<?php

namespace Wonder\Plugin\CustomerAccount\Support;

use Wonder\App\Support\Repeater;
use Wonder\App\ResourceSchema\RepeaterRelation;
use Wonder\Plugin\CustomerAccount\Models\Company;
use Wonder\Plugin\CustomerAccount\Models\Contact;
use Wonder\Plugin\CustomerAccount\Models\ContactRelation;
use Wonder\Plugin\CustomerAccount\Models\ContactRelationBrand;
use Wonder\Plugin\CustomerAccount\Models\ContactRelationDepartment;
use Wonder\Sql\Transaction;

/**
 * I legami fra aziende (`companies`) e persone (`contacts`), in `contact_relations`.
 *
 * Una persona può essere referente di più aziende, un'azienda può avere più
 * referenti. `access` dice cosa può fare la persona per l'azienda nell'area
 * cliente. Sempre attivi: `enabled()` resta per chi lo chiama e per i test.
 *
 * Un referente può seguire solo alcuni brand dell'azienda
 * (`contact_relation_brands`); senza righe li segue tutti. Contano solo i
 * brand ancora legati all'azienda: se non ne resta nessuno, li segue tutti.
 *
 * Allo stesso modo un referente si occupa di una o più aree
 * (`contact_relation_departments`): senza righe si occupa di tutto. Chi deve
 * scegliere a chi mandare un documento parte da `peopleFor()`.
 */
final class ContactRelations
{
    public const COMPANY = 'company_id';
    public const PERSON = 'person_id';

    private static ?bool $override = null;

    /** @var array<string, string> aree aggiunte da moduli e siti: chiave => etichetta */
    private static array $extraDepartments = [];

    /** @var null|callable(string, list<int>): list<int> */
    private static $counterpartFilter = null;

    /** @var null|callable(int, int, string): bool */
    private static $accessGuard = null;

    /**
     * Restringe le schede che si possono legare, per chi ha una visibilità per
     * riga (il commerciale vede solo le sue): `fn(string $modelClass, list<int>
     * $ids): list<int>` dà gli id ammessi fra quelli dati. In scrittura una
     * controparte non ammessa si scarta, ma una già legata resta (chi non la
     * vede non la stacca salvando), ma in sola lettura: mansione, principale,
     * documenti, accesso, contatti di lavoro, aree e brand seguiti restano
     * quelli salvati, qualunque cosa arrivi (`sync()`); in lettura le scelte
     * del repeater sono le ammesse più quelle già legate. `null` (il default) = tutte.
     */
    public static function useCounterpartFilter(?callable $filter): void
    {
        self::$counterpartFilter = $filter;
    }

    /**
     * Chi può alzare `access` (`none` → `buyer` → `admin`):
     * `fn(int $companyId, int $personId, string $access): bool`. Se dice no,
     * l'accesso resta quello già salvato (`none` per un legame nuovo);
     * abbassarlo si può sempre. `null` (il default) = nessun controllo.
     */
    public static function useAccessGuard(?callable $guard): void
    {
        self::$accessGuard = $guard;
    }

    /**
     * Gli id ammessi dal filtro delle controparti (`useCounterpartFilter()`),
     * nell'ordine dato; senza filtro tutti.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    public static function allowedCounterparts(string $parentColumn, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map([self::class, 'id'], $ids), static fn (int $id): bool => $id > 0)));

        if (self::$counterpartFilter === null || $ids === []) {
            return $ids;
        }

        $allowed = array_map([self::class, 'id'], (array) (self::$counterpartFilter)(self::counterpartModel($parentColumn), $ids));

        return array_values(array_filter($ids, static fn (int $id): bool => in_array($id, $allowed, true)));
    }

    /**
     * Gli id dell'altro lato legati oggi a una scheda.
     *
     * @return list<int>
     */
    public static function linkedIds(string $parentColumn, int $parentId): array
    {
        return $parentId > 0 && in_array($parentColumn, [self::COMPANY, self::PERSON], true)
            ? array_values(array_filter(array_keys(self::liveByCounterpart($parentColumn, $parentId)), static fn (int $id): bool => $id > 0))
            : [];
    }

    public static function enabled(): bool
    {
        return self::$override ?? true;
    }

    /** Solo per i test: spegne i referenti (`null` li riaccende). */
    public static function useEnabled(?bool $enabled): void
    {
        self::$override = $enabled;
    }

    /** La colonna dell'altro lato del legame. */
    public static function counterpart(string $parentColumn): string
    {
        return $parentColumn === self::COMPANY ? self::PERSON : self::COMPANY;
    }

    /**
     * Il Model dell'altro lato: un'azienda ha persone, una persona aziende.
     *
     * @return class-string<\Wonder\App\Model>
     */
    public static function counterpartModel(string $parentColumn): string
    {
        return $parentColumn === self::COMPANY ? Contact::class : Company::class;
    }

    /**
     * Le righe postate da un repeater, pronte per `Repeater::syncRelatedRows()`.
     *
     * Toglie le righe senza controparte, le controparti che non esistono e i
     * doppioni (vince la prima). Una coppia che esiste già, anche cancellata,
     * riprende l'id della sua riga: così la chiave unica azienda/persona non
     * salta e la riga torna viva. Dal lato azienda al massimo un referente
     * principale (il primo); dal lato persona ogni riga è un'azienda diversa e
     * tiene il suo `is_primary` (gli altri referenti di quell'azienda lo
     * perdono in `sync()`). Se la riga non porta `access`, resta quello di
     * prima; con un `useAccessGuard()` che dice no, l'accesso non sale oltre
     * quello salvato (`none` per un legame nuovo). Le aree non sono colonne
     * del legame: le scrive `sync()`. Un valore non scalare vale vuoto.
     *
     * @param list<array<string, mixed>> $posted
     * @param list<array<string, mixed>> $existing tutte le righe del padre, cancellate comprese
     * @param list<int> $validIds le controparti vive
     * @return list<array<string, mixed>>
     */
    public static function normalize(array $posted, array $existing, string $parentColumn, int $parentId, array $validIds): array
    {
        $other = self::counterpart($parentColumn);
        $valid = array_fill_keys(array_map('intval', $validIds), true);
        $existingByCounterpart = [];

        foreach ($existing as $row) {
            $key = (int) ($row[$other] ?? 0);
            // Se una coppia avesse più righe, si preferisce quella viva.
            if ($key > 0 && (!isset($existingByCounterpart[$key]) || ($row['deleted'] ?? 'false') === 'false')) {
                $existingByCounterpart[$key] = $row;
            }
        }

        $rows = [];
        $seen = [];
        $primary = false;

        foreach ($posted as $row) {
            if (!is_array($row)) {
                continue;
            }

            $counterpartId = self::id($row[$other] ?? 0);

            if ($counterpartId <= 0 || isset($seen[$counterpartId]) || !isset($valid[$counterpartId])) {
                continue;
            }

            $seen[$counterpartId] = true;
            $stored = $existingByCounterpart[$counterpartId] ?? null;
            // Lo stato salvato conta solo per un legame vivo: uno cancellato che torna riparte da `none`.
            $before = $stored !== null && ($stored['deleted'] ?? 'false') === 'false' ? self::access($stored['access'] ?? null) : ContactRelation::ACCESS_NONE;
            $access = self::access($row['access'] ?? $stored['access'] ?? null);
            $companyId = $parentColumn === self::COMPANY ? $parentId : $counterpartId;
            $personId = $parentColumn === self::COMPANY ? $counterpartId : $parentId;

            if (self::rank($access) > self::rank($before) && self::$accessGuard !== null && !(self::$accessGuard)($companyId, $personId, $access)) {
                $access = $before;
            }

            $clean = [
                $other => $counterpartId,
                'job_title' => is_scalar($row['job_title'] ?? null) ? trim((string) $row['job_title']) : '',
                'is_primary' => self::flag($row['is_primary'] ?? 'false'),
                'receives_documents' => self::flag($row['receives_documents'] ?? 'false'),
                'access' => $access,
            ] + self::workContacts($row, $stored ?? []) + [
                'deleted' => 'false',
            ];

            // Un solo principale per azienda: dal lato persona le righe sono aziende diverse.
            if ($clean['is_primary'] === 'true' && $parentColumn === self::COMPANY) {
                $clean['is_primary'] = $primary ? 'false' : 'true';
                $primary = true;
            }

            if (isset($existingByCounterpart[$counterpartId]['id'])) {
                $clean['id'] = (int) $existingByCounterpart[$counterpartId]['id'];
            }

            $rows[] = $clean;
        }

        return $rows;
    }

    /**
     * I contatti di lavoro di una riga (`email`, `phone_prefix`, `phone`),
     * ripuliti come i contatti della scheda ma senza DNS: email in minuscolo
     * e di formato valido, telefono a sole cifre, prefisso `+NN`. Un valore
     * non valido (o non scalare) vale vuoto; una riga senza la chiave tiene
     * quello salvato. Il controllo del dominio lo fa il form
     * (`HasContactRelations::assertRelationContacts()`).
     *
     * @return array{email: string, phone_prefix: string, phone: string}
     */
    public static function workContacts(array $row, array $stored = []): array
    {
        $email = array_key_exists('email', $row) ? ContactPoints::cleanEmail($row['email']) : (string) ($stored['email'] ?? '');
        $phone = array_key_exists('phone', $row) ? (ContactPoints::phoneNumber($row['phone']) ?? '') : (string) ($stored['phone'] ?? '');
        $prefix = array_key_exists('phone_prefix', $row) ? (ContactPoints::phonePrefix($row['phone_prefix']) ?? '') : (string) ($stored['phone_prefix'] ?? '');

        return ['email' => $email, 'phone_prefix' => $phone !== '' ? $prefix : '', 'phone' => $phone];
    }

    /** `'true'` o `'false'` da quello che arriva da un select, un checkbox o un booleano. */
    public static function flag(mixed $value): string
    {
        return in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true) ? 'true' : 'false';
    }

    /** Un valore di `access` valido; tutto il resto è `none`. */
    public static function access(mixed $value): string
    {
        return in_array($value, ContactRelation::ACCESS, true) ? (string) $value : ContactRelation::ACCESS_NONE;
    }

    /**
     * Le aree di cui si occupa un referente, per un select: quelle di base e
     * quelle aggiunte.
     *
     * @return array<string, string>
     */
    public static function departments(): array
    {
        $out = [];

        foreach (ContactRelation::DEPARTMENTS as $key) {
            $out[$key] = (string) __t('contacts.department_'.$key);
        }

        return $out + self::$extraDepartments;
    }

    /**
     * Aggiunge un'area (es. `marketing`) per i select, le email e i sync.
     * Un modulo lo chiama al boot, un sito in `custom/function`.
     */
    public static function addDepartment(string $key, string $label): void
    {
        $key = strtolower(trim($key));

        if (preg_match('/^[a-z0-9_]{1,40}$/', $key) === 1) {
            self::$extraDepartments[$key] = $label;
        }
    }

    /** Un'area valida, altrimenti vuoto. */
    public static function department(mixed $value): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return array_key_exists($value, self::departments()) ? $value : '';
    }

    /**
     * Le aree valide di una lista (o di un valore solo), senza doppioni e
     * nell'ordine dato: le altre si scartano.
     *
     * @return list<string>
     */
    public static function cleanDepartments(mixed $values): array
    {
        $out = [];

        foreach (is_array($values) ? $values : [$values] as $value) {
            $value = self::department($value);

            if ($value !== '' && !in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /** L'etichetta di un'area; vuota se non c'è. */
    public static function departmentLabel(mixed $value): string
    {
        return self::departments()[self::department($value)] ?? '';
    }

    /**
     * Le aree di cui si occupa un referente; vuota = tutte.
     *
     * @return list<string>
     */
    public static function departmentsOf(int $relationId): array
    {
        return self::departmentsByRelation([$relationId])[$relationId] ?? [];
    }

    /**
     * Le aree di più legami, in una query. I legami che si occupano di tutto
     * (senza righe) non compaiono.
     *
     * @param list<int> $relationIds
     * @return array<int, list<string>>
     */
    public static function departmentsByRelation(array $relationIds): array
    {
        $relationIds = array_values(array_unique(array_filter(array_map('intval', $relationIds), static fn (int $id): bool => $id > 0)));
        $out = [];

        if ($relationIds === []) {
            return $out;
        }

        foreach (self::rows(ContactRelationDepartment::find(['relation_id' => $relationIds], null, 'id', 'ASC')) as $row) {
            $out[(int) $row['relation_id']][] = (string) $row['department'];
        }

        return $out;
    }

    /**
     * Scrive le aree di un referente: quelle sconosciute si scartano, una
     * lista vuota = si occupa di tutto.
     *
     * @param list<string>|string $departments
     * @return list<string> le aree rimaste
     */
    public static function setDepartments(int $relationId, array|string $departments): array
    {
        $relation = $relationId > 0 ? ContactRelation::findById($relationId) : null;

        if (!is_array($relation) || !isset($relation['id']) || ($relation['deleted'] ?? 'false') !== 'false') {
            return [];
        }

        return Transaction::run(static fn (): array => self::writeDepartments($relationId, self::cleanDepartments($departments)));
    }

    /**
     * Le righe di un repeater dei legami con `departments`, per mostrarle nel
     * form: la colonna non è di `contact_relations`.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function withDepartments(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            if (is_array($row) && !array_key_exists('departments', $row) && (int) ($row['id'] ?? 0) > 0) {
                $ids[] = (int) $row['id'];
            }
        }

        $areas = self::departmentsByRelation($ids);

        foreach ($rows as $key => $row) {
            if (is_array($row) && !array_key_exists('departments', $row)) {
                $rows[$key]['departments'] = $areas[(int) ($row['id'] ?? 0)] ?? [];
            }
        }

        return $rows;
    }

    /**
     * A chi scrivere per un'area e un brand: i referenti vivi che si occupano
     * di quell'area o di tutte e seguono quel brand o tutti, con la scheda
     * sotto `contact`; il principale per primo, poi nell'ordine scelto. È il
     * punto unico da cui i moduli scelgono i destinatari di un documento
     * (`receives_documents` è nella riga: lo filtra chi manda documenti).
     * Area vuota = qualunque area; brand 0 = qualunque brand.
     *
     * @return list<array<string, mixed>>
     */
    public static function peopleFor(int $companyId, string $department = '', int $brandId = 0): array
    {
        $rows = self::peopleOf($companyId, $brandId);
        $department = trim($department);

        if ($department !== '') {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => $row['departments'] === [] || in_array($department, $row['departments'], true)
            ));
        }

        // usort è stabile: fra i non principali resta l'ordine del repeater.
        usort($rows, static fn (array $a, array $b): int => (($b['is_primary'] ?? 'false') === 'true') <=> (($a['is_primary'] ?? 'false') === 'true'));

        return $rows;
    }

    /**
     * I referenti di un'azienda che si occupano di almeno una di queste aree
     * o di tutte (nessuna area), con la scheda sotto `contact`.
     *
     * @param string|list<string> $departments
     * @return list<array<string, mixed>>
     */
    public static function peopleByDepartment(int $companyId, string|array $departments): array
    {
        $wanted = array_map('strval', (array) $departments);

        return array_values(array_filter(
            self::peopleOf($companyId),
            static fn (array $row): bool => $row['departments'] === [] || array_intersect($wanted, $row['departments']) !== []
        ));
    }

    /**
     * Tutte le righe di un padre, cancellate comprese.
     *
     * @return list<array<string, mixed>>
     */
    public static function allRowsOf(string $parentColumn, int $parentId): array
    {
        return [
            ...self::rows(ContactRelation::find([$parentColumn => $parentId, 'deleted' => 'false'])),
            ...self::rows(ContactRelation::find([$parentColumn => $parentId, 'deleted' => 'true'])),
        ];
    }

    /**
     * I referenti di un'azienda, in ordine: ogni riga del legame con la
     * scheda della persona sotto `contact` e le aree in `departments`
     * (vuota = tutte; `department` è la prima, per chi leggeva il reparto).
     * I contatti di lavoro sono in `work_email`, `work_phone_prefix`,
     * `work_phone`; `contact_email` è l'email a cui scrivergli per
     * quest'azienda (di lavoro se c'è, altrimenti la sua).
     *
     * @param int $brandId se c'è, solo chi segue quel brand o tutti i brand
     * @return list<array<string, mixed>>
     */
    public static function peopleOf(int $companyId, int $brandId = 0): array
    {
        $rows = self::linked(self::COMPANY, $companyId);

        if ($brandId <= 0 || $rows === []) {
            return $rows;
        }

        $followed = self::followedBrands($rows);

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => !isset($followed[(int) $row['id']]) || in_array($brandId, $followed[(int) $row['id']], true)
        ));
    }

    /**
     * I brand che segue un referente; vuoto = tutti quelli dell'azienda.
     *
     * @return list<int>
     */
    public static function brandsOf(int $relationId): array
    {
        return self::brandsByRelation([$relationId])[$relationId] ?? [];
    }

    /**
     * I brand seguiti da più legami. I legami che seguono tutto (senza righe,
     * o con soli brand non più legati all'azienda) non compaiono.
     *
     * @param list<int> $relationIds
     * @return array<int, list<int>>
     */
    public static function brandsByRelation(array $relationIds): array
    {
        $relationIds = array_values(array_unique(array_filter(array_map('intval', $relationIds), static fn (int $id): bool => $id > 0)));

        return $relationIds === [] ? [] : self::followedBrands(self::rows(ContactRelation::find(['id' => $relationIds])));
    }

    /**
     * Toglie i brand che si staccano da un'azienda dai referenti che li
     * seguivano: lo chiama `CompanyBrands::sync()`.
     *
     * @param list<int> $brandIds
     */
    public static function dropBrands(int $companyId, array $brandIds): void
    {
        $brandIds = array_values(array_filter(array_map('intval', $brandIds), static fn (int $id): bool => $id > 0));
        $relationIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            self::rows(ContactRelation::find([self::COMPANY => $companyId, 'deleted' => ['true', 'false']]))
        );

        if ($brandIds === [] || $relationIds === []) {
            return;
        }

        foreach (self::rows(ContactRelationBrand::find(['relation_id' => $relationIds, 'brand_id' => $brandIds])) as $row) {
            ContactRelationBrand::delete((int) $row['id']);
        }
    }

    /**
     * Le righe di un repeater dei referenti con `brand_ids` (i brand seguiti),
     * per mostrarli nel form: la colonna non è di `contact_relations`.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function withBrandIds(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            if (is_array($row) && !array_key_exists('brand_ids', $row) && (int) ($row['id'] ?? 0) > 0) {
                $ids[] = (int) $row['id'];
            }
        }

        $followed = self::brandsByRelation($ids);

        foreach ($rows as $key => $row) {
            if (is_array($row) && !array_key_exists('brand_ids', $row)) {
                $rows[$key]['brand_ids'] = $followed[(int) ($row['id'] ?? 0)] ?? [];
            }
        }

        return $rows;
    }

    /**
     * Scrive i brand seguiti da un referente: solo brand legati oggi alla sua
     * azienda, gli altri si ignorano. Una lista vuota = tutti i brand.
     *
     * @param list<int|string> $brandIds
     * @return list<int> i brand rimasti
     */
    public static function setBrands(int $relationId, array $brandIds): array
    {
        $relation = $relationId > 0 ? ContactRelation::findById($relationId) : null;

        if (!is_array($relation) || !isset($relation['id']) || ($relation['deleted'] ?? 'false') !== 'false') {
            return [];
        }

        return self::writeBrands($relation, $brandIds, CompanyBrands::openIdsByCompany([(int) $relation[self::COMPANY]])[(int) $relation[self::COMPANY]] ?? []);
    }

    /**
     * Sposta i referenti da un brand a un altro: lo chiama
     * `CompanyBrands::merge()`. Chi seguiva già il brand di arrivo perde la
     * riga doppia.
     */
    public static function moveBrand(int $fromId, int $intoId): void
    {
        $rows = self::rows(ContactRelationBrand::find(['brand_id' => $fromId]));

        if ($rows === [] || $intoId <= 0) {
            return;
        }

        $taken = array_flip(array_map(
            static fn (array $row): int => (int) $row['relation_id'],
            self::rows(ContactRelationBrand::find(['brand_id' => $intoId, 'relation_id' => array_map(static fn (array $row): int => (int) $row['relation_id'], $rows)]))
        ));

        foreach ($rows as $row) {
            if (isset($taken[(int) $row['relation_id']])) {
                ContactRelationBrand::delete((int) $row['id']);
            } else {
                ContactRelationBrand::query()->Update(ContactRelationBrand::$table, ['brand_id' => $intoId], 'id', (int) $row['id']);
            }
        }
    }

    /**
     * Le aziende di una persona, in ordine, con la scheda sotto `company`.
     *
     * @param list<string>|null $access solo i legami con questi accessi (`buyer`, `admin`)
     * @return list<array<string, mixed>>
     */
    public static function companiesOf(int $personId, ?array $access = null): array
    {
        $rows = self::linked(self::PERSON, $personId);

        if ($access === null) {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => in_array($row['access'] ?? ContactRelation::ACCESS_NONE, $access, true)
        ));
    }

    /** La persona è un referente vivo dell'azienda? */
    public static function isLinked(int $personId, int $companyId): bool
    {
        return $personId > 0 && $companyId > 0
            && self::rows(ContactRelation::find([self::PERSON => $personId, self::COMPANY => $companyId, 'deleted' => 'false'], 1)) !== [];
    }

    /** L'accesso di una persona a un'azienda: `none` se non sono legate. */
    public static function accessOf(int $personId, int $companyId): string
    {
        foreach (self::rows(ContactRelation::find([self::PERSON => $personId, self::COMPANY => $companyId, 'deleted' => 'false'])) as $row) {
            return self::access($row['access'] ?? null);
        }

        return ContactRelation::ACCESS_NONE;
    }

    /**
     * Quali fra gli id indicati esistono dall'altro lato del legame e, con un
     * filtro (`useCounterpartFilter()`), sono ammessi o già legati a `$parentId`.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    public static function existingCounterparts(string $parentColumn, array $ids, int $parentId = 0): array
    {
        $model = self::counterpartModel($parentColumn);
        $out = [];

        foreach (array_unique(array_filter(array_map([self::class, 'id'], $ids))) as $id) {
            $row = $model::findById($id);

            if (is_array($row) && isset($row['id'])) {
                $out[] = $id;
            }
        }

        if (self::$counterpartFilter === null || $out === []) {
            return $out;
        }

        $keep = [...self::allowedCounterparts($parentColumn, $out), ...self::linkedIds($parentColumn, $parentId)];

        return array_values(array_filter($out, static fn (int $id): bool => in_array($id, $keep, true)));
    }

    /**
     * Crea, o riapre, il solo legame fra un'azienda e una persona, senza
     * ripassare né toccare gli altri legami dell'una o dell'altra (la
     * creazione rapida). `$values` come una riga di `sync()` (`job_title`,
     * `is_primary`, `receives_documents`, `access`, `departments`, `brand_ids`,
     * `email`, `phone_prefix`, `phone`); `access` è `none` se non c'è, e passa da `useAccessGuard()`. Un legame
     * già vivo non cambia: per modificarlo c'è `sync()`. Stessa transazione e
     * stessi eventi di `sync()` (`contact.linked` dopo il commit). Non applica
     * il filtro delle controparti: chi chiama sa già che le due schede si vedono.
     *
     * @param array<string, mixed> $values
     * @return bool il legame è vivo
     */
    public static function attach(int $companyId, int $personId, array $values = []): bool
    {
        $company = $companyId > 0 ? Company::findById($companyId) : null;
        $person = $personId > 0 ? Contact::findById($personId) : null;

        if (!is_array($company) || !isset($company['id']) || !is_array($person) || !isset($person['id'])) {
            return false;
        }

        if (self::isLinked($personId, $companyId)) {
            return true;
        }

        $values = [self::PERSON => $personId, 'access' => ContactRelation::ACCESS_NONE] + array_diff_key($values, [self::COMPANY => true, self::PERSON => true, 'id' => true]);

        $event = Transaction::run(static function () use ($companyId, $personId, $values): array {
            $existing = self::rows(ContactRelation::find([self::COMPANY => $companyId, self::PERSON => $personId, 'deleted' => ['true', 'false']]));
            $clean = self::normalize([$values], $existing, self::COMPANY, $companyId, [$personId])[0];
            $id = (int) ($clean['id'] ?? 0);
            unset($clean['id']);
            $clean[self::COMPANY] = $companyId;

            if ($id > 0) {
                $result = ContactRelation::query()->Update(ContactRelation::$table, $clean, 'id', $id);
            } else {
                $max = ContactRelation::query()->Select(ContactRelation::$table, [self::COMPANY => $companyId], 1, null, null, 'MAX(position) AS pos')->row ?? [];
                $clean['position'] = (int) ($max['pos'] ?? 0) + 1;
                $result = ContactRelation::create($clean);
                $id = (int) ($result->insert_id ?? 0);
            }

            if (!($result->success ?? false) || $id <= 0) {
                throw new \RuntimeException('Legame azienda/persona non salvato.');
            }

            if ($clean['is_primary'] === 'true') {
                self::demoteOtherPrimaries($companyId, $personId);
            }

            if (array_key_exists('departments', $values) || array_key_exists('department', $values)) {
                self::writeDepartments($id, self::cleanDepartments($values['departments'] ?? $values['department'] ?? []));
            }

            $relation = ContactRelation::findById($id);

            if (array_key_exists('brand_ids', $values) && is_array($relation)) {
                self::writeBrands($relation, is_array($values['brand_ids']) ? $values['brand_ids'] : [$values['brand_ids']], CompanyBrands::openIdsByCompany([$companyId])[$companyId] ?? []);
            }

            return self::eventPayload(is_array($relation) ? $relation : ['id' => $id] + $clean);
        });

        ContactEvents::dispatch(ContactEvents::LINKED, $event);

        return true;
    }

    /**
     * Crea al volo la persona di una riga dei referenti, se la riga non ne
     * sceglie una ma ne scrive nome o cognome. Torna la riga con `person_id`.
     *
     * Una persona creata così non ha un accesso ed è solo un referente
     * (`is_customer` falso), salvo che `$payload` dica altro. Senza
     * `new_email` prende l'email di lavoro della riga (`email`).
     *
     * @param array<string, mixed> $row
     * @param callable(array<string, mixed>): array<string, mixed>|null $payload ritocca i valori della scheda
     * @return array<string, mixed>
     */
    public static function createPerson(array $row, ?callable $payload = null): array
    {
        $name = is_scalar($row['new_name'] ?? null) ? trim((string) $row['new_name']) : '';
        $surname = is_scalar($row['new_surname'] ?? null) ? trim((string) $row['new_surname']) : '';

        if ((int) ($row[self::PERSON] ?? 0) > 0 || ($name === '' && $surname === '')) {
            return $row;
        }

        $values = [
            'is_customer' => 'false',
            'active' => 'true',
            'name' => $name,
            'surname' => $surname,
        ];
        $email = is_scalar($row['new_email'] ?? null) ? trim((string) $row['new_email']) : '';

        // Senza un'email sua, la persona prende l'email di lavoro della riga.
        if ($email === '' && is_scalar($row['email'] ?? null)) {
            $email = strtolower(trim((string) $row['email']));
        }

        // L'email è facoltativa: vuota non si manda, altrimenti la validazione la scarta.
        if ($email !== '') {
            $values['email'] = $email;
        }

        $result = Contact::create($payload !== null ? $payload($values) : $values);

        if (($result->success ?? false) && (int) ($result->insert_id ?? 0) > 0) {
            $row[self::PERSON] = (int) $result->insert_id;
            ContactEvents::dispatch(ContactEvents::CREATED, [
                'id' => $row[self::PERSON],
                'contact' => Contact::findById($row[self::PERSON]) ?: $values,
            ]);
        }

        return $row;
    }

    /**
     * L'unico punto che scrive i legami di una scheda: form, API, import e sync
     * passano da qui.
     *
     * `$rows` sono le righe grezze dell'altro lato (`person_id` o `company_id`,
     * `job_title`, `departments`, `is_primary`, `receives_documents`, `access`, `brand_ids`,
     * i contatti di lavoro `email`, `phone_prefix`, `phone` (vedi `workContacts()`); dal lato azienda anche
     * `new_name`, `new_surname`, `new_email` per creare la persona). Le righe non
     * presenti si cancellano (soft delete). Una riga senza `departments` (o
     * senza il vecchio `department`) tiene le aree di prima, come `brand_ids`.
     * I legami, i brand e le aree si scrivono in una transazione; gli eventi
     * `ContactEvents::LINKED` e `UNLINKED` partono dopo il commit. `CREATED`
     * parte per le persone nuove, create prima dei legami.
     *
     * @param list<array<string, mixed>> $rows
     * @param callable(array<string, mixed>): array<string, mixed>|null $payload ritocca la persona creata
     * @return array<string, mixed> il riepilogo di `Repeater::syncRelatedRows()`
     */
    public static function sync(string $parentColumn, int $parentId, array $rows, ?callable $payload = null): array
    {
        if ($parentId <= 0 || !in_array($parentColumn, [self::COMPANY, self::PERSON], true)) {
            return [];
        }

        $other = self::counterpart($parentColumn);
        $rows = array_values(array_filter($rows, 'is_array'));

        if ($parentColumn === self::COMPANY) {
            $rows = array_map(static fn (array $row): array => self::createPerson($row, $payload), $rows);
        }

        // Brand seguiti e aree non sono colonne del legame: si scrivono dopo, solo per le righe che li portano.
        $brands = [];
        $areas = [];

        foreach ($rows as $row) {
            $counterpartId = self::id($row[$other] ?? 0);

            if ($counterpartId <= 0) {
                continue;
            }

            if (!isset($brands[$counterpartId]) && array_key_exists('brand_ids', $row)) {
                $brands[$counterpartId] = is_array($row['brand_ids']) ? $row['brand_ids'] : [$row['brand_ids']];
            }

            // `department` (un valore) è la forma di prima: vale ancora per un'API o un import vecchi.
            if (!isset($areas[$counterpartId]) && (array_key_exists('departments', $row) || array_key_exists('department', $row))) {
                $areas[$counterpartId] = self::cleanDepartments($row['departments'] ?? $row['department'] ?? []);
            }
        }

        // Gli eventi partono dopo il commit: un ascoltatore non vede scritture poi annullate.
        [$summary, $events] = Transaction::run(static fn (): array => self::writeRelations($parentColumn, $parentId, $rows, $brands, $areas));

        foreach ($events as [$event, $payload]) {
            ContactEvents::dispatch($event, $payload);
        }

        return $summary;
    }

    /**
     * Le scritture di `sync()`, dentro la sua transazione.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<int, list<int|string>> $brands controparte => brand seguiti
     * @param array<int, list<string>> $areas controparte => aree
     * @return array{0: array<string, mixed>, 1: list<array{0: string, 1: array<string, mixed>}>} il riepilogo e gli eventi da emettere
     */
    private static function writeRelations(string $parentColumn, int $parentId, array $rows, array $brands, array $areas): array
    {
        $other = self::counterpart($parentColumn);
        $before = self::liveByCounterpart($parentColumn, $parentId);
        $valid = self::existingCounterparts($parentColumn, array_map(static fn (array $row): int => self::id($row[$other] ?? 0), $rows), $parentId);
        $clean = self::normalize($rows, self::allRowsOf($parentColumn, $parentId), $parentColumn, $parentId, $valid);
        $readonly = self::readonlyCounterparts($parentColumn, array_map(static fn (array $row): int => (int) $row[$other], $clean));

        // Una controparte che chi scrive non vede resta com'era: nessun campo del legame arriva dal POST.
        if ($readonly !== []) {
            $primaryKept = false;

            foreach ($clean as $i => $row) {
                $counterpartId = (int) $row[$other];

                if (isset($readonly[$counterpartId], $before[$counterpartId])) {
                    $clean[$i] = self::storedClean($before[$counterpartId], $other);
                    $primaryKept = $primaryKept || $clean[$i]['is_primary'] === 'true';
                    unset($brands[$counterpartId], $areas[$counterpartId]);
                }
            }

            // Dal lato azienda un principale salvato e non visibile tiene il titolo.
            if ($primaryKept && $parentColumn === self::COMPANY) {
                foreach ($clean as $i => $row) {
                    if (!isset($readonly[(int) $row[$other]])) {
                        $clean[$i]['is_primary'] = 'false';
                    }
                }
            }
        }

        $relation = RepeaterRelation::make(ContactRelation::$table, $parentColumn)
            ->model(ContactRelation::class)
            ->positionKey('position')
            ->softDelete(true);
        $summary = Repeater::syncRelatedRows($relation, $parentId, $clean);

        if ($parentColumn === self::PERSON) {
            foreach ($clean as $row) {
                if ($row['is_primary'] === 'true' && !isset($readonly[(int) $row[self::COMPANY]])) {
                    self::demoteOtherPrimaries((int) $row[self::COMPANY], $parentId);
                }
            }
        }

        $after = self::liveByCounterpart($parentColumn, $parentId);

        // I brand ammessi si leggono una volta per tutte le aziende delle righe.
        $allowed = CompanyBrands::openIdsByCompany(array_map(
            static fn (array $row): int => (int) ($row[self::COMPANY] ?? 0),
            array_intersect_key($after, $brands)
        ));

        foreach ($brands as $counterpartId => $brandIds) {
            if (isset($after[$counterpartId])) {
                self::writeBrands($after[$counterpartId], $brandIds, $allowed[(int) ($after[$counterpartId][self::COMPANY] ?? 0)] ?? []);
            }
        }

        foreach ($areas as $counterpartId => $departments) {
            if (isset($after[$counterpartId])) {
                self::writeDepartments((int) $after[$counterpartId]['id'], $departments);
            }
        }

        $events = [];

        foreach (array_diff_key($after, $before) as $row) {
            $events[] = [ContactEvents::LINKED, self::eventPayload($row)];
        }

        foreach (array_diff_key($before, $after) as $row) {
            $events[] = [ContactEvents::UNLINKED, self::eventPayload(['deleted' => 'true'] + $row)];
        }

        return [$summary, $events];
    }

    /**
     * Quando una persona diventa il referente principale di un'azienda dal
     * lato delle sue aziende, le altre righe di quell'azienda smettono di esserlo.
     */
    public static function demoteOtherPrimaries(int $companyId, int $personId): void
    {
        foreach (self::rows(ContactRelation::find([self::COMPANY => $companyId, 'is_primary' => 'true'])) as $row) {
            if ((int) ($row[self::PERSON] ?? 0) !== $personId && isset($row['id'])) {
                ContactRelation::query()->Update(ContactRelation::$table, ['is_primary' => 'false'], 'id', (int) $row['id']);
            }
        }
    }

    /**
     * @param array<string, mixed> $relation la riga viva del legame
     * @param list<int|string> $brandIds
     * @param list<int> $allowed i brand legati oggi all'azienda del legame
     * @return list<int>
     */
    private static function writeBrands(array $relation, array $brandIds, array $allowed): array
    {
        $wanted = array_values(array_intersect(array_unique(array_map([self::class, 'id'], $brandIds)), $allowed));
        $current = [];

        foreach (self::rows(ContactRelationBrand::find(['relation_id' => (int) $relation['id']])) as $row) {
            $current[(int) $row['brand_id']] = (int) $row['id'];
        }

        foreach ($wanted as $brandId) {
            if (!isset($current[$brandId])) {
                ContactRelationBrand::create(['relation_id' => (int) $relation['id'], 'brand_id' => $brandId]);
            }
        }

        foreach ($current as $brandId => $rowId) {
            if (!in_array($brandId, $wanted, true)) {
                ContactRelationBrand::delete($rowId);
            }
        }

        return $wanted;
    }

    /**
     * @param list<string> $departments già ripulite da `cleanDepartments()`
     * @return list<string>
     */
    private static function writeDepartments(int $relationId, array $departments): array
    {
        $current = [];

        foreach (self::rows(ContactRelationDepartment::find(['relation_id' => $relationId])) as $row) {
            $current[(string) $row['department']] = (int) $row['id'];
        }

        foreach ($departments as $department) {
            if (!isset($current[$department])) {
                $result = ContactRelationDepartment::create(['relation_id' => $relationId, 'department' => $department]);

                if (!($result->success ?? false)) {
                    throw new \RuntimeException('Area del referente non salvata.');
                }
            }
        }

        foreach ($current as $department => $rowId) {
            if (!in_array($department, $departments, true)) {
                ContactRelationDepartment::delete($rowId);
            }
        }

        return $departments;
    }

    /**
     * I brand seguiti dai legami dati, ristretti a quelli ancora legati alla
     * loro azienda; un legame che resta senza brand li segue tutti e non compare.
     *
     * @param list<array<string, mixed>> $relations righe di `contact_relations`
     * @return array<int, list<int>>
     */
    private static function followedBrands(array $relations): array
    {
        $companyOf = [];

        foreach ($relations as $relation) {
            if ((int) ($relation['id'] ?? 0) > 0) {
                $companyOf[(int) $relation['id']] = (int) ($relation[self::COMPANY] ?? 0);
            }
        }

        if ($companyOf === []) {
            return [];
        }

        $stored = [];

        foreach (self::rows(ContactRelationBrand::find(['relation_id' => array_keys($companyOf)], null, 'id', 'ASC')) as $row) {
            $stored[(int) $row['relation_id']][] = (int) $row['brand_id'];
        }

        if ($stored === []) {
            return [];
        }

        $open = CompanyBrands::openIdsByCompany(array_values(array_intersect_key($companyOf, $stored)));
        $out = [];

        foreach ($stored as $relationId => $brandIds) {
            $kept = array_values(array_intersect($brandIds, $open[$companyOf[$relationId]] ?? []));

            if ($kept !== []) {
                $out[$relationId] = $kept;
            }
        }

        return $out;
    }

    /**
     * Le controparti delle righe che chi scrive non vede: con un filtro
     * (`useCounterpartFilter()`) quelle non ammesse. Sono già legate
     * (`existingCounterparts()` scarta le altre): il loro legame è in sola
     * lettura. Senza filtro nessuna.
     *
     * @param list<int> $ids
     * @return array<int, true>
     */
    private static function readonlyCounterparts(string $parentColumn, array $ids): array
    {
        if (self::$counterpartFilter === null || $ids === []) {
            return [];
        }

        return array_fill_keys(array_values(array_diff($ids, self::allowedCounterparts($parentColumn, $ids))), true);
    }

    /**
     * Una riga salvata nella forma di `normalize()`, per un legame in sola lettura.
     *
     * @return array<string, mixed>
     */
    private static function storedClean(array $stored, string $other): array
    {
        return [
            'id' => (int) $stored['id'],
            $other => (int) $stored[$other],
            'job_title' => (string) ($stored['job_title'] ?? ''),
            'is_primary' => self::flag($stored['is_primary'] ?? 'false'),
            'receives_documents' => self::flag($stored['receives_documents'] ?? 'false'),
            'access' => self::access($stored['access'] ?? null),
            'email' => (string) ($stored['email'] ?? ''),
            'phone_prefix' => (string) ($stored['phone_prefix'] ?? ''),
            'phone' => (string) ($stored['phone'] ?? ''),
            'deleted' => 'false',
        ];
    }

    /** @return array<int, array<string, mixed>> le righe vive per id della controparte */
    private static function liveByCounterpart(string $parentColumn, int $parentId): array
    {
        $other = self::counterpart($parentColumn);
        $out = [];

        foreach (self::rows(ContactRelation::find([$parentColumn => $parentId, 'deleted' => 'false'])) as $row) {
            $out[(int) ($row[$other] ?? 0)] = $row;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function eventPayload(array $row): array
    {
        return [
            self::COMPANY => (int) ($row[self::COMPANY] ?? 0),
            self::PERSON => (int) ($row[self::PERSON] ?? 0),
            'relation' => $row,
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function linked(string $parentColumn, int $parentId): array
    {
        if ($parentId <= 0) {
            return [];
        }

        $other = self::counterpart($parentColumn);
        $model = self::counterpartModel($parentColumn);
        $key = $parentColumn === self::COMPANY ? 'contact' : 'company';
        $relations = self::rows(ContactRelation::find([$parentColumn => $parentId], null, 'position', 'ASC'));
        $areas = self::departmentsByRelation(array_map(static fn (array $row): int => (int) $row['id'], $relations));
        // Dal lato della persona l'email di ripiego è la sua: si legge una volta.
        $person = $parentColumn === self::PERSON && $relations !== [] ? Contact::findById($parentId) : null;
        $out = [];

        foreach ($relations as $row) {
            $record = $model::findById((int) ($row[$other] ?? 0));

            if (is_array($record) && isset($record['id'])) {
                $departments = $areas[(int) $row['id']] ?? [];
                $personEmail = (string) (($parentColumn === self::COMPANY ? $record : $person)['email'] ?? '');
                $out[] = ['departments' => $departments, 'department' => $departments[0] ?? ''] + self::workFields($row, $personEmail) + $row + [$key => $record];
            }
        }

        return $out;
    }

    /**
     * I contatti di lavoro di un legame per chi legge: `work_email`,
     * `work_phone_prefix`, `work_phone` e `contact_email`, l'email da usare
     * per scrivere al referente per quell'azienda (quella di lavoro, se c'è,
     * altrimenti la sua).
     *
     * @return array{work_email: string, work_phone_prefix: string, work_phone: string, contact_email: string}
     */
    private static function workFields(array $relation, string $personEmail): array
    {
        $email = (string) ($relation['email'] ?? '');

        return [
            'work_email' => $email,
            'work_phone_prefix' => (string) ($relation['phone_prefix'] ?? ''),
            'work_phone' => (string) ($relation['phone'] ?? ''),
            'contact_email' => $email !== '' ? $email : strtolower(trim($personEmail)),
        ];
    }

    /** Un id da un valore della richiesta: un valore non scalare (`x[]=1`) vale 0. */
    private static function id(mixed $value): int
    {
        return is_int($value) || (is_string($value) && preg_match('/^\s*\d+\s*$/', $value) === 1) ? (int) $value : 0;
    }

    /** Il peso di un accesso: `none` < `buyer` < `admin`. */
    private static function rank(string $access): int
    {
        return (int) array_search($access, ContactRelation::ACCESS, true);
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
