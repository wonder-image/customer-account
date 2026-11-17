<?php

namespace Wonder\Plugin\CustomerAccount\Support\Import;

use RuntimeException;
use Throwable;
use Wonder\App\Dataset\Column;
use Wonder\App\Import\Change;
use Wonder\App\Import\Context;
use Wonder\App\Import\Layout;
use Wonder\Auth\Frontend\AccountAddressValidation;
use Wonder\Plugin\CustomerAccount\Models\{CompanyAddress, ContactAddress};
use Wonder\Plugin\CustomerAccount\Resources\{CompanyResource, ContactAddressResource, ContactResource};

/**
 * Gli indirizzi di consegna di persone e aziende. Una riga è un indirizzo di
 * una scheda che esiste già (riconosciuta come nei tracciati delle schede):
 * l'import non crea schede. Con l'ID dell'indirizzo si aggiorna quello;
 * senza, si aggiorna l'indirizzo uguale (via, civico, CAP, città, paese) o se
 * ne aggiunge uno. «Predefinito» a sì toglie il predefinito agli altri
 * indirizzi della scheda. Nessun evento della rubrica, come dal form.
 */
class AddressLayout extends Layout
{
    use ReadsImportCells;

    public const KEY = 'contacts_addresses';

    /** I campi dell'indirizzo nel file, nell'ordine dell'export. */
    private const FIELDS = ['label', 'business_name', 'name', 'surname', 'phone', 'phone_prefix', 'street', 'number', 'more', 'cap', 'city', 'province', 'country'];

    /** I campi che dicono se due indirizzi sono lo stesso. */
    private const SAME = ['street', 'number', 'cap', 'city', 'country'];

    private const MAX = ['label' => 100, 'business_name' => 150];

    /** @var list<ImportExtension>|null */
    private ?array $extensions;

    private ?PeopleLayout $people = null;
    private ?CompanyLayout $companies = null;

    /** @param list<ImportExtension>|null $extensions per i test; null = quelle registrate */
    public function __construct(?array $extensions = null)
    {
        $this->extensions = $extensions;
    }

    public static function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->text('addresses');
    }

    /** @return list<ImportExtension> */
    private function extensions(): array
    {
        return $this->extensions ??= ImportExtensions::all();
    }

    public function permits(object $user): bool
    {
        return ImportExtensions::permits(
            $this->extensions(),
            self::KEY,
            $user,
            ContactAddressResource::allows((array) ($user->authority ?? []), 'update'),
        );
    }

    public function backUrl(): ?string
    {
        return ImportExtensions::backUrl($this->extensions(), self::KEY, static fn (): string => ImportExtensions::listUrl(ContactAddressResource::class));
    }

    public function message(Throwable $exception): ?string
    {
        return $exception instanceof PartyImportException ? $exception->getMessage() : null;
    }

    /* ----------------------------------------------------------------- colonne */

    /** @return array<string, string> */
    private function labels(): array
    {
        return ContactAddressResource::labelSchema();
    }

    /** I campi dell'indirizzo che il Model ha davvero. */
    private function fields(): array
    {
        $labels = $this->labels();

        return array_values(array_filter(self::FIELDS, static fn (string $field): bool => isset($labels[$field])));
    }

    public function columns(): array
    {
        $labels = $this->labels();
        $columns = [
            Column::make('card_type', $this->text('columns.card_type'), ['Tipo', 'Tipo scheda'])->field($this->text('fields.card_type')),
            Column::make('card_id', $this->text('columns.card_id'), ['ID scheda', 'Id scheda'])->field($this->text('fields.card_id')),
            Column::make('card_code', $this->text('columns.card_code'), ['Codice scheda', 'Codice cliente'])->field($this->text('fields.card_code')),
            Column::make('pi', (string) (CompanyResource::labelSchema()['pi'] ?? 'P.IVA'), ['Partita IVA', 'P.IVA', 'PIVA', 'VAT'])->field($this->text('fields.tax')),
            Column::make('cf', (string) (ContactResource::labelSchema()['cf'] ?? 'CF'), ['Codice fiscale', 'CF'])->field($this->text('fields.tax')),
            Column::make('card_email', $this->text('columns.card_email'), ['Email scheda', 'Email cliente'])->field($this->text('fields.card_email')),
            Column::make('address_id', $this->text('columns.address_id'), ['ID indirizzo', 'Id indirizzo'])->field($this->text('fields.address_id')),
        ];

        foreach ($this->fields() as $field) {
            $header = (string) ($labels[$field] ?? $this->text('columns.'.$field));
            $columns[] = Column::make($field, $header)->field(match ($field) {
                'phone' => $this->text('fields.phone'),
                'phone_prefix' => $this->text('fields.phone_prefix'),
                'country' => $this->text('fields.country'),
                'province' => $this->text('fields.province'),
                default => '',
            });
        }

        $columns[] = Column::make('is_default', $this->text('columns.is_default'), ['Predefinito', 'Default'])->field($this->text('fields.flag'));

        foreach ($this->extensions() as $extension) {
            foreach ($extension->columns(self::KEY) as $column) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    public function sampleColumns(): array
    {
        return array_values(array_filter($this->columns(), static fn (Column $column): bool => $column->key !== 'phone_prefix'));
    }

    /* --------------------------------------------------------------- controllo */

    public function validate(array $rows, Context $context): array
    {
        $results = [];
        $seen = [];

        foreach ($rows as $row) {
            $results[] = $this->validateRow((int) $row['line'], (array) $row['data'], $context, $seen);
        }

        return $results;
    }

    /** @param array<string, int> $seen indirizzo ⇒ riga del file che l'ha già usato */
    private function validateRow(int $line, array $data, Context $context, array &$seen): array
    {
        $errors = [];
        $warnings = [];
        [$type, $card] = $this->card($data, $context, $errors);

        if ($card === null) {
            return ['action' => 'create', 'errors' => $errors, 'warnings' => []];
        }

        $model = $type === 'company' ? CompanyAddress::class : ContactAddress::class;
        $parentKey = $type === 'company' ? 'company_id' : 'contact_id';
        $addresses = $this->addresses($model, $parentKey, (int) $card['id']);
        $current = null;
        $addressId = trim((string) ($data['address_id'] ?? ''));

        if ($addressId !== '') {
            $current = ctype_digit($addressId) ? ($addresses[(int) $addressId] ?? null) : null;

            if ($current === null) {
                return ['action' => 'update', 'errors' => [$this->text('errors.address_not_found', ['id' => $addressId])], 'warnings' => []];
            }
        }

        $values = $this->cellValues($data, $current, $errors);

        foreach ($this->extensions() as $extension) {
            $extra = $extension->values(self::KEY, $data, $current, $context);
            $values = [...$values, ...array_map(static fn ($value) => $value === null ? null : (string) $value, (array) ($extra['values'] ?? []))];
            array_push($errors, ...array_map('strval', (array) ($extra['errors'] ?? [])));
            array_push($warnings, ...array_map('strval', (array) ($extra['warnings'] ?? [])));
        }

        // Senza ID: l'indirizzo uguale della scheda, se c'è, si aggiorna.
        if ($current === null) {
            $current = $this->sameAddress($addresses, $values);
        }

        $merged = [...($current ?? ['country' => 'IT']), ...$values];

        // Doppioni nel file: lo stesso indirizzo (per ID o per contenuto) due volte.
        $key = $type.'#'.$card['id'].':'.($current !== null ? 'id'.$current['id'] : $this->sameKey($merged));
        if (isset($seen[$key])) {
            $errors[] = $this->text('errors.duplicate_address', ['line' => $seen[$key]]);
        }
        $seen[$key] ??= $line;

        if ($errors === []) {
            array_push($errors, ...AccountAddressValidation::validate($model::address(), $merged));
        }

        if ($current !== null) {
            $values = array_filter(
                $values,
                static fn ($value, string $field): bool => (string) ($current[$field] ?? '') !== (string) ($value ?? ''),
                ARRAY_FILTER_USE_BOTH,
            );
        } elseif (!isset($values['country'])) {
            $values['country'] = 'IT';
        }

        $action = $current === null ? 'create' : ($values === [] ? 'unchanged' : 'update');

        return [
            'action' => $action,
            'errors' => array_values(array_unique($errors)),
            'warnings' => $warnings,
            'data' => [
                '_type' => $type,
                '_card' => (string) $card['id'],
                '_id' => (string) ($current['id'] ?? ''),
                '_values' => (string) json_encode($values, JSON_UNESCAPED_UNICODE),
            ],
        ];
    }

    /**
     * Il tipo e la scheda della riga; null con un errore se non si trova.
     *
     * @param list<string> $errors
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    private function card(array $data, Context $context, array &$errors): array
    {
        $cell = trim((string) ($data['card_type'] ?? ''));
        $pi = trim((string) ($data['pi'] ?? ''));
        $person = trim((string) ($data['cf'] ?? '')) !== '' || trim((string) ($data['card_email'] ?? '')) !== '';

        if ($cell !== '') {
            $type = $this->cardType($cell);

            if ($type === null) {
                $errors[] = $this->text('errors.card_type', ['value' => $cell]);

                return ['', null];
            }
        } elseif ($pi !== '') {
            $type = 'company';
        } elseif ($person) {
            $type = 'person';
        } else {
            $errors[] = $this->text('errors.card_missing');

            return ['', null];
        }

        $ids = ['id' => (string) ($data['card_id'] ?? ''), 'code' => (string) ($data['card_code'] ?? '')];

        if ($type === 'company') {
            $layout = $this->companies ??= new CompanyLayout($this->extensions());
            $ids['pi'] = $pi;
        } else {
            $layout = $this->people ??= new PeopleLayout($this->extensions());
            $ids += ['cf' => (string) ($data['cf'] ?? ''), 'email' => (string) ($data['card_email'] ?? '')];
        }

        if (implode('', array_map('trim', $ids)) === '') {
            $errors[] = $this->text('errors.card_missing');

            return [$type, null];
        }

        $card = $layout->findExisting($ids, $context, $errors);

        if ($card === null && $errors === []) {
            $errors[] = $this->text('errors.card_not_found');
        }

        return [$type, $card];
    }

    /** `person` o `company` dalla cella (codice o nome, in italiano o inglese); null se non si capisce. */
    private function cardType(string $cell): ?string
    {
        foreach (['company' => ['azienda', (string) __t('contacts.company')], 'person' => ['persona', (string) __t('contacts.person')]] as $type => $names) {
            if ($this->lookup($cell, [$type => $names[1]]) !== null || $this->plain($cell) === $names[0]) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Gli indirizzi vivi di una scheda, per ID, con i testi decodificati.
     *
     * @param class-string $model
     * @return array<int, array<string, mixed>>
     */
    private function addresses(string $model, string $parentKey, int $cardId): array
    {
        $result = $model::query()->Select($model::$table, [$parentKey => $cardId, 'deleted' => 'false']);
        $rows = $result->row ?? [];
        $rows = is_array($rows) && isset($rows['id']) ? [$rows] : (array) $rows;
        $out = [];

        foreach ($rows as $row) {
            if (is_array($row) && isset($row['id'])) {
                $out[(int) $row['id']] = $this->decoded($row);
            }
        }

        return $out;
    }

    /** L'indirizzo della scheda uguale a quello della riga (stessi campi di `SAME`); null se non c'è. */
    private function sameAddress(array $addresses, array $values): ?array
    {
        if (trim((string) ($values['street'] ?? '')) === '') {
            return null;
        }

        $key = $this->sameKey($values + ['country' => 'IT']);

        foreach ($addresses as $address) {
            if ($this->sameKey($address) === $key) {
                return $address;
            }
        }

        return null;
    }

    private function sameKey(array $values): string
    {
        return implode('|', array_map(fn (string $field): string => $this->plain((string) ($values[$field] ?? '')), self::SAME));
    }

    /**
     * Le celle lette e ripulite; quelle vuote non ci sono.
     *
     * @param list<string> $errors
     * @return array<string, string>
     */
    private function cellValues(array $data, ?array $current, array &$errors): array
    {
        $labels = $this->labels();
        $values = [];

        foreach ($this->fields() as $field) {
            $value = trim((string) ($data[$field] ?? ''));

            if ($value === '' || in_array($field, ['phone', 'phone_prefix', 'country', 'province'], true)) {
                continue;
            }

            if (isset(self::MAX[$field]) && mb_strlen($value) > self::MAX[$field]) {
                $errors[] = $this->text('errors.invalid_value', ['field' => (string) ($labels[$field] ?? $field), 'value' => $value]);
                continue;
            }

            $values[$field] = $value;
        }

        $countryCell = trim((string) ($data['country'] ?? ''));
        if ($countryCell !== '') {
            $country = $this->countryCode($countryCell);

            if ($country === null) {
                $errors[] = $this->text('errors.invalid_value', ['field' => (string) ($labels['country'] ?? 'country'), 'value' => $countryCell]);
            } else {
                $values['country'] = $country;
            }
        }

        $country = $values['country'] ?? (string) ($current['country'] ?? 'IT');
        $provinceCell = trim((string) ($data['province'] ?? ''));
        if ($provinceCell !== '') {
            $province = $this->provinceCode($provinceCell, $country);

            if ($province === null) {
                $errors[] = $this->text('errors.invalid_value', ['field' => (string) ($labels['province'] ?? 'province'), 'value' => $provinceCell]);
            } else {
                $values['province'] = $province;
            }
        }

        if (isset($labels['phone'])) {
            $values = [...$values, ...$this->phoneCells(
                (string) ($data['phone'] ?? ''),
                (string) ($data['phone_prefix'] ?? ''),
                $current,
                $country,
                (string) $labels['phone'],
                (string) ($labels['phone_prefix'] ?? $this->text('columns.phone_prefix')),
                $errors,
            )];
        }

        $default = trim((string) ($data['is_default'] ?? ''));
        if ($default !== '') {
            $flag = $this->flag($default);

            if ($flag === null) {
                $errors[] = $this->text('errors.invalid_flag', ['field' => $this->text('columns.is_default'), 'value' => $default]);
            } else {
                $values['is_default'] = $flag;
            }
        }

        return $values;
    }

    /* --------------------------------------------------------------- scrittura */

    public function apply(array $data, Context $context): array
    {
        $values = json_decode((string) ($data['_values'] ?? ''), true);
        $values = is_array($values) ? $values : [];
        $company = ($data['_type'] ?? '') === 'company';
        $model = $company ? CompanyAddress::class : ContactAddress::class;
        $parentKey = $company ? 'company_id' : 'contact_id';
        $target = $company ? 'company_address' : 'contact_address';
        $cardId = (int) ($data['_card'] ?? 0);
        $id = (int) ($data['_id'] ?? 0);
        $changes = [];

        if ($id > 0) {
            $raw = $this->rawRow($model, $id);

            if ($raw === null || (int) ($raw[$parentKey] ?? 0) !== $cardId) {
                throw new PartyImportException($this->text('errors.address_not_found', ['id' => (string) $id]));
            }

            // L'indirizzo può essere cambiato dal controllo: si scrive solo la differenza di adesso.
            $stored = $this->decoded($raw);
            $values = array_filter($values, static fn ($value, string $field): bool => (string) ($stored[$field] ?? '') !== (string) ($value ?? ''), ARRAY_FILTER_USE_BOTH);

            if ($values === []) {
                return ['action' => 'unchanged', 'changes' => []];
            }

            // Un indirizzo spostato perde il punto sulla mappa.
            if (array_intersect_key($values, array_flip([...self::SAME, 'province'])) !== []) {
                foreach (['place_id', 'lat', 'lng'] as $geo) {
                    if (array_key_exists($geo, $raw) && $raw[$geo] !== null && $raw[$geo] !== '') {
                        $values[$geo] = null;
                    }
                }
            }

            $result = $model::query()->Update($model::$table, $values, 'id', $id);

            if (($result->success ?? false) !== true) {
                throw new PartyImportException($this->text('errors.save_failed'));
            }

            $changes[] = new Change($target, $id, 'updated', array_intersect_key($raw, $values));
            $action = 'update';
        } else {
            $max = $model::query()->Select($model::$table, [$parentKey => $cardId], 1, null, null, 'MAX(position) AS pos')->row ?? [];
            $result = $model::query()->Insert($model::$table, $values + [
                $parentKey => $cardId,
                'is_default' => 'false',
                'position' => (int) ($max['pos'] ?? 0) + 1,
            ]);
            $id = (int) ($result->insert_id ?? 0);

            if (($result->success ?? false) !== true || $id <= 0) {
                throw new PartyImportException($this->text('errors.save_failed'));
            }

            $changes[] = new Change($target, $id, 'created');
            $action = 'create';
        }

        // Un solo predefinito per scheda: gli altri lo perdono, ognuno con il suo annullo.
        if (($values['is_default'] ?? null) === 'true') {
            foreach ($this->addresses($model, $parentKey, $cardId) as $otherId => $other) {
                if ($otherId !== $id && ($other['is_default'] ?? 'false') === 'true') {
                    $model::query()->Update($model::$table, ['is_default' => 'false'], 'id', $otherId);
                    $changes[] = new Change($target, $otherId, 'updated', ['is_default' => 'true']);
                }
            }
        }

        return ['action' => $action, 'changes' => $changes];
    }

    /** La riga com'è salvata, senza decorazioni. */
    private function rawRow(string $model, int $id): ?array
    {
        $row = $model::query()->Select($model::$table, ['id' => $id, 'deleted' => 'false'], 1)->row ?? null;

        return is_array($row) && isset($row['id']) ? $row : null;
    }

    public function undo(Change $change, Context $context): void
    {
        $model = $change->target === 'company_address' ? CompanyAddress::class : ContactAddress::class;
        $values = $change->action === 'created' ? ['deleted' => 'true'] : $change->oldValues;

        if ($values === [] || $change->recordId <= 0) {
            return;
        }

        $result = $model::query()->Update($model::$table, $values, 'id', $change->recordId);

        if (($result->success ?? false) !== true) {
            throw new RuntimeException('Annullo non riuscito per l\'indirizzo '.$change->recordId.'.');
        }
    }
}
