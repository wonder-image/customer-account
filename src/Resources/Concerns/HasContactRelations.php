<?php

namespace Wonder\Plugin\CustomerAccount\Resources\Concerns;

use Throwable;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\Input;
use Wonder\App\ResourceSchema\RepeaterColumn;
use Wonder\App\ResourceSchema\RepeaterRelation;
use Wonder\App\Support\Repeater;
use Wonder\Plugin\CustomerAccount\Models\ContactRelation;
use Wonder\Plugin\CustomerAccount\Support\CompanyBrands;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;

/**
 * Il repeater dei legami azienda/persona di una Resource della rubrica:
 * «Referenti» sulle aziende, «Aziende» sulle persone.
 *
 * Il lato lo dice `relationSide()`: `ContactRelations::PERSON` (default) per
 * le persone, `ContactRelations::COMPANY` per le aziende.
 *
 * Il sync del core cancella le righe di un repeater che non arriva nel POST:
 * qui il repeater si salva solo se il form l'ha mostrato (campo
 * `contact_relations_form`).
 *
 * Porta con sé `HasContactPoints` («Altri contatti»): la Resource che lo
 * mostra mette `contactPointFields()` nel form e chiama
 * `assertContactPoints()` in `mutateRequestValues()`; lettura e scrittura
 * passano da qui.
 */
trait HasContactRelations
{
    use HasContactPoints;

    public const RELATION_PEOPLE = 'contact_people';
    public const RELATION_COMPANIES = 'contact_companies';
    public const RELATION_MARKER = 'contact_relations_form';

    /** Il lato della scheda nel legame. */
    public static function relationSide(): string
    {
        return ContactRelations::PERSON;
    }

    /** Il nome del repeater della scheda. */
    public static function relationInputName(): string
    {
        return static::relationSide() === ContactRelations::COMPANY ? self::RELATION_PEOPLE : self::RELATION_COMPANIES;
    }

    /** @return list<Input> */
    public static function contactRelationFields(): array
    {
        if (!ContactRelations::enabled()) {
            return [];
        }

        return [
            static::relationSide() === ContactRelations::COMPANY ? static::contactPeopleField() : static::contactCompaniesField(),
            FormField::key(self::RELATION_MARKER)->hidden()->value('1'),
        ];
    }

    protected static function contactPeopleField(): Input
    {
        return static::relationRepeater(self::RELATION_PEOPLE, ContactRelations::COMPANY, [
            RepeaterColumn::key('id')->hidden(),
            static::withRelationQuickCreate(
                RepeaterColumn::key(ContactRelations::PERSON)
                    ->selectSearch(['' => '—'] + static::relationOptions(ContactRelations::COMPANY))
                    ->label(static::relationText('person'))
                    ->columnSpan(6),
                ContactRelations::COMPANY
            ),
            RepeaterColumn::key('job_title')->text()->label(static::relationText('job_title'))->columnSpan(3),
            static::departmentsColumn(),
            ...static::relationFlagColumns(),
            static::relationBrandsColumn(),
        ], static::relationText('add_person'));
    }

    protected static function contactCompaniesField(): Input
    {
        return static::relationRepeater(self::RELATION_COMPANIES, ContactRelations::PERSON, [
            RepeaterColumn::key('id')->hidden(),
            static::withRelationQuickCreate(
                RepeaterColumn::key(ContactRelations::COMPANY)
                    ->selectSearch(['' => '—'] + static::relationOptions(ContactRelations::PERSON))
                    ->label(static::relationText('company'))
                    ->columnSpan(6),
                ContactRelations::PERSON
            ),
            RepeaterColumn::key('job_title')->text()->label(static::relationText('job_title'))->columnSpan(3),
            static::departmentsColumn(),
            ...static::relationFlagColumns(),
        ], static::relationText('add_company'));
    }

    /**
     * Di cosa si occupa il referente (vuoto = di tutto): a chi mandare
     * fatture, newsletter e avvisi. Come i brand seguiti, non è una colonna
     * del legame: la legge `hydrateRepeaterFormValues()`, la scrive `sync()`.
     */
    protected static function departmentsColumn(): Input
    {
        return RepeaterColumn::key('departments')
            ->selectSearch(ContactRelations::departments(), true)
            ->label(static::relationText('departments'))
            ->columnSpan(3);
    }

    /**
     * I brand che il referente segue (vuoto = tutti). Le scelte sono tutti i
     * brand: il form non sa quale azienda mostra, e `ContactRelations`
     * tiene solo quelli legati all'azienda della riga.
     */
    protected static function relationBrandsColumn(): Input
    {
        return RepeaterColumn::key('brand_ids')
            ->selectSearch(CompanyBrands::options(), true)
            ->label(static::relationText('relation_brands'))
            ->columnSpan(12);
    }

    /**
     * Il «+» del select di una riga, per creare al volo l'altro lato.
     * Restituisce `[Resource, campi, campo etichetta, testo del bottone]`
     * o null (nessun «+», il default): lo sovrascrive chi ha una Resource
     * che sa creare persone o aziende (`QuickCreateStore`).
     *
     * @return array{0: class-string, 1?: list<string>|null, 2?: string|null, 3?: string|null}|null
     */
    protected static function relationQuickCreate(string $parentColumn): ?array
    {
        return null;
    }

    private static function withRelationQuickCreate(Input $column, string $parentColumn): Input
    {
        $quick = static::relationQuickCreate($parentColumn);

        if ($quick === null || !method_exists($column, 'quickCreate')) {
            return $column;
        }

        return $column->quickCreate($quick[0], $quick[1] ?? null, null, $quick[2] ?? null, $quick[3] ?? null);
    }

    /** @return list<Input> */
    protected static function relationFlagColumns(): array
    {
        $options = ['false' => static::relationText('no'), 'true' => static::relationText('yes')];
        $access = [];

        foreach (ContactRelation::ACCESS as $value) {
            $access[$value] = static::relationText('access_'.$value);
        }

        return [
            RepeaterColumn::key('is_primary')->select($options)->value('false')
                ->label(static::relationText('is_primary'))->columnSpan(4),
            RepeaterColumn::key('receives_documents')->select($options)->value('false')
                ->label(static::relationText('receives_documents'))->columnSpan(4),
            RepeaterColumn::key('access')->select($access)->value(ContactRelation::ACCESS_NONE)
                ->label(static::relationText('access'))->columnSpan(4),
        ];
    }

    /** @param list<Input> $columns */
    protected static function relationRepeater(string $key, string $parentColumn, array $columns, string $addLabel): Input
    {
        return FormField::key($key)
            ->repeater($columns)
            ->relation(
                RepeaterRelation::make(ContactRelation::$table, $parentColumn)
                    ->model(ContactRelation::class)
                    ->positionKey('position')
                    ->softDelete(true)
            )
            ->nested()
            ->repeaterSortable()
            ->repeaterAddLabel($addLabel)
            ->label('');
    }

    /**
     * Le schede dell'altro lato per il select di una riga.
     *
     * @return array<int, string>
     */
    protected static function relationOptions(string $parentColumn): array
    {
        $model = ContactRelations::counterpartModel($parentColumn);

        try {
            $rows = $model::find(['deleted' => 'false']);
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $options = [];

        foreach (isset($rows['id']) ? [$rows] : $rows as $row) {
            if (is_array($row) && isset($row['id'])) {
                $options[(int) $row['id']] = static::relationLabel($row);
            }
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    /** Come si legge una scheda nel select: nome o ragione sociale, ed email se c'è. */
    protected static function relationLabel(array $row): string
    {
        $name = trim((string) ($row['business_name'] ?? ''));

        if ($name === '') {
            $name = trim(((string) ($row['name'] ?? '')).' '.((string) ($row['surname'] ?? '')));
        }

        $name = html_entity_decode($name !== '' ? $name : '#'.($row['id'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $email = trim((string) ($row['email'] ?? ''));

        return $email !== '' ? "{$name} · {$email}" : $name;
    }

    protected static function relationText(string $key): string
    {
        return (string) __t('contacts.'.$key);
    }

    /**
     * I valori della persona creata al volo da una riga dei referenti: chi usa
     * il trait li ritocca (ruoli, codice, …).
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    protected static function relationPersonPayload(array $values): array
    {
        return $values;
    }

    /**
     * Le righe dei legami si leggono da `contact_relations`: aree e brand
     * seguiti stanno in altre tabelle e si aggiungono qui, altrimenti la
     * colonna vuota li cancellerebbe al salvataggio.
     */
    public static function hydrateRepeaterFormValues(
        array $values,
        int|string|null $parentId = null,
        ?array $post = null,
        ?array $files = null
    ): array {
        $values = static::withContactPointValues(parent::hydrateRepeaterFormValues($values, $parentId, $post, $files), $parentId);

        $input = static::relationInputName();

        if (is_array($values[$input] ?? null)) {
            $values[$input] = ContactRelations::withDepartments($values[$input]);

            if (static::relationSide() === ContactRelations::COMPANY) {
                $values[$input] = ContactRelations::withBrandIds($values[$input]);
            }
        }

        return $values;
    }

    public static function stripRelationInputValues(array $values): array
    {
        unset($values[self::RELATION_MARKER]);

        return parent::stripRelationInputValues($values);
    }

    public static function syncRepeaterRelations(
        int|string $parentId,
        array $post,
        array $files = [],
        string $action = 'store',
        string $context = 'backend'
    ): array {
        $ours = [self::RELATION_PEOPLE, self::RELATION_COMPANIES];
        $summary = [];

        // Il ciclo del core, senza i nostri repeater.
        foreach (static::repeaterRelations() as $inputName => $entry) {
            if (in_array($inputName, $ours, true)) {
                continue;
            }

            $relation = $entry['relation'];
            $rows = static::prepareRepeaterRows($inputName, Repeater::rowsFromRequest($inputName, $post, $files, Repeater::columnDefaults($entry['field'])), $action, $context);
            $summary[$inputName] = Repeater::syncRelatedRows(
                $relation,
                $parentId,
                $rows,
                fn (array $payload, array $row, ?array $existingRow): array => static::prepareRepeaterRelationRow(
                    $inputName, $payload, $row, $existingRow, $action, $context
                )
            );
        }

        $inputName = static::relationInputName();

        if (ContactRelations::enabled() && isset($post[self::RELATION_MARKER])) {
            $summary[$inputName] = ContactRelations::sync(
                static::relationSide(),
                (int) $parentId,
                Repeater::rowsFromRequest($inputName, $post, $files),
                static fn (array $values): array => static::relationPersonPayload($values)
            );
        }

        $points = static::syncContactPoints((int) $parentId, $post);

        if ($points !== null) {
            $summary[static::$pointsInput] = $points;
        }

        return $summary;
    }
}
