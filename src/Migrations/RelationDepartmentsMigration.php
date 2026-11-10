<?php

namespace Wonder\Plugin\CustomerAccount\Migrations;

use mysqli;
use RuntimeException;
use Throwable;
use Wonder\App\ModelRegistry;
use Wonder\App\Module\Contracts\ModuleMigration;
use Wonder\Plugin\CustomerAccount\Models\ContactRelation;
use Wonder\Plugin\CustomerAccount\Models\ContactRelationDepartment;

/**
 * Copia il vecchio reparto singolo (`contact_relations.department`) nelle aree
 * del referente (`contact_relation_departments`, più righe per legame).
 *
 * Gira prima di `runTables()`, che poi toglie la colonna dallo schema: per
 * questo crea da sé la tabella nuova e copia adesso. Un legame che ha già
 * delle aree non si tocca, così rilanciata (anche dopo un `forge update`
 * interrotto) non fa niente; senza la colonna non c'è niente da fare.
 */
final class RelationDepartmentsMigration implements ModuleMigration
{
    public static function run(mysqli $connection): array
    {
        $table = ContactRelation::$table;
        $exists = $connection->query("SHOW TABLES LIKE '".$connection->real_escape_string($table)."'");

        // Installazione nuova: le tabelle le crea runTables().
        if (!$exists || $exists->num_rows === 0) {
            return [];
        }

        $result = $connection->query("SHOW COLUMNS FROM `{$table}` LIKE 'department'");

        if (!$result || $result->num_rows === 0) {
            return [];
        }

        self::syncTable();
        $copied = self::backfill();

        return $copied === 0 ? [] : ['copied' => $copied];
    }

    /**
     * Una riga di `contact_relation_departments` per ogni legame con un reparto,
     * cancellati compresi (un legame ripreso torna con la sua area).
     *
     * @return int quante aree ha scritto
     */
    public static function backfill(): int
    {
        try {
            $relations = ContactRelation::find(['deleted' => ['true', 'false']], null, 'id', 'ASC');
            $stored = ContactRelationDepartment::find(['deleted' => ['true', 'false']], null, null, null, ['relation_id']);
        } catch (Throwable) {
            return 0;
        }

        $done = [];

        foreach (self::rows($stored) as $row) {
            $done[(int) $row['relation_id']] = true;
        }

        $copied = 0;

        foreach (self::rows($relations) as $row) {
            $department = strtolower(trim((string) ($row['department'] ?? '')));

            // Il valore lo aveva già validato il vecchio select: si copia così com'è.
            if ($department === '' || isset($done[(int) $row['id']]) || preg_match('/^[a-z0-9_]{1,40}$/', $department) !== 1) {
                continue;
            }

            $result = ContactRelationDepartment::create(['relation_id' => (int) $row['id'], 'department' => $department]);

            if (!($result->success ?? false)) {
                throw new RuntimeException('Area del referente '.(int) $row['id'].' non copiata.');
            }

            $done[(int) $row['id']] = true;
            $copied++;
        }

        return $copied;
    }

    /** Crea la tabella delle aree com'è nel Model registrato (anche se un modulo lo estende). */
    private static function syncTable(): void
    {
        global $TABLE;

        $table = ContactRelationDepartment::$table;
        $model = ModelRegistry::all()[$table] ?? ContactRelationDepartment::class;
        $schema = isset($TABLE->{$table}) && is_array($TABLE->{$table}) ? $TABLE->{$table} : $model::rawTableSchema();

        if (!function_exists('sqlTable')) {
            throw new RuntimeException('sqlTable() non disponibile: la migrazione gira con forge update.');
        }

        sqlTable($table, $schema);
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) || isset($rows['relation_id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
