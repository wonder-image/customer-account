<?php

namespace Wonder\Plugin\CustomerAccount\Support;

use Wonder\Plugin\CustomerAccount\CustomerAccount;
use Wonder\Plugin\CustomerAccount\Models\Contact;
use Wonder\Plugin\CustomerAccount\Models\ContactRelation;

/**
 * I legami azienda/persona della rubrica (`contact_relations`).
 *
 * Una persona può essere referente di più aziende, un'azienda può avere più
 * referenti. Si attiva con `relations.enabled` nella configurazione del modulo:
 * spento, nessun form, lista o documento ne parla.
 */
final class ContactRelations
{
    public const COMPANY = 'company_id';
    public const PERSON = 'person_id';

    private static ?bool $override = null;

    public static function enabled(): bool
    {
        return self::$override ?? (bool) CustomerAccount::config('relations.enabled', false);
    }

    /** Solo per i test: forza l'interruttore (`null` torna alla configurazione). */
    public static function useEnabled(?bool $enabled): void
    {
        self::$override = $enabled;
    }

    /** La colonna dell'altro lato del legame. */
    public static function counterpart(string $parentColumn): string
    {
        return $parentColumn === self::COMPANY ? self::PERSON : self::COMPANY;
    }

    /** Il tipo di scheda che sta dall'altro lato: un'azienda ha persone, una persona aziende. */
    public static function counterpartType(string $parentColumn): string
    {
        return $parentColumn === self::COMPANY ? 'private' : 'business';
    }

    /**
     * Le righe postate da un repeater, pronte per `Repeater::syncRelatedRows()`.
     *
     * Toglie le righe senza controparte, i legami con sé stessi, le schede del
     * tipo sbagliato e i doppioni (vince la prima). Una coppia che esiste già,
     * anche cancellata, riprende l'id della sua riga: così la chiave unica
     * azienda/persona non salta e la riga torna viva. Al massimo un referente
     * principale.
     *
     * @param list<array<string, mixed>> $posted
     * @param list<array<string, mixed>> $existing tutte le righe del padre, cancellate comprese
     * @param array<int|string, string> $typesById tipo (`business`/`private`) delle controparti
     * @return list<array<string, mixed>>
     */
    public static function normalize(array $posted, array $existing, string $parentColumn, int $parentId, array $typesById): array
    {
        $other = self::counterpart($parentColumn);
        $type = self::counterpartType($parentColumn);
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

            if ($counterpartId <= 0 || $counterpartId === $parentId || isset($seen[$counterpartId])
                || ($typesById[$counterpartId] ?? null) !== $type) {
                continue;
            }

            $seen[$counterpartId] = true;
            $clean = [
                $other => $counterpartId,
                'job_title' => trim((string) ($row['job_title'] ?? '')),
                'is_primary' => self::flag($row['is_primary'] ?? 'false'),
                'receives_documents' => self::flag($row['receives_documents'] ?? 'false'),
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
     * I referenti vivi di un'azienda, in ordine: ogni riga del legame con la
     * scheda della persona sotto `contact`.
     *
     * @return list<array<string, mixed>>
     */
    public static function peopleOf(int $companyId): array
    {
        return self::linked(self::COMPANY, $companyId);
    }

    /**
     * Le aziende vive di una persona, in ordine, con la scheda sotto `contact`.
     *
     * @return list<array<string, mixed>>
     */
    public static function companiesOf(int $personId): array
    {
        return self::linked(self::PERSON, $personId);
    }

    /**
     * Il tipo (`business`/`private`) di ogni scheda viva fra quelle indicate.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    public static function typesOf(array $ids): array
    {
        $types = [];

        foreach (array_unique(array_filter(array_map('intval', $ids))) as $id) {
            $contact = Contact::findById($id);

            if (is_array($contact) && isset($contact['id'])) {
                $types[$id] = (string) ($contact['type'] ?? 'private');
            }
        }

        return $types;
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
            'type' => 'private',
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
        }

        return $row;
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

    /** @return list<array<string, mixed>> */
    private static function linked(string $parentColumn, int $parentId): array
    {
        if ($parentId <= 0) {
            return [];
        }

        $other = self::counterpart($parentColumn);
        $out = [];

        foreach (self::rows(ContactRelation::find([$parentColumn => $parentId], null, 'position', 'ASC')) as $row) {
            $contact = Contact::findById((int) ($row[$other] ?? 0));

            if (is_array($contact) && isset($contact['id'])) {
                $out[] = $row + ['contact' => $contact];
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
