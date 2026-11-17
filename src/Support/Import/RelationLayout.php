<?php

namespace Wonder\Plugin\CustomerAccount\Support\Import;

use Throwable;
use Wonder\App\Dataset\Column;
use Wonder\App\Import\Change;
use Wonder\App\Import\Context;
use Wonder\App\Import\Layout;
use Wonder\Plugin\CustomerAccount\Models\ContactRelation;
use Wonder\Plugin\CustomerAccount\Resources\CompanyResource;
use Wonder\Plugin\CustomerAccount\Support\{ContactPoints, ContactRelations};

/**
 * I referenti: il legame fra un'azienda e una persona che esistono già
 * (riconosciute come nei loro tracciati). Una riga crea, riapre o aggiorna il
 * legame con `ContactRelations::import()`; le celle vuote tengono il valore
 * salvato. Aree e brand restano al form dell'azienda.
 */
class RelationLayout extends Layout
{
    use ReadsImportCells;

    public const KEY = 'contacts_relations';

    private const FLAGS = ['is_primary', 'receives_documents', 'is_legal_representative'];

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
        return $this->text('relations');
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
            CompanyResource::allows((array) ($user->authority ?? []), 'update'),
        );
    }

    public function backUrl(): ?string
    {
        return ImportExtensions::backUrl($this->extensions(), self::KEY, static fn (): string => ImportExtensions::listUrl(CompanyResource::class));
    }

    public function message(Throwable $exception): ?string
    {
        return $exception instanceof PartyImportException ? $exception->getMessage() : null;
    }

    /* ----------------------------------------------------------------- colonne */

    public function columns(): array
    {
        $columns = [
            Column::make('company_id', $this->text('columns.company_id'), ['ID azienda', 'Id azienda'])->field($this->text('fields.card_id')),
            Column::make('company_code', $this->text('columns.company_code'), ['Codice azienda'])->field($this->text('fields.card_code')),
            Column::make('company_pi', $this->text('columns.company_pi'), ['Partita IVA', 'P.IVA', 'PIVA'])->field($this->text('fields.tax')),
            Column::make('person_id', $this->text('columns.person_id'), ['ID persona', 'Id persona'])->field($this->text('fields.card_id')),
            Column::make('person_code', $this->text('columns.person_code'), ['Codice persona'])->field($this->text('fields.card_code')),
            Column::make('person_cf', $this->text('columns.person_cf'), ['Codice fiscale', 'CF'])->field($this->text('fields.tax')),
            Column::make('person_email', $this->text('columns.person_email'), ['Email persona', 'Email'])->field($this->text('fields.card_email')),
            Column::make('job_title', (string) __t('contacts.job_title'), ['Qualifica', 'Ruolo'])->field(''),
            Column::make('is_primary', $this->text('columns.is_primary'), ['Principale'])->field($this->text('fields.flag')),
            Column::make('receives_documents', $this->text('columns.receives_documents'), ['Documenti'])->field($this->text('fields.flag')),
            Column::make('is_legal_representative', $this->text('columns.is_legal_representative'), ['Rappresentante legale'])->field($this->text('fields.flag')),
            Column::make('access', $this->text('columns.access'), ['Accesso'])->field($this->text('fields.access')),
            Column::make('email', $this->text('columns.work_email'), ['Email di lavoro'])->field($this->text('fields.email')),
            Column::make('phone', $this->text('columns.work_phone'), ['Telefono di lavoro'])->field($this->text('fields.phone')),
            Column::make('phone_prefix', $this->text('columns.work_phone_prefix'), ['Prefisso di lavoro'])->field($this->text('fields.phone_prefix')),
        ];

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

    /** @param array<string, int> $seen coppia azienda/persona ⇒ riga del file che l'ha già usata */
    private function validateRow(int $line, array $data, Context $context, array &$seen): array
    {
        $errors = [];
        $warnings = [];
        $company = $this->find('company', $data, $context, $errors);
        $person = $this->find('person', $data, $context, $errors);

        if ($company === null || $person === null) {
            return ['action' => 'create', 'errors' => $errors, 'warnings' => []];
        }

        $pair = $company['id'].'#'.$person['id'];
        if (isset($seen[$pair])) {
            $errors[] = $this->text('errors.duplicate_relation', ['line' => $seen[$pair]]);
        }
        $seen[$pair] ??= $line;

        $values = $this->cellValues($data, (int) $company['id'], (int) $person['id'], $errors);
        $current = $this->current((int) $company['id'], (int) $person['id']);

        foreach ($this->extensions() as $extension) {
            $extra = $extension->values(self::KEY, $data, $current, $context);
            $values = [...$values, ...array_map(static fn ($value) => $value === null ? null : (string) $value, (array) ($extra['values'] ?? []))];
            array_push($errors, ...array_map('strval', (array) ($extra['errors'] ?? [])));
            array_push($warnings, ...array_map('strval', (array) ($extra['warnings'] ?? [])));
        }

        $action = 'create';

        if ($errors === []) {
            $plan = ContactRelations::prepareImport((int) $company['id'], (int) $person['id'], $values);
            $action = $plan['id'] <= 0 ? 'create' : ($plan['changed'] === [] ? 'unchanged' : 'update');

            if ($plan['access_lowered']) {
                $warnings[] = $this->text('warnings.access_lowered');
            }
        }

        return [
            'action' => $action,
            'errors' => array_values(array_unique($errors)),
            'warnings' => $warnings,
            'data' => [
                '_company' => (string) $company['id'],
                '_person' => (string) $person['id'],
                '_values' => (string) json_encode($values, JSON_UNESCAPED_UNICODE),
            ],
        ];
    }

    /**
     * L'azienda o la persona della riga, riconosciuta come nel suo tracciato.
     *
     * @param list<string> $errors
     */
    private function find(string $type, array $data, Context $context, array &$errors): ?array
    {
        if ($type === 'company') {
            $layout = $this->companies ??= new CompanyLayout($this->extensions());
            $ids = ['id' => $data['company_id'] ?? '', 'code' => $data['company_code'] ?? '', 'pi' => $data['company_pi'] ?? ''];
        } else {
            $layout = $this->people ??= new PeopleLayout($this->extensions());
            $ids = ['id' => $data['person_id'] ?? '', 'code' => $data['person_code'] ?? '', 'cf' => $data['person_cf'] ?? '', 'email' => $data['person_email'] ?? ''];
        }

        $ids = array_map(static fn ($value): string => trim((string) $value), $ids);

        if (implode('', $ids) === '') {
            $errors[] = $this->text('errors.'.$type.'_missing');

            return null;
        }

        $before = count($errors);
        $card = $layout->findExisting($ids, $context, $errors);

        if ($card === null && count($errors) === $before) {
            $errors[] = $this->text('errors.'.$type.'_missing');
        }

        return $card;
    }

    /** Il legame salvato (anche eliminato), per le estensioni; null se non c'è. */
    private function current(int $companyId, int $personId): ?array
    {
        $result = ContactRelation::query()->Select(ContactRelation::$table, [ContactRelations::COMPANY => $companyId, ContactRelations::PERSON => $personId, 'deleted' => 'false'], 1);
        $row = $result->row ?? null;

        return is_array($row) && isset($row['id']) ? $this->decoded($row) : null;
    }

    /**
     * Le celle lette e ripulite; quelle vuote non ci sono.
     *
     * @param list<string> $errors
     * @return array<string, string>
     */
    private function cellValues(array $data, int $companyId, int $personId, array &$errors): array
    {
        $values = [];

        $title = trim((string) ($data['job_title'] ?? ''));
        if ($title !== '') {
            if (mb_strlen($title) > 150) {
                $errors[] = $this->text('errors.invalid_value', ['field' => (string) __t('contacts.job_title'), 'value' => $title]);
            } else {
                $values['job_title'] = $title;
            }
        }

        foreach (self::FLAGS as $field) {
            $cell = trim((string) ($data[$field] ?? ''));

            if ($cell === '') {
                continue;
            }

            $flag = $this->flag($cell);

            if ($flag === null) {
                $errors[] = $this->text('errors.invalid_flag', ['field' => $this->text('columns.'.$field), 'value' => $cell]);
            } else {
                $values[$field] = $flag;
            }
        }

        $access = trim((string) ($data['access'] ?? ''));
        if ($access !== '') {
            $code = $this->lookup($access, [
                ContactRelation::ACCESS_NONE => $this->text('access_none'),
                ContactRelation::ACCESS_BUYER => $this->text('access_buyer'),
                ContactRelation::ACCESS_ADMIN => $this->text('access_admin'),
            ]);

            if ($code === null) {
                $errors[] = $this->text('errors.invalid_value', ['field' => $this->text('columns.access'), 'value' => $access]);
            } else {
                $values['access'] = $code;
            }
        }

        $email = mb_strtolower(trim((string) ($data['email'] ?? '')), 'UTF-8');
        if ($email !== '') {
            if (mb_strlen($email) > 190 || !ContactPoints::emailFormat($email)) {
                $errors[] = $this->text('errors.invalid_email', ['value' => $email]);
            } else {
                $values['email'] = $email;
            }
        }

        $stored = $this->current($companyId, $personId);

        return [...$values, ...$this->phoneCells(
            (string) ($data['phone'] ?? ''),
            (string) ($data['phone_prefix'] ?? ''),
            $stored,
            null,
            $this->text('columns.work_phone'),
            $this->text('columns.work_phone_prefix'),
            $errors,
        )];
    }

    /* --------------------------------------------------------------- scrittura */

    public function apply(array $data, Context $context): array
    {
        $values = json_decode((string) ($data['_values'] ?? ''), true);
        $values = is_array($values) ? $values : [];

        try {
            $result = ContactRelations::import((int) ($data['_company'] ?? 0), (int) ($data['_person'] ?? 0), $values);
        } catch (\RuntimeException) {
            throw new PartyImportException($this->text('errors.save_failed'));
        }

        if ($result['access_lowered']) {
            $context->warn($this->text('warnings.access_lowered'));
        }

        $changes = [];

        if ($result['action'] === 'created') {
            $changes[] = new Change('contact_relation', $result['id'], 'created');
        } elseif ($result['action'] !== 'unchanged') {
            $changes[] = new Change('contact_relation', $result['id'], 'updated', $result['old']);
        }

        foreach ($result['demoted'] as $relationId) {
            $changes[] = new Change('contact_relation', (int) $relationId, 'updated', ['is_primary' => 'true']);
        }

        return [
            'action' => match ($result['action']) {
                'created' => 'create',
                'unchanged' => 'unchanged',
                default => 'update',
            },
            'changes' => $changes,
        ];
    }

    public function undo(Change $change, Context $context): void
    {
        ContactRelations::restore($change->recordId, $change->action === 'created' ? ['deleted' => 'true'] : $change->oldValues);
    }
}
