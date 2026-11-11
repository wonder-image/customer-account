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
use Wonder\Plugin\CustomerAccount\Support\Crm;

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
 * `mutateRequestValues()`; lettura e scrittura passano da qui.
 *
 * Una riga ha la controparte, il ruolo e l'«Ufficio» (uno solo, `department`),
 * poi i «Contatti aziendali» (`workContactColumns()`): con il CRM acceso un
 * repeater `points`, altrimenti un'email e un telefono. Sulle aziende ci
 * sono anche i «Brand seguiti», visibili solo se l'azienda ha più di un
 * brand (campo nascosto `relation_brands_many`, che lo script del modulo
 * tiene allineato alle righe dei brand dello stesso form). Il form non crea
 * più persone: una riga senza controparte si scarta se è vuota, altrimenti
 * `assertRelationContacts()` rifiuta il salvataggio. Principale, «riceve i
 * documenti» e accesso non si postano: `ContactRelations::sync()` tiene
 * quelli salvati.
 */
trait HasContactRelations
{
    use HasContactPoints;

    public const RELATION_PEOPLE = 'contact_people';
    public const RELATION_COMPANIES = 'contact_companies';
    public const RELATION_MARKER = 'contact_relations_form';
    public const RELATION_BRANDS_MANY = 'relation_brands_many';

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

        if (static::relationSide() !== ContactRelations::COMPANY) {
            return [static::contactCompaniesField(), FormField::key(self::RELATION_MARKER)->hidden()->value('1')];
        }

        return [
            static::contactPeopleField(),
            FormField::key(self::RELATION_MARKER)->hidden()->value('1'),
            // «1» se l'azienda ha più di un brand: decide se i «Brand seguiti» si vedono.
            FormField::key(self::RELATION_BRANDS_MANY)->hidden()->value('0')->attribute('data-ca-brands-many'),
        ];
    }

    protected static function contactPeopleField(): Input
    {
        return static::relationRepeater(self::RELATION_PEOPLE, ContactRelations::COMPANY, [
            RepeaterColumn::key('id')->hidden(),
            static::withRelationQuickCreate(
                RepeaterColumn::key(ContactRelations::PERSON)
                    ->selectSearch(['' => (string) __t('contacts.option_none_f')] + static::relationOptions(ContactRelations::COMPANY))
                    ->label(static::relationText('person'))
                    ->columnSpan(3),
                ContactRelations::COMPANY
            ),
            RepeaterColumn::key('job_title')->text()->label(static::relationText('job_title'))->columnSpan(3),
            static::officeColumn(3),
            ...static::workContactColumns(),
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
                    ->columnSpan(3),
                ContactRelations::PERSON
            ),
            RepeaterColumn::key('job_title')->text()->label(static::relationText('job_title'))->columnSpan(3),
            static::officeColumn(3),
            ...static::workContactColumns(),
        ], static::relationText('add_company'));
    }

    /**
     * I «Contatti aziendali» del referente per quell'azienda (facoltativi).
     * Con il CRM acceso un repeater `points` (tipo, email oppure prefisso e
     * numero); spento un'email e un telefono soli, le colonne di
     * `contact_relations`. Mai un'email di accesso.
     *
     * @return list<Input>
     */
    protected static function workContactColumns(): array
    {
        if (Crm::enabled()) {
            return [
                RepeaterColumn::key('points')
                    ->repeater(static::pointColumns())
                    ->label(static::relationText('work_contacts'))
                    ->repeaterAddLabel(static::relationText('point_add'))
                    ->columnSpan(12),
            ];
        }

        return [
            RepeaterColumn::key('email')->email()->label(static::relationText('work_contacts_email'))->columnSpan(4),
            RepeaterColumn::key('phone_prefix')->phonePrefix()->label(static::relationText('work_phone_prefix'))->columnSpan(2),
            RepeaterColumn::key('phone')->tel()->label(static::relationText('work_contacts_phone'))->columnSpan(3),
        ];
    }

    /**
     * Rifiuta il salvataggio se una riga dei legami non va: senza controparte
     * ma con qualcosa di scritto (il form non crea più persone: una riga del
     * tutto vuota invece si scarta), email di formato o dominio non valido
     * (qui sì il DNS: è il form), telefono o prefisso non numerici. Senza
     * `contact_relations_form` non controlla niente. Le Resource lo chiamano
     * in `mutateRequestValues()` con il POST intero: il repeater lì non c'è
     * più (`stripRelationInputValues()`).
     */
    protected static function assertRelationContacts(array $post): void
    {
        $rows = $post[static::relationInputName()] ?? null;

        if (!isset($post[self::RELATION_MARKER]) || !is_array($rows)) {
            return;
        }

        $other = static::relationSide() === ContactRelations::COMPANY ? ContactRelations::PERSON : ContactRelations::COMPANY;
        $problems = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if ((int) (is_scalar($row[$other] ?? null) ? $row[$other] : 0) <= 0) {
                if (static::relationRowFilled($row)) {
                    $problems[] = static::relationText('relation_'.($other === ContactRelations::PERSON ? 'person' : 'company').'_required');
                }

                continue;
            }

            $email = is_scalar($row['email'] ?? null) ? strtolower(trim((string) $row['email'])) : '';
            $phone = $row['phone'] ?? '';

            if ($email !== '' && !ContactPoints::validEmail($email)) {
                $problems[] = (string) __t('contacts.work_email_invalid', ['value' => mb_substr($email, 0, 80)]);
            }

            if (ContactPoints::phoneNumber($phone) === null || (ContactPoints::phoneNumber($phone) !== '' && ContactPoints::phonePrefix($row['phone_prefix'] ?? '') === null)) {
                $problems[] = (string) __t('contacts.work_phone_invalid', ['value' => is_scalar($phone) ? mb_substr((string) $phone, 0, 40) : '']);
            }

            if (is_array($row['points'] ?? null)) {
                $problems = [...$problems, ...ContactPoints::cleanBox(array_values($row['points']), true)[1]];
            }
        }

        if ($problems !== []) {
            throw new InvalidArgumentException(implode("\n", array_values(array_unique($problems))));
        }
    }

    /** Una riga senza controparte porta qualcosa (ruolo, email, telefono, un recapito)? L'ufficio ha un default e non conta. */
    private static function relationRowFilled(array $row): bool
    {
        $values = [$row['job_title'] ?? '', $row['email'] ?? '', $row['phone'] ?? ''];

        foreach (is_array($row['points'] ?? null) ? $row['points'] : [] as $point) {
            if (is_array($point)) {
                $values[] = $point['email'] ?? '';
                $values[] = $point['phone'] ?? '';
            }
        }

        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * I brand che il referente segue (vuoto = tutti). Le scelte sono tutti i
     * brand: il form non sa quale azienda mostra, e `ContactRelations`
     * tiene solo quelli legati all'azienda della riga. Si vede solo se
     * l'azienda ha più di un brand (`relation_brands_many`).
     */
    protected static function relationBrandsColumn(): Input
    {
        return RepeaterColumn::key('brand_ids')
            ->selectSearch(CompanyBrands::options(), true)
            ->label(static::relationText('relation_brands'))
            ->columnSpan(12)
            ->visibleWhen(self::RELATION_BRANDS_MANY, '1');
    }

    /** Quanti brand ha l'azienda della scheda: con uno o nessuno i «Brand seguiti» non servono. */
    protected static function relationBrandCount(int $companyId): int
    {
        return $companyId > 0 ? count(CompanyBrands::idsOf($companyId)) : 0;
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

    /** Come si legge una scheda nel select: un'azienda con la sola ragione sociale, una persona con nome ed email se c'è. */
    protected static function relationLabel(array $row): string
    {
        $company = trim((string) ($row['business_name'] ?? ''));
        $name = $company !== '' ? $company : trim(((string) ($row['name'] ?? '')).' '.((string) ($row['surname'] ?? '')));
        $name = html_entity_decode($name !== '' ? $name : '#'.($row['id'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $email = $company === '' ? trim((string) ($row['email'] ?? '')) : '';

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

            foreach ($values[$input] as $key => $row) {
                // Il select è uno: il primo ufficio salvato.
                if (is_array($row) && !array_key_exists('department', $row)) {
                    $values[$input][$key]['department'] = ContactRelations::firstDepartment($row['departments'] ?? []);
                }
            }

            if (Crm::enabled()) {
                $values[$input] = ContactRelations::withPoints($values[$input]);
            }

            if (static::relationSide() === ContactRelations::COMPANY) {
                $values[$input] = ContactRelations::withBrandIds($values[$input]);
            }
        }

        if (static::relationSide() === ContactRelations::COMPANY) {
            $values[self::RELATION_BRANDS_MANY] = static::relationBrandCount((int) ($parentId ?? 0)) > 1 ? '1' : '0';
        }

        return $values;
    }

    /**
     * Le righe postate dei legami come le vuole `ContactRelations::sync()`.
     * Il form non crea persone (`new_name`, `new_surname`, `new_email` non
     * passano: una riga senza controparte si scarta). Con il CRM acceso
     * `points` c'è sempre, anche vuoto (un repeater interno senza righe non
     * posta la chiave), a meno che la riga porti i campi singoli; spento non
     * passa, e valgono email e telefono della riga. I «Brand seguiti» contano
     * solo se l'azienda ha più di un brand: altrimenti restano quelli salvati.
     *
     * @return list<array<string, mixed>>
     */
    protected static function relationRowsFromRequest(int $parentId, array $post, array $files = []): array
    {
        $company = static::relationSide() === ContactRelations::COMPANY;
        $manyBrands = $company && static::relationBrandCount($parentId) > 1;
        $rows = [];

        foreach (Repeater::rowsFromRequest(static::relationInputName(), $post, $files) as $row) {
            if (!is_array($row)) {
                continue;
            }

            unset($row['new_name'], $row['new_surname'], $row['new_email']);

            if (!Crm::enabled()) {
                unset($row['points']);
            } elseif (is_array($row['points'] ?? null)) {
                $row['points'] = array_values($row['points']);
            } elseif (!array_key_exists('email', $row) && !array_key_exists('phone', $row)) {
                $row['points'] = [];
            } else {
                unset($row['points']);
            }

            // Con un brand solo il campo non si vede: una riga senza la chiave tiene comunque quelli salvati.
            if ($company && !$manyBrands) {
                unset($row['brand_ids']);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    public static function stripRelationInputValues(array $values): array
    {
        unset($values[self::RELATION_MARKER], $values[self::RELATION_BRANDS_MANY]);

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
                static::relationRowsFromRequest((int) $parentId, $post, $files),
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
