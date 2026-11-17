<?php

namespace Wonder\Plugin\CustomerAccount\Support\Import;

use RuntimeException;
use Throwable;
use Wonder\App\Dataset\Column;
use Wonder\App\Import\Change;
use Wonder\App\Import\Context;
use Wonder\App\Import\ImportError;
use Wonder\App\Import\Layout;
use Wonder\Plugin\CustomerAccount\Support\ContactEvents;
use Wonder\Plugin\CustomerAccount\Support\ContactPoints;

/**
 * Il caricamento di schede della rubrica (persone o aziende), speculare
 * all'export della Resource: stesse intestazioni, più codice e flag.
 *
 * Una riga trova la sua scheda per ID, poi codice, poi gli identificativi del
 * tracciato (`identifiers()`); se la trova la aggiorna, altrimenti la crea.
 * Un dato che porta a un'altra scheda ferma la riga. Le celle vuote e le
 * colonne non abbinate non cambiano niente di quello che è salvato.
 */
abstract class PartyLayout extends Layout
{
    use ReadsImportCells;


    /** @var list<ImportExtension>|null */
    private ?array $extensions = null;

    /** @return class-string<\Wonder\Plugin\CustomerAccount\Resources\ContactResource> */
    abstract protected function resource(): string;

    /** @return class-string il Model base del tracciato */
    abstract protected function baseModel(): string;

    /** `ContactPoints::CONTACT` o `ContactPoints::COMPANY`. */
    abstract protected function owner(): string;

    /** Il `target` delle modifiche (`contact`, `company`). */
    abstract protected function target(): string;

    /** I flag `true`/`false` del tracciato, oltre ad `active`. */
    protected function flags(): array
    {
        return [];
    }

    /** I campi che riconoscono la scheda dopo ID e codice, nell'ordine in cui si provano. */
    abstract protected function identifiers(): array;

    /** @param list<ImportExtension>|null $extensions per i test; null = quelle registrate */
    public function __construct(?array $extensions = null)
    {
        $this->extensions = $extensions;
    }

    /** @return list<ImportExtension> */
    protected function extensions(): array
    {
        return $this->extensions ??= ImportExtensions::all();
    }

    /** @return class-string */
    protected function model(): string
    {
        return ImportExtensions::model($this->extensions(), static::key(), $this->baseModel());
    }

    public function permits(object $user): bool
    {
        $resource = $this->resource();

        return ImportExtensions::permits(
            $this->extensions(),
            static::key(),
            $user,
            $resource::allows((array) ($user->authority ?? []), 'update'),
        );
    }

    public function backUrl(): ?string
    {
        return ImportExtensions::backUrl($this->extensions(), static::key(), fn (): string => ImportExtensions::listUrl($this->resource()));
    }

    public function message(Throwable $exception): ?string
    {
        return $exception instanceof PartyImportException ? $exception->getMessage() : null;
    }

    /* ----------------------------------------------------------------- colonne */

    /** @return array<string, string> le etichette della Resource */
    protected function labels(): array
    {
        return $this->resource()::labelSchema();
    }

    /** I campi del file, come l'export (più il prefisso, dopo il telefono). */
    protected function fields(): array
    {
        $fields = [];

        foreach ($this->resource()::exportFields() as $field) {
            $fields[] = $field;

            if ($field === 'phone') {
                $fields[] = 'phone_prefix';
            }
        }

        return array_values(array_filter($fields, fn (string $field): bool => isset($this->labels()[$field]) || $field === 'phone_prefix'));
    }

    public function columns(): array
    {
        $labels = $this->labels();
        $columns = [
            Column::make('id', 'ID', ['Id scheda', 'ID scheda'])->field($this->text('fields.id')),
            Column::make('code', $this->text('columns.code'), ['Codice', 'Codice cliente', 'Code'])->field($this->text('fields.code')),
        ];

        foreach ($this->fields() as $field) {
            $header = (string) ($labels[$field] ?? $this->text('columns.'.$field));
            $columns[] = Column::make($field, $header, self::ALIASES[$field] ?? [])->field($this->describe($field));
        }

        foreach (['active', ...$this->flags()] as $flag) {
            $header = (string) ($labels[$flag] ?? __t('contacts.'.$flag));
            $columns[] = Column::make($flag, $header, self::ALIASES[$flag] ?? [])->field($this->text('fields.flag'));
        }

        foreach ($this->extensions() as $extension) {
            foreach ($extension->columns(static::key()) as $column) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /** Alias in italiano e inglese, solo per abbinare le intestazioni di altri file. */
    private const ALIASES = [
        'name' => ['Nome', 'First name'],
        'surname' => ['Cognome', 'Last name'],
        'business_name' => ['Ragione sociale', 'Azienda', 'Società', 'Company'],
        'cf' => ['Codice fiscale', 'CF', 'Tax code'],
        'pi' => ['Partita IVA', 'P.IVA', 'P IVA', 'PIVA', 'VAT', 'VAT number'],
        'sdi' => ['Codice destinatario', 'SDI', 'Codice SDI'],
        'pec' => ['PEC'],
        'email' => ['Email', 'E-mail', 'Mail'],
        'phone' => ['Telefono', 'Cellulare', 'Phone'],
        'phone_prefix' => ['Prefisso', 'Prefisso telefono'],
        'street' => ['Indirizzo', 'Via', 'Address'],
        'number' => ['Civico', 'Numero civico'],
        'more' => ['Interno', 'Scala', 'Altro'],
        'cap' => ['CAP', 'Codice postale', 'Zip', 'Postal code'],
        'city' => ['Città', 'Comune', 'City'],
        'province' => ['Provincia', 'Prov', 'State'],
        'country' => ['Paese', 'Nazione', 'Stato estero', 'Country'],
        'note' => ['Note', 'Notes'],
        'active' => ['Stato', 'Attiva', 'Attivo'],
        'is_customer' => ['Cliente'],
        'is_supplier' => ['Fornitore'],
    ];

    private function describe(string $field): string
    {
        return match ($field) {
            'email' => $this->text('fields.email'),
            'phone' => $this->text('fields.phone'),
            'phone_prefix' => $this->text('fields.phone_prefix'),
            'country' => $this->text('fields.country'),
            'province' => $this->text('fields.province'),
            'cf', 'pi' => $this->text('fields.tax'),
            default => '',
        };
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

    /**
     * @param array<string, string> $data
     * @param array<string, int> $seen scheda o identificativo ⇒ riga del file che l'ha già usato
     */
    private function validateRow(int $line, array $data, Context $context, array &$seen): array
    {
        $errors = [];
        $warnings = [];
        [$card, $found] = $this->findCard($data, $context, $errors);

        if ($errors !== []) {
            return ['action' => 'create', 'errors' => $errors, 'warnings' => []];
        }

        $current = $card !== null ? $this->decoded($card) : null;
        $values = $this->cellValues($data, $current, $errors);

        foreach ($this->extensions() as $extension) {
            $extra = $extension->values(static::key(), $data, $current, $context);
            $values = [...$values, ...array_map(static fn ($value) => $value === null ? null : (string) $value, (array) ($extra['values'] ?? []))];
            array_push($errors, ...array_map('strval', (array) ($extra['errors'] ?? [])));
            array_push($warnings, ...array_map('strval', (array) ($extra['warnings'] ?? [])));
        }

        // Doppioni nel file: la stessa scheda, o lo stesso dato per due schede nuove.
        $keys = $card !== null ? ['#'.$card['id']] : [];
        foreach ($found as $field => $value) {
            $keys[] = $field.':'.$value;
        }
        foreach ($keys as $key) {
            if (isset($seen[$key])) {
                $errors[] = $this->text('errors.duplicate', ['line' => $seen[$key]]);
                break;
            }
        }
        foreach ($keys as $key) {
            $seen[$key] ??= $line;
        }

        if ($current === null) {
            $values = $this->creationValues($values, $data, $context, $errors);
        } else {
            $values = $this->changedValues($values, $current, $warnings);
        }

        if ($errors === []) {
            array_push($errors, ...$this->modelErrors($values, $current));
        }

        $action = $current === null ? 'create' : ($values === [] ? 'unchanged' : 'update');

        return [
            'action' => $action,
            'errors' => array_values(array_unique($errors)),
            'warnings' => $warnings,
            'data' => [
                '_id' => (string) ($card['id'] ?? ''),
                '_values' => (string) json_encode($values, JSON_UNESCAPED_UNICODE),
            ],
        ];
    }

    /**
     * La scheda viva e visibile con questi identificativi (`id`, `code` e
     * quelli del tracciato), per gli altri tracciati che vi si appoggiano
     * (indirizzi, referenti); null se non c'è o se gli identificativi sono vuoti.
     *
     * @param array<string, string> $ids
     * @param list<string> $errors
     */
    public function findExisting(array $ids, Context $context, array &$errors): ?array
    {
        $own = [];
        [$card] = $this->findCard($ids, $context, $own);
        array_push($errors, ...$own);

        return $own === [] && $card !== null ? $this->decoded($card) : null;
    }

    /** Il nome della scheda come lo mostra la Resource. */
    public function cardName(array $card): string
    {
        return (string) $this->resource()::displayName($card);
    }

    /**
     * La scheda della riga, o null se la riga ne crea una. Gli errori dicono
     * perché la riga non si può leggere (ID sconosciuto, dato di un'altra
     * scheda, più schede con lo stesso dato).
     *
     * @param list<string> $errors
     * @return array{0: array<string, mixed>|null, 1: array<string, string>} la scheda e gli identificativi letti
     */
    private function findCard(array $data, Context $context, array &$errors): array
    {
        $index = $this->index($context);
        $labels = $this->labels();
        $card = null;
        $found = [];

        $id = trim((string) ($data['id'] ?? ''));
        if ($id !== '') {
            $row = ctype_digit($id) ? ($index['rows'][(int) $id] ?? null) : null;

            if ($row === null || !$this->visible($row)) {
                $errors[] = $this->text('errors.not_found', ['id' => $id]);

                return [null, []];
            }

            $card = $row;
        }

        foreach (['code', ...$this->identifiers()] as $field) {
            $value = $this->identifier($field, (string) ($data[$field] ?? ''));

            if ($value === '') {
                continue;
            }

            $found[$field] = $value;
            $ids = $index['by'][$field][$value] ?? [];
            $label = $field === 'code' ? $this->text('columns.code') : (string) ($labels[$field] ?? $field);

            if ($card !== null) {
                $others = array_values(array_diff($ids, [(int) $card['id']]));

                if ($others !== []) {
                    $errors[] = $this->usedBy($label, (string) ($data[$field] ?? ''), $index['rows'][$others[0]] ?? null);
                }

                continue;
            }

            if (count($ids) > 1) {
                $errors[] = $this->text('errors.ambiguous', ['field' => $label, 'value' => (string) ($data[$field] ?? '')]);
                continue;
            }

            if ($ids !== []) {
                $row = $index['rows'][$ids[0]] ?? null;

                if ($row === null || !$this->visible($row)) {
                    $errors[] = $this->usedBy($label, (string) ($data[$field] ?? ''), null);
                    continue;
                }

                $card = $row;
            }
        }

        return [$errors === [] ? $card : null, $found];
    }

    private function usedBy(string $label, string $value, ?array $row): string
    {
        if ($row === null || !$this->visible($row)) {
            return $this->text('errors.used_hidden', ['field' => $label, 'value' => $value]);
        }

        return $this->text('errors.used_by', ['field' => $label, 'value' => $value, 'name' => $this->resource()::displayName($this->decoded($row))]);
    }

    private function visible(array $row): bool
    {
        foreach ($this->extensions() as $extension) {
            if (!$extension->visible(static::key(), $row)) {
                return false;
            }
        }

        return true;
    }

    /** Un identificativo come si confronta: codice ed email in minuscolo, CF e P.IVA in maiuscolo senza spazi. */
    private function identifier(string $field, string $value): string
    {
        $value = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return match ($field) {
            'code', 'email' => strtolower($value),
            'cf', 'pi' => strtoupper((string) preg_replace('/\s+/', '', $value)),
            default => $value,
        };
    }

    /**
     * Le schede della tabella per ID e, per ogni identificativo, valore ⇒ ID;
     * letta una volta per caricamento e rifatta dopo ogni scrittura.
     *
     * @return array{rows: array<int, array<string, mixed>>, by: array<string, array<string, list<int>>>}
     */
    private function index(Context $context): array
    {
        return $context->remember($this->cacheKey(), function (): array {
            $model = $this->model();
            $result = $model::query()->Select($model::$table, ['deleted' => 'false']);
            $rows = $result->row ?? [];
            $rows = is_array($rows) && isset($rows['id']) ? [$rows] : (array) $rows;
            $index = ['rows' => [], 'by' => []];

            foreach ($rows as $row) {
                if (!is_array($row) || !isset($row['id'])) {
                    continue;
                }

                $id = (int) $row['id'];
                $index['rows'][$id] = $row;

                foreach (['code', ...$this->identifiers()] as $field) {
                    $value = $this->identifier($field, (string) ($row[$field] ?? ''));

                    if ($value !== '') {
                        $index['by'][$field][$value][] = $id;
                    }
                }
            }

            return $index;
        });
    }

    private function cacheKey(): string
    {
        return 'contacts_import.'.static::key();
    }

    /**
     * Le celle lette e ripulite, per campo; quelle vuote non ci sono.
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

            if ($value !== '' && !in_array($field, ['phone', 'phone_prefix', 'country', 'province'], true)) {
                $values[$field] = match ($field) {
                    'email', 'pec' => strtolower($value),
                    'cf', 'pi' => strtoupper((string) preg_replace('/\s+/', '', $value)),
                    'sdi' => strtoupper($value),
                    default => $value,
                };
            }
        }

        foreach (['email', 'pec'] as $field) {
            if (isset($values[$field]) && !ContactPoints::emailFormat($values[$field])) {
                $errors[] = $this->text('errors.invalid_email', ['field' => (string) ($labels[$field] ?? $field), 'value' => $values[$field]]);
                unset($values[$field]);
            }
        }

        $values = [...$values, ...$this->addressValues($data, $current, $errors)];
        $values = [...$values, ...$this->phoneValues($data, $current, $values['country'] ?? null, $errors)];

        foreach (['active', ...$this->flags()] as $flag) {
            $value = trim((string) ($data[$flag] ?? ''));

            if ($value === '') {
                continue;
            }

            $flagValue = $this->flag($value);

            if ($flagValue === null) {
                $errors[] = $this->text('errors.invalid_flag', ['field' => (string) ($labels[$flag] ?? __t('contacts.'.$flag)), 'value' => $value]);
            } else {
                $values[$flag] = $flagValue;
            }
        }

        return $values;
    }

    /**
     * Paese e provincia come codici, dal codice o dal nome.
     *
     * @param list<string> $errors
     * @return array<string, string>
     */
    private function addressValues(array $data, ?array $current, array &$errors): array
    {
        $labels = $this->labels();
        $values = [];
        $countryCell = trim((string) ($data['country'] ?? ''));

        if ($countryCell !== '' && in_array('country', $this->fields(), true)) {
            $country = $this->countryCode($countryCell);

            if ($country === null) {
                $errors[] = $this->text('errors.invalid_value', ['field' => (string) ($labels['country'] ?? 'country'), 'value' => $countryCell]);
            } else {
                $values['country'] = $country;
            }
        }

        $provinceCell = trim((string) ($data['province'] ?? ''));

        if ($provinceCell !== '' && in_array('province', $this->fields(), true)) {
            $province = $this->provinceCode($provinceCell, $values['country'] ?? (string) ($current['country'] ?? ''));

            if ($province === null) {
                $errors[] = $this->text('errors.invalid_value', ['field' => (string) ($labels['province'] ?? 'province'), 'value' => $provinceCell]);
            } else {
                $values['province'] = $province;
            }
        }

        return $values;
    }

    /**
     * @param list<string> $errors
     * @return array<string, string>
     */
    private function phoneValues(array $data, ?array $current, ?string $country, array &$errors): array
    {
        $labels = $this->labels();

        return $this->phoneCells(
            (string) ($data['phone'] ?? ''),
            (string) ($data['phone_prefix'] ?? ''),
            $current,
            $country,
            (string) ($labels['phone'] ?? 'phone'),
            (string) ($labels['phone_prefix'] ?? $this->text('columns.phone_prefix')),
            $errors,
        );
    }

    /**
     * Una scheda nuova: i campi obbligatori, il paese di default e il codice
     * dato se è libero.
     *
     * @param list<string> $errors
     */
    private function creationValues(array $values, array $data, Context $context, array &$errors): array
    {
        $labels = $this->labels();

        foreach ($this->resource()::requiredKeys() as $key) {
            if (trim((string) ($values[$key] ?? '')) === '') {
                $errors[] = $this->text('errors.required', ['field' => (string) ($labels[$key] ?? $key)]);
            }
        }

        $code = trim((string) ($data['code'] ?? ''));
        if ($code !== '') {
            $values['code'] = $code;
        }

        if (!isset($values['country']) && $this->defaultCountry() !== null) {
            $values['country'] = $this->defaultCountry();
        }

        return $values;
    }

    /** Il paese di una scheda nuova senza paese (null = resta vuoto). */
    protected function defaultCountry(): ?string
    {
        return null;
    }

    /**
     * Solo i valori che cambiano; il codice non cambia mai e l'email di chi
     * ha un account nemmeno (avviso).
     *
     * @param list<string> $warnings
     */
    private function changedValues(array $values, array $current, array &$warnings): array
    {
        unset($values['code']);

        if (isset($values['email']) && ($locked = ContactPoints::lockedEmail($this->owner(), $current)) !== null && $values['email'] !== $locked) {
            $warnings[] = $this->text('warnings.email_locked');
            unset($values['email']);
        }

        return array_filter(
            $values,
            static fn ($value, string $key): bool => (string) ($current[$key] ?? '') !== (string) ($value ?? ''),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Le regole del Model sui valori che si scrivono (con il paese, se
     * cambiano CF o P.IVA), come «Etichetta: messaggio».
     *
     * @return list<string>
     */
    private function modelErrors(array $values, ?array $current): array
    {
        if ($values === []) {
            return [];
        }

        $model = $this->model();
        $check = $this->withRequired($values, $current);

        if ($current !== null && (isset($values['cf']) || isset($values['pi'])) && !isset($check['country'])) {
            $check['country'] = (string) ($current['country'] ?? '');
        }

        $labels = $this->labels();
        $errors = [];

        foreach ((array) ($model::validate($check)->response ?? []) as $key => $result) {
            if (is_object($result) && method_exists($result, 'isValid') && !$result->isValid()) {
                $errors[] = $this->text('errors.field', [
                    'field' => (string) ($labels[$key] ?? ($key === 'code' ? $this->text('columns.code') : $key)),
                    'message' => (string) ($result->message ?? $this->text('errors.invalid')),
                ]);
            }
        }

        return $errors;
    }

    /** I campi obbligatori del Model presi dalla scheda, se la riga non li cambia. */
    private function withRequired(array $values, ?array $current): array
    {
        if ($current === null) {
            return $values;
        }

        foreach ($this->model()::dataSchema() as $field) {
            if (isset($field->key) && $field->isRequired() && !array_key_exists($field->key, $values) && array_key_exists($field->key, $current)) {
                $values[$field->key] = $current[$field->key];
            }
        }

        return $values;
    }

    /* --------------------------------------------------------------- scrittura */

    public function apply(array $data, Context $context): array
    {
        $values = json_decode((string) ($data['_values'] ?? ''), true);
        $values = is_array($values) ? $values : [];
        $id = (int) ($data['_id'] ?? 0);
        $model = $this->model();

        if ($id > 0) {
            $card = $model::findById($id);

            if (!is_array($card) || !isset($card['id'])) {
                throw new PartyImportException($this->text('errors.not_found', ['id' => (string) $id]));
            }

            // La scheda può essere cambiata dal controllo: si scrive solo la differenza di adesso.
            $raw = $this->rawRow($model, $id) ?? $card;
            $values = array_filter(
                $this->withRequired($values, $this->decoded($raw)),
                fn ($value, string $key): bool => array_key_exists($key, $values) && (string) ($this->decoded($raw)[$key] ?? '') !== (string) ($value ?? ''),
                ARRAY_FILTER_USE_BOTH,
            );

            if ($values === []) {
                return ['action' => 'unchanged', 'changes' => []];
            }

            $result = $model::update($this->withRequired($values, $this->decoded($raw)), $id);

            if (($result->success ?? false) !== true) {
                throw new PartyImportException($this->failure($result));
            }

            $old = array_intersect_key($raw, $values);
            if ((isset($values['email']) || isset($values['phone']) || isset($values['phone_prefix'])) && array_key_exists('phone_kind', $raw)) {
                $old['phone_kind'] = $raw['phone_kind'];
            }

            $this->afterWrite($id, 'updated', $context);

            return ['action' => 'update', 'changes' => [new Change($this->target(), $id, 'updated', $old)]];
        }

        $result = $model::create($values);
        $newId = (int) ($result->insert_id ?? 0);

        if (($result->success ?? false) !== true || $newId <= 0) {
            throw new PartyImportException($this->failure($result));
        }

        $this->afterWrite($newId, 'created', $context);

        return ['action' => 'create', 'changes' => [new Change($this->target(), $newId, 'created')]];
    }

    /** La riga com'è salvata, senza decorazioni. */
    private function rawRow(string $model, int $id): ?array
    {
        $result = $model::query()->Select($model::$table, ['id' => $id, 'deleted' => 'false'], 1);
        $row = $result->row ?? null;

        return is_array($row) && isset($row['id']) ? $row : null;
    }

    private function failure(object $result): string
    {
        foreach ((array) ($result->response ?? []) as $key => $item) {
            if (is_object($item) && method_exists($item, 'isValid') && !$item->isValid()) {
                return $this->text('errors.field', [
                    'field' => (string) ($this->labels()[$key] ?? $key),
                    'message' => (string) ($item->message ?? $this->text('errors.invalid')),
                ]);
            }
        }

        return $this->text('errors.save_failed');
    }

    /** Contatti principali allineati, indice da rifare ed evento della rubrica. */
    private function afterWrite(int $id, string $what, Context $context): void
    {
        ContactPoints::primaryWritten($this->owner(), $id);
        $context->forget($this->cacheKey());

        $row = $this->model()::findById($id);

        if (is_array($row) && isset($row['id'])) {
            [$event, $key] = $this->event($what);
            ContactEvents::dispatch($event, ['id' => $id, $key => $row]);
        }
    }

    /** @return array{0: string, 1: string} il nome dell'evento e la chiave della scheda nel payload */
    abstract protected function event(string $what): array;

    public function undo(Change $change, Context $context): void
    {
        $model = $this->model();
        $values = $change->action === 'created' ? ['deleted' => 'true'] : $change->oldValues;

        if ($values === [] || $change->recordId <= 0) {
            return;
        }

        $result = $model::query()->Update($model::$table, $values, 'id', $change->recordId);

        if (($result->success ?? false) !== true) {
            throw new RuntimeException('Annullo non riuscito per la scheda '.$change->recordId.'.');
        }

        $context->forget($this->cacheKey());
    }
}

