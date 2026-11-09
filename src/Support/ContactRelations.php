<?php

namespace Wonder\Plugin\CustomerAccount\Support;

use Wonder\App\Support\Repeater;
use Wonder\App\ResourceSchema\RepeaterRelation;
use Wonder\Plugin\CustomerAccount\Models\Company;
use Wonder\Plugin\CustomerAccount\Models\Contact;
use Wonder\Plugin\CustomerAccount\Models\ContactRelation;
use Wonder\Plugin\CustomerAccount\Models\ContactRelationBrand;

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
 */
final class ContactRelations
{
    public const COMPANY = 'company_id';
    public const PERSON = 'person_id';

    private static ?bool $override = null;

    /** @var array<string, string> reparti aggiunti da moduli e siti: chiave => etichetta */
    private static array $extraDepartments = [];

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
     * salta e la riga torna viva. Al massimo un referente principale. Se la
     * riga non porta `access` o `department`, resta quello di prima.
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
            $counterpartId = (int) ($row[$other] ?? 0);

            if ($counterpartId <= 0 || isset($seen[$counterpartId]) || !isset($valid[$counterpartId])) {
                continue;
            }

            $seen[$counterpartId] = true;
            $clean = [
                $other => $counterpartId,
                'job_title' => trim((string) ($row['job_title'] ?? '')),
                'is_primary' => self::flag($row['is_primary'] ?? 'false'),
                'receives_documents' => self::flag($row['receives_documents'] ?? 'false'),
                'access' => self::access($row['access'] ?? $existingByCounterpart[$counterpartId]['access'] ?? null),
                'department' => self::department($row['department'] ?? $existingByCounterpart[$counterpartId]['department'] ?? ''),
                'deleted' => 'false',
            ];

            if ($clean['is_primary'] === 'true') {
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
     * I reparti del referente per un select: quelli di base e quelli aggiunti.
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
     * Aggiunge un reparto (es. `marketing`) per i select, le email e i sync.
     * Un modulo lo chiama al boot, un sito in `custom/function`.
     */
    public static function addDepartment(string $key, string $label): void
    {
        $key = strtolower(trim($key));

        if (preg_match('/^[a-z0-9_]{1,40}$/', $key) === 1) {
            self::$extraDepartments[$key] = $label;
        }
    }

    /** Un reparto valido, altrimenti vuoto (nessun reparto). */
    public static function department(mixed $value): string
    {
        $value = trim((string) $value);

        return array_key_exists($value, self::departments()) ? $value : '';
    }

    /** L'etichetta di un reparto; vuota se non c'è. */
    public static function departmentLabel(mixed $value): string
    {
        return self::departments()[self::department($value)] ?? '';
    }

    /**
     * I referenti di un'azienda in uno o più reparti, con la scheda sotto
     * `contact`: il punto da cui email, fatture e newsletter scelgono a chi
     * scrivere (es. `administration` per le fatture).
     *
     * @param string|list<string> $departments
     * @return list<array<string, mixed>>
     */
    public static function peopleByDepartment(int $companyId, string|array $departments): array
    {
        $wanted = array_map('strval', (array) $departments);

        return array_values(array_filter(
            self::peopleOf($companyId),
            static fn (array $row): bool => in_array((string) ($row['department'] ?? ''), $wanted, true)
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
     * scheda della persona sotto `contact`.
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
     * Quali fra gli id indicati esistono dall'altro lato del legame.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    public static function existingCounterparts(string $parentColumn, array $ids): array
    {
        $model = self::counterpartModel($parentColumn);
        $out = [];

        foreach (array_unique(array_filter(array_map('intval', $ids))) as $id) {
            $row = $model::findById($id);

            if (is_array($row) && isset($row['id'])) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * Crea al volo la persona di una riga dei referenti, se la riga non ne
     * sceglie una ma ne scrive nome o cognome. Torna la riga con `person_id`.
     *
     * Una persona creata così è solo un referente: non è cliente
     * (`is_customer` falso) e non ha un accesso.
     *
     * @param array<string, mixed> $row
     * @param callable(array<string, mixed>): array<string, mixed>|null $payload ritocca i valori della scheda
     * @return array<string, mixed>
     */
    public static function createPerson(array $row, ?callable $payload = null): array
    {
        $name = trim((string) ($row['new_name'] ?? ''));
        $surname = trim((string) ($row['new_surname'] ?? ''));

        if ((int) ($row[self::PERSON] ?? 0) > 0 || ($name === '' && $surname === '')) {
            return $row;
        }

        $values = [
            'is_customer' => 'false',
            'active' => 'true',
            'name' => $name,
            'surname' => $surname,
        ];
        $email = trim((string) ($row['new_email'] ?? ''));

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
     * `job_title`, `department`, `is_primary`, `receives_documents`, `access`, `brand_ids`; dal lato azienda anche
     * `new_name`, `new_surname`, `new_email` per creare la persona). Le righe non
     * presenti si cancellano (soft delete). Emette `ContactEvents::LINKED` e
     * `UNLINKED` per le coppie che cambiano, `CREATED` per le persone nuove.
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

        if ($parentColumn === self::COMPANY) {
            $rows = array_map(static fn (array $row): array => self::createPerson($row, $payload), $rows);
        }

        // I brand seguiti non sono colonne del legame: si scrivono dopo, solo per le righe che li portano.
        $brands = [];

        foreach ($rows as $row) {
            $counterpartId = (int) ($row[$other] ?? 0);

            if ($counterpartId > 0 && !isset($brands[$counterpartId]) && array_key_exists('brand_ids', $row)) {
                $brands[$counterpartId] = is_array($row['brand_ids']) ? $row['brand_ids'] : [$row['brand_ids']];
            }
        }

        $before = self::liveByCounterpart($parentColumn, $parentId);
        $valid = self::existingCounterparts($parentColumn, array_map(static fn (array $row): int => (int) ($row[$other] ?? 0), $rows));
        $clean = self::normalize($rows, self::allRowsOf($parentColumn, $parentId), $parentColumn, $parentId, $valid);
        $relation = RepeaterRelation::make(ContactRelation::$table, $parentColumn)
            ->model(ContactRelation::class)
            ->positionKey('position')
            ->softDelete(true);
        $summary = Repeater::syncRelatedRows($relation, $parentId, $clean);

        if ($parentColumn === self::PERSON) {
            foreach ($clean as $row) {
                if ($row['is_primary'] === 'true') {
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

        foreach (array_diff_key($after, $before) as $row) {
            ContactEvents::dispatch(ContactEvents::LINKED, self::eventPayload($row));
        }

        foreach (array_diff_key($before, $after) as $row) {
            ContactEvents::dispatch(ContactEvents::UNLINKED, self::eventPayload(['deleted' => 'true'] + $row));
        }

        return $summary;
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
        $wanted = array_values(array_intersect(array_unique(array_map('intval', $brandIds)), $allowed));
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
        $out = [];

        foreach (self::rows(ContactRelation::find([$parentColumn => $parentId], null, 'position', 'ASC')) as $row) {
            $record = $model::findById((int) ($row[$other] ?? 0));

            if (is_array($record) && isset($record['id'])) {
                $out[] = $row + [$key => $record];
            }
        }

        return $out;
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
