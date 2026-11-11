<?php

namespace Wonder\Plugin\CustomerAccount\Resources\Concerns;

use Closure;
use InvalidArgumentException;
use Throwable;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\Input;
use Wonder\App\ResourceSchema\RepeaterColumn;
use Wonder\App\ResourceSchema\RepeaterRelation;
use Wonder\App\Support\Repeater;
use Wonder\Plugin\CustomerAccount\Models\ContactRelation;
use Wonder\Plugin\CustomerAccount\Support\CompanyBrands;
use Wonder\Plugin\CustomerAccount\Support\ContactPoints;
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
 * Porta con sé `HasContactPoints` (il riquadro «Contatti» o «Altri
 * contatti»): la Resource mette `contactBoxFields()` (o
 * `contactPointFields()`) nel form e chiama `applyContactPoints()` in
 * `mutateRequestValues()`; lettura e scrittura passano da qui. Le righe dei
 * legami hanno i contatti di lavoro (`workContactColumns()`), controllati
 * dal form con `assertRelationContacts()`.
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
                    ->selectSearch(['' => static::relationText('new_person')] + static::relationOptions(ContactRelations::COMPANY))
                    ->label(static::relationText('person'))
                    ->columnSpan(6),
                ContactRelations::COMPANY
            ),
            // Con il select vuoto la riga crea la persona: nome e cognome qui, email da quella di lavoro.
            RepeaterColumn::key('new_name')->text()->label(static::relationText('new_person_name'))->columnSpan(3)
                ->visibleWhen(ContactRelations::PERSON, ''),
            RepeaterColumn::key('new_surname')->text()->label(static::relationText('new_surname'))->columnSpan(3)
                ->visibleWhen(ContactRelations::PERSON, ''),
            RepeaterColumn::key('job_title')->text()->label(static::relationText('job_title'))->columnSpan(3),
            static::departmentsColumn(),
            ...static::workContactColumns(),
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
                    ->selectSearch(['' => (string) __t('contacts.option_none_f')] + static::relationOptions(ContactRelations::PERSON))
                    ->label(static::relationText('company'))
                    ->columnSpan(6),
                ContactRelations::PERSON
            ),
            RepeaterColumn::key('job_title')->text()->label(static::relationText('job_title'))->columnSpan(3),
            static::departmentsColumn(),
            ...static::workContactColumns(),
            ...static::relationFlagColumns(),
        ], static::relationText('add_company'));
    }

    /**
     * Email e telefono di lavoro del referente per quell'azienda
     * (facoltativi): colonne di `contact_relations`, ripulite da
     * `ContactRelations::workContacts()`. Mai un'email di accesso.
     *
     * @return list<Input>
     */
    protected static function workContactColumns(): array
    {
        return [
            RepeaterColumn::key('email')->email()->label(static::relationText('work_email'))->columnSpan(6),
            RepeaterColumn::key('phone_prefix')->phonePrefix()->label(static::relationText('work_phone_prefix'))->columnSpan(2),
            RepeaterColumn::key('phone')->tel()->label(static::relationText('work_phone'))->columnSpan(4),
        ];
    }

    /**
     * Rifiuta il salvataggio se un contatto di lavoro postato nel repeater dei
     * legami non va: email di formato o dominio non valido (qui sì il DNS: è
     * il form), telefono o prefisso non numerici. Senza `contact_relations_form`
     * non controlla niente. Le Resource lo chiamano in `mutateRequestValues()`
     * con il POST intero: il repeater lì non c'è più (`stripRelationInputValues()`).
     */
    protected static function assertRelationContacts(array $post): void
    {
        $rows = $post[static::relationInputName()] ?? null;

        if (!isset($post[self::RELATION_MARKER]) || !is_array($rows)) {
            return;
        }

        $problems = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $email = is_scalar($row['email'] ?? null) ? strtolower(trim((string) $row['email'])) : '';
            $phone = $row['phone'] ?? '';

            if ($email !== '' && !ContactPoints::validEmail($email)) {
                $problems[] = (string) __t('contacts.work_email_invalid', ['value' => mb_substr($email, 0, 80)]);
            }

            // Una riga «nuova persona» con l'email di una persona che c'è già: si sceglie quella.
            $newPerson = static::relationSide() === ContactRelations::COMPANY
                && (int) ($row[ContactRelations::PERSON] ?? 0) <= 0
                && trim((is_scalar($row['new_name'] ?? null) ? (string) $row['new_name'] : '').(is_scalar($row['new_surname'] ?? null) ? (string) $row['new_surname'] : '')) !== '';
            $newEmail = is_scalar($row['new_email'] ?? null) && trim((string) $row['new_email']) !== '' ? strtolower(trim((string) $row['new_email'])) : $email;
            $existing = $newPerson ? ContactRelations::personByEmail($newEmail) : [];

            if ($existing !== []) {
                $problems[] = static::relationPersonExistsMessage($existing, $newEmail);
            }

            if (ContactPoints::phoneNumber($phone) === null || (ContactPoints::phoneNumber($phone) !== '' && ContactPoints::phonePrefix($row['phone_prefix'] ?? '') === null)) {
                $problems[] = (string) __t('contacts.work_phone_invalid', ['value' => is_scalar($phone) ? mb_substr((string) $phone, 0, 40) : '']);
            }
        }

        if ($problems !== []) {
            throw new InvalidArgumentException(implode("\n", array_values(array_unique($problems))));
        }
    }

    /**
     * Cosa dire quando la riga «nuova persona» porta l'email di una persona
     * che c'è già. Chi ha schede che non tutti vedono lo riscrive.
     *
     * @param array<string, mixed> $person
     */
    protected static function relationPersonExistsMessage(array $person, string $email): string
    {
        $name = trim((string) ($person['name'] ?? '').' '.(string) ($person['surname'] ?? ''));

        return (string) __t('contacts.relation_person_exists', ['email' => mb_substr($email, 0, 80), 'contact' => $name !== '' ? $name : $email]);
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
            ->quickCreate(static::officeTypeResource(), ['name'], null, 'name', (string) __t('contacts.office_type_add'))
            ->quickCreateCompact()
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
     * Restituisce `[Resource, campi, campo etichetta, testo del bottone, layout]`
     * o null (nessun «+», il default): lo sovrascrive chi ha una Resource
     * che sa creare persone o aziende (`QuickCreateStore`). Il layout serve
     * quando un campo non è nel form del target (l'email sta nel riquadro
     * «Contatti»).
     *
     * @return array{0: class-string, 1?: list<string>|null, 2?: string|null, 3?: string|null, 4?: Closure|null}|null
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

        return $column->quickCreate($quick[0], $quick[1] ?? null, $quick[4] ?? null, $quick[2] ?? null, $quick[3] ?? null);
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

    /** @var list<int> le controparti già legate alla scheda del form, lette da `hydrateRepeaterFormValues()` */
    protected static array $relationLinkedIds = [];

    /**
     * Le schede dell'altro lato per il select di una riga. Con un filtro
     * (`ContactRelations::useCounterpartFilter()`) solo le ammesse più quelle
     * già legate alla scheda del form: altrimenti chi non le vede le
     * staccherebbe salvando.
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

        $keep = [...ContactRelations::allowedCounterparts($parentColumn, array_keys($options)), ...static::$relationLinkedIds];
        $options = array_intersect_key($options, array_flip($keep));
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
        // Prima dei campi: il presenter chiama `formSchema()` dopo, e le scelte devono comprendere i legami di oggi.
        static::$relationLinkedIds = ContactRelations::linkedIds(static::relationSide(), (int) ($parentId ?? 0));
        $values = static::withContactPointValues(parent::hydrateRepeaterFormValues($values, $parentId, $post, $files), $parentId, $post);

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
