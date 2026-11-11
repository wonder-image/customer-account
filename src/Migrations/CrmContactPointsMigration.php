<?php

namespace Wonder\Plugin\CustomerAccount\Migrations;

use mysqli;
use RuntimeException;
use Wonder\App\ModelRegistry;
use Wonder\App\Module\Contracts\ModuleMigration;
use Wonder\Plugin\CustomerAccount\Models\CompanyPoint;
use Wonder\Plugin\CustomerAccount\Models\CompanyPointDepartment;
use Wonder\Plugin\CustomerAccount\Models\ContactPoint;
use Wonder\Plugin\CustomerAccount\Models\ContactPointDepartment;
use Wonder\Plugin\CustomerAccount\Models\ContactRelation;
use Wonder\Plugin\CustomerAccount\Models\ContactRelationPoint;

/**
 * I recapiti del CRM: il tipo dei recapiti accetta anche cellulare e
 * WhatsApp, e nasce la tabella dei recapiti del referente per l'azienda.
 *
 * Gira prima di `runTables()` e allinea solo le tabelle che servono, con lo
 * schema del Model: aggiunge valori e tabelle, non toglie né riscrive righe.
 * I telefoni salvati restano `phone`; i recapiti dei legami di prima non si
 * copiano (`ContactRelations::pointsByRelation()` li legge dalle colonne del
 * legame). Rilanciata non cambia niente.
 */
final class CrmContactPointsMigration implements ModuleMigration
{
    public static function run(mysqli $connection): array
    {
        $existing = [];

        foreach (array_keys(self::models()) + [99 => ContactRelation::$table] as $table) {
            $found = $connection->query("SHOW TABLES LIKE '".$connection->real_escape_string($table)."'");

            if ($found && $found->num_rows > 0) {
                $existing[] = $table;
            }
        }

        $plan = self::plan($existing);

        foreach ($plan as $table) {
            self::syncTable(self::models()[$table]);
        }

        return $plan === [] ? [] : ['tables' => $plan];
    }

    /**
     * Le tabelle da allineare, date quelle che ci sono: quelle con il tipo
     * del recapito già create, e i recapiti dei referenti se ci sono i
     * legami. Installazione nuova: nessuna, le crea `runTables()`.
     *
     * @param list<string> $existing
     * @return list<string>
     */
    public static function plan(array $existing): array
    {
        $plan = [];

        foreach (array_keys(self::models()) as $table) {
            $needs = $table === ContactRelationPoint::$table ? ContactRelation::$table : $table;

            if (in_array($needs, $existing, true)) {
                $plan[] = $table;
            }
        }

        return $plan;
    }

    /** @return array<string, class-string> tabella => Model */
    private static function models(): array
    {
        return [
            ContactPoint::$table => ContactPoint::class,
            CompanyPoint::$table => CompanyPoint::class,
            ContactPointDepartment::$table => ContactPointDepartment::class,
            CompanyPointDepartment::$table => CompanyPointDepartment::class,
            ContactRelationPoint::$table => ContactRelationPoint::class,
        ];
    }

    /** Crea o allinea la tabella com'è nel Model registrato (anche se un modulo lo estende). */
    private static function syncTable(string $default): void
    {
        global $TABLE;

        $table = $default::$table;
        $model = ModelRegistry::all()[$table] ?? $default;
        $schema = isset($TABLE->{$table}) && is_array($TABLE->{$table}) ? $TABLE->{$table} : $model::rawTableSchema();

        if (!function_exists('sqlTable')) {
            throw new RuntimeException('sqlTable() non disponibile: la migrazione gira con forge update.');
        }

        sqlTable($table, $schema);
    }
}
