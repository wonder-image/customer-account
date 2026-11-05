<?php

namespace Wonder\Plugin\CustomerAccount\Support;

use Throwable;
use Wonder\App\Models\System\ExternalReference;
use Wonder\Consent\Repository\UserConsentStateRepository;
use Wonder\Plugin\CustomerAccount\Models\Contact;

/**
 * La forma stabile di una scheda contatto per export, import e sync verso un
 * CRM o un sistema di newsletter.
 *
 * `from()` legge la scheda e la porta in un array serializzabile (versione in
 * `schema`): i campi di `ContactExport::FIELDS`, i legami azienda/persona,
 * gli id esterni di `external_references` e lo stato dei consensi dell'utente
 * collegato. `fromArray()` e `validate()` fanno il viaggio inverso per un
 * import: stessi campi, stesse regole. Nessuna scrittura qui: i legami si
 * salvano con `ContactRelations::sync()`.
 */
final class ContactRecord
{
    public const SCHEMA = 1;

    public const TYPES = ['private', 'business'];

    /** @var null|callable(int): array<string, string> */
    private static $consents = null;

    /**
     * @param array<string, mixed>|int $contact la scheda o il suo id
     * @return array<string, mixed>|null null se la scheda non esiste
     */
    public static function from(array|int $contact): ?array
    {
        if (is_int($contact)) {
            $contact = Contact::findById($contact);
        }

        if (!is_array($contact) || (int) ($contact['id'] ?? 0) <= 0) {
            return null;
        }

        $id = (int) $contact['id'];
        $record = ['schema' => self::SCHEMA, 'id' => $id];

        foreach (ContactExport::FIELDS as $field) {
            $record[$field] = isset($contact[$field]) ? (string) $contact[$field] : '';
        }

        $record['active'] = ($contact['active'] ?? 'true') === 'true';
        $record['relations'] = self::relations($id, $record['type']);
        $record['external_ids'] = self::externalIds($id);
        $record['consents'] = self::consents((int) ($contact['user_id'] ?? 0));

        return $record;
    }

    /**
     * Un record in ingresso (file, API, CRM) ridotto ai campi noti, ripuliti.
     * Le chiavi assenti restano assenti: un import parziale non svuota la scheda.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function fromArray(array $input): array
    {
        $out = [];

        if (isset($input['id']) && (int) $input['id'] > 0) {
            $out['id'] = (int) $input['id'];
        }

        foreach (ContactExport::FIELDS as $field) {
            if (array_key_exists($field, $input)) {
                $out[$field] = trim((string) $input[$field]);
            }
        }

        if (isset($out['type'])) {
            $out['type'] = strtolower($out['type']);
        }

        if (isset($out['email'])) {
            $out['email'] = strtolower($out['email']);
        }

        if (isset($input['relations']) && is_array($input['relations'])) {
            $out['relations'] = array_values(array_filter($input['relations'], 'is_array'));
        }

        if (isset($input['external_ids']) && is_array($input['external_ids'])) {
            $out['external_ids'] = array_values(array_filter($input['external_ids'], 'is_array'));
        }

        return $out;
    }

    /**
     * Gli errori di un record normalizzato, campo → codice. Vuoto se va bene.
     *
     * @param array<string, mixed> $record
     * @return array<string, string>
     */
    public static function validate(array $record): array
    {
        $errors = [];
        $type = (string) ($record['type'] ?? '');

        if (!in_array($type, self::TYPES, true)) {
            $errors['type'] = 'invalid';
        }

        if ($type === 'business' && trim((string) ($record['business_name'] ?? '')) === '') {
            $errors['business_name'] = 'required';
        }

        if ($type === 'private' && trim((string) ($record['name'] ?? '').($record['surname'] ?? '')) === '') {
            $errors['name'] = 'required';
        }

        foreach (['email', 'pec'] as $field) {
            $value = (string) ($record[$field] ?? '');
            if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                $errors[$field] = 'invalid';
            }
        }

        foreach ((array) ($record['relations'] ?? []) as $i => $relation) {
            $counterpart = $type === 'business' ? ContactRelations::PERSON : ContactRelations::COMPANY;
            if ((int) ($relation[$counterpart] ?? 0) <= 0) {
                $errors['relations.'.$i] = 'invalid';
            }
        }

        foreach ((array) ($record['external_ids'] ?? []) as $i => $reference) {
            if (trim((string) ($reference['provider'] ?? '')) === '' || trim((string) ($reference['external_id'] ?? '')) === '') {
                $errors['external_ids.'.$i] = 'invalid';
            }
        }

        return $errors;
    }

    /** Solo per i test: sostituisce la lettura dei consensi (`null` torna al repository). */
    public static function useConsents(?callable $reader): void
    {
        self::$consents = $reader;
    }

    /** @return list<array<string, mixed>> */
    private static function relations(int $id, string $type): array
    {
        $side = $type === 'business' ? ContactRelations::PERSON : ContactRelations::COMPANY;
        $rows = $type === 'business' ? ContactRelations::peopleOf($id) : ContactRelations::companiesOf($id);
        $out = [];

        foreach ($rows as $row) {
            if (($row['deleted'] ?? 'false') !== 'false') {
                continue;
            }

            $out[] = [
                $side => (int) ($row[$side] ?? 0),
                'job_title' => (string) ($row['job_title'] ?? ''),
                'is_primary' => ($row['is_primary'] ?? 'false') === 'true',
                'receives_documents' => ($row['receives_documents'] ?? 'false') === 'true',
            ];
        }

        return $out;
    }

    /** @return list<array<string, string>> */
    private static function externalIds(int $id): array
    {
        try {
            $rows = ExternalReference::find(['entity_type' => Contact::$table, 'entity_id' => $id]);
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $rows = isset($rows['id']) ? [$rows] : array_filter($rows, 'is_array');
        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'provider' => (string) ($row['provider'] ?? ''),
                'environment' => (string) ($row['environment'] ?? ''),
                'object_type' => (string) ($row['object_type'] ?? ''),
                'external_id' => (string) ($row['external_id'] ?? ''),
                'synced_at' => (string) ($row['synced_at'] ?? ''),
            ];
        }

        return $out;
    }

    /** @return array<string, string> tipo di consenso → stato corrente */
    private static function consents(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        try {
            if (self::$consents !== null) {
                return (self::$consents)($userId);
            }

            $out = [];
            foreach ((new UserConsentStateRepository())->getByUserId($userId) as $row) {
                $out[(string) $row['consent_type']] = (string) $row['current_status'];
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }
}
