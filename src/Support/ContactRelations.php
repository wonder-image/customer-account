<?php

namespace Wonder\Plugin\CustomerAccount\Support;

use Wonder\App\Support\Repeater;
use Wonder\App\ResourceSchema\RepeaterRelation;
use Wonder\Plugin\CustomerAccount\Models\Company;
use Wonder\Plugin\CustomerAccount\Models\Contact;
use Wonder\Plugin\CustomerAccount\Models\ContactRelation;

/**
 * I legami fra aziende (`companies`) e persone (`contacts`), in `contact_relations`.
 *
 * Una persona può essere referente di più aziende, un'azienda può avere più
 * referenti. `access` dice cosa può fare la persona per l'azienda nell'area
 * cliente. Sempre attivi: `enabled()` resta per chi lo chiama e per i test.
 */
final class ContactRelations
{
    public const COMPANY = 'company_id';
    public const PERSON = 'person_id';

    private static ?bool $override = null;

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
     * riga non porta `access`, resta quello di prima (`none` per le nuove).
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
     * @return list<array<string, mixed>>
     */
    public static function peopleOf(int $companyId): array
    {
        return self::linked(self::COMPANY, $companyId);
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
     * `job_title`, `is_primary`, `receives_documents`, `access`; dal lato azienda anche
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
