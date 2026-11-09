<?php

namespace Wonder\Plugin\CustomerAccount\Support;

use Throwable;
use Wonder\App\Models\System\ExternalReference;

/**
 * La parte comune di `ContactRecord` e `CompanyRecord`: la forma stabile di
 * una scheda per export, import e sync verso un CRM o un sistema di newsletter.
 *
 * `from()` legge la scheda e la porta in un array serializzabile (versione in
 * `schema`): i campi della scheda, i legami azienda/persona visti dal suo
 * lato, gli id esterni di `external_references`. `fromArray()` e `validate()`
 * fanno il viaggio inverso per un import. Nessuna scrittura qui: i legami si
 * salvano con `ContactRelations::sync()`.
 */
abstract class PartyRecord
{
    public const SCHEMA = 2;

    /** L'entità in `external_references` (`contact`, `company`). */
    abstract public static function entity(): string;

    /** @return class-string<\Wonder\App\Model> */
    abstract protected static function model(): string;

    /** @return list<string> */
    abstract public static function fields(): array;

    /** Il lato della scheda nei legami (`ContactRelations::PERSON` o `COMPANY`). */
    abstract protected static function side(): string;

    /** @return array<string, string> campo → codice, per i campi obbligatori del tipo */
    abstract protected static function requiredErrors(array $record): array;

    /**
     * @param array<string, mixed>|int $row la scheda o il suo id
     * @return array<string, mixed>|null null se la scheda non esiste
     */
    public static function from(array|int $row): ?array
    {
        if (is_int($row)) {
            $model = static::model();
            $row = $model::findById($row);
        }

        if (!is_array($row) || (int) ($row['id'] ?? 0) <= 0) {
            return null;
        }

        $id = (int) $row['id'];
        $record = ['schema' => static::SCHEMA, 'entity' => static::entity(), 'id' => $id, 'code' => (string) ($row['code'] ?? '')];

        foreach (static::fields() as $field) {
            $record[$field] = isset($row[$field]) ? (string) $row[$field] : '';
        }

        $record['is_customer'] = ($row['is_customer'] ?? 'true') === 'true';
        $record['active'] = ($row['active'] ?? 'true') === 'true';
        $record['relations'] = self::relations($id);
        $record['external_ids'] = self::externalIds($id);

        return static::extra($record, $row);
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

        foreach (static::fields() as $field) {
            if (array_key_exists($field, $input)) {
                $out[$field] = trim((string) $input[$field]);
            }
        }

        foreach (['email', 'pec'] as $field) {
            if (isset($out[$field])) {
                $out[$field] = strtolower($out[$field]);
            }
        }

        foreach (['relations', 'external_ids'] as $key) {
            if (isset($input[$key]) && is_array($input[$key])) {
                $out[$key] = array_values(array_filter($input[$key], 'is_array'));
            }
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
        $errors = static::requiredErrors($record);

        foreach (['email', 'pec'] as $field) {
            $value = (string) ($record[$field] ?? '');
            if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                $errors[$field] = 'invalid';
            }
        }

        $counterpart = ContactRelations::counterpart(static::side());

        foreach ((array) ($record['relations'] ?? []) as $i => $relation) {
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

    /**
     * I campi in più di una sottoclasse.
     *
     * @param array<string, mixed> $record
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected static function extra(array $record, array $row): array
    {
        return $record;
    }

    /** @return list<array<string, mixed>> */
    private static function relations(int $id): array
    {
        $side = static::side();
        $other = ContactRelations::counterpart($side);
        $rows = $side === ContactRelations::COMPANY ? ContactRelations::peopleOf($id) : ContactRelations::companiesOf($id);
        $out = [];

        foreach ($rows as $row) {
            if (($row['deleted'] ?? 'false') !== 'false') {
                continue;
            }

            $out[] = [
                $other => (int) ($row[$other] ?? 0),
                'job_title' => (string) ($row['job_title'] ?? ''),
                'is_primary' => ($row['is_primary'] ?? 'false') === 'true',
                'receives_documents' => ($row['receives_documents'] ?? 'false') === 'true',
                'access' => ContactRelations::access($row['access'] ?? null),
            ];
        }

        return $out;
    }

    /** @return list<array<string, string>> */
    private static function externalIds(int $id): array
    {
        try {
            $rows = ExternalReference::find(['entity_type' => static::entity(), 'entity_id' => $id]);
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
}
