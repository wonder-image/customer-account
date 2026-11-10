<?php

namespace Wonder\Plugin\CustomerAccount\Migrations;

use mysqli;
use RuntimeException;
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
        self::assertAllCopied($connection);

        return $copied === 0 ? [] : ['copied' => $copied];
    }

    /**
     * Una riga di `contact_relation_departments` per ogni legame con un reparto,
     * cancellati compresi (un legame ripreso torna con la sua area).
     *
     * Un reparto si normalizza (minuscole, spazi e trattini → `_`); se resta
     * fuori formato diventa `other`: un'area generica, mai «nessuna area»,
     * che vorrebbe dire «si occupa di tutto». Ogni errore (una lettura o una
     * scrittura fallita, un legame rimasto senza area) lancia: l'aggiornamento
     * si ferma prima che `runTables()` tolga la colonna.
     *
     * @return int quante aree ha scritto
     */
    public static function backfill(): int
    {
        $relations = self::select(ContactRelation::$table, ['deleted' => ['true', 'false']], 'id', ['id', 'department']);
        $done = self::migratedIds();
        $copied = 0;

        foreach ($relations as $row) {
            $department = self::normalize($row['department'] ?? '');

            if ($department === '' || isset($done[(int) $row['id']])) {
                continue;
            }

            $result = ContactRelationDepartment::create(['relation_id' => (int) $row['id'], 'department' => $department]);

            if (!($result->success ?? false)) {
                throw new RuntimeException('Area del referente '.(int) $row['id'].' non copiata.');
            }

            $done[(int) $row['id']] = true;
            $copied++;
        }

        // Ricontrollo sulle righe scritte davvero: nessun reparto resta senza area.
        $done = self::migratedIds();

        foreach ($relations as $row) {
            if (self::normalize($row['department'] ?? '') !== '' && !isset($done[(int) $row['id']])) {
                throw new RuntimeException('Il legame '.(int) $row['id'].' ha un reparto ma nessuna area: la colonna `department` non si può togliere.');
            }
        }

        return $copied;
    }

    /**
     * Il reparto di prima nella forma di un'area: vuoto se non c'era,
     * `other` se non si riesce a leggere.
     */
    public static function normalize(mixed $value): string
    {
        $value = is_scalar($value) ? strtolower(trim((string) $value)) : '';

        if ($value === '') {
            return '';
        }

        $value = trim((string) preg_replace('/[\s\-]+/', '_', $value), '_');

        return preg_match('/^[a-z0-9_]{1,40}$/', $value) === 1 ? $value : 'other';
    }

    /** @return array<int, true> i legami che hanno già almeno un'area */
    private static function migratedIds(): array
    {
        $out = [];

        foreach (self::select(ContactRelationDepartment::$table, ['deleted' => ['true', 'false']], null, ['id', 'relation_id']) as $row) {
            $out[(int) $row['relation_id']] = true;
        }

        return $out;
    }

    /**
     * Una lettura che non tace gli errori: `Model::find()` su una query
     * fallita dà una lista vuota, che qui vorrebbe dire «niente da copiare».
     *
     * @return list<array<string, mixed>>
     */
    private static function select(string $table, array $condition, ?string $order, array $columns): array
    {
        $result = ContactRelation::query()->Select($table, $condition, null, $order, $order !== null ? 'ASC' : null, $columns);

        if (!($result->success ?? false)) {
            throw new RuntimeException("Lettura di `{$table}` non riuscita: aree dei referenti non copiate.");
        }

        return self::rows($result->row ?? []);
    }

    /** Lo stesso ricontrollo di `backfill()`, in SQL sulla connessione dell'aggiornamento. */
    private static function assertAllCopied(mysqli $connection): void
    {
        $relations = ContactRelation::$table;
        $areas = ContactRelationDepartment::$table;
        $result = $connection->query(
            "SELECT COUNT(*) AS missing FROM `{$relations}` r WHERE TRIM(COALESCE(r.department, '')) <> ''"
            ." AND NOT EXISTS (SELECT 1 FROM `{$areas}` d WHERE d.relation_id = r.id)"
        );
        $missing = $result ? (int) ($result->fetch_assoc()['missing'] ?? -1) : -1;

        if ($missing !== 0) {
            throw new RuntimeException('Aree dei referenti non copiate ('.($missing < 0 ? 'controllo non riuscito' : $missing.' legami').'): la colonna `department` resta.');
        }
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
