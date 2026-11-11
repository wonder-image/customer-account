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

/**
 * L'etichetta «Fatturazione» dei recapiti diventa il tipo ufficio
 * «Amministrazione»: l'etichetta non si sceglie più, e quella era l'unica
 * che diceva di cosa si occupa il recapito.
 *
 * Gira prima di `runTables()`: le tabelle dei tipi le crea da sé. Un recapito
 * che ha già un tipo tiene il suo; a copia fatta l'etichetta si svuota, così
 * rilanciata non fa niente e un tipo tolto a mano non torna. Le altre
 * etichette (Lavoro, Personale, Altro) restano dove sono.
 */
final class PointOfficeTypesMigration implements ModuleMigration
{
    public const LABEL = 'billing';
    public const DEPARTMENT = 'administration';

    public static function run(mysqli $connection): array
    {
        $copied = 0;

        foreach (self::stores() as [$points, $departments, $fk]) {
            $exists = $connection->query("SHOW TABLES LIKE '".$connection->real_escape_string($points::$table)."'");

            // Installazione nuova: le tabelle le crea runTables().
            if (!$exists || $exists->num_rows === 0) {
                continue;
            }

            self::syncTable($departments);
            $copied += self::backfill($points, $departments, $fk);
        }

        return $copied === 0 ? [] : ['copied' => $copied];
    }

    /**
     * @param class-string $points
     * @param class-string $departments
     * @return int quanti recapiti hanno preso il tipo
     */
    public static function backfill(string $points, string $departments, string $fk): int
    {
        $rows = self::select($points, ['label' => self::LABEL]);

        if ($rows === []) {
            return 0;
        }

        $typed = [];

        foreach (self::select($departments, []) as $row) {
            $typed[(int) $row[$fk].'|'.$row['kind'].'|'.$row['value']] = true;
        }

        $copied = 0;

        foreach ($rows as $row) {
            $key = (int) $row[$fk].'|'.$row['kind'].'|'.$row['value'];

            if (!isset($typed[$key])) {
                $result = $departments::create([
                    $fk => (int) $row[$fk],
                    'kind' => (string) $row['kind'],
                    'value' => (string) $row['value'],
                    'department' => self::DEPARTMENT,
                ]);

                if (!($result->success ?? false)) {
                    throw new RuntimeException('Tipo ufficio del recapito '.(int) $row['id'].' non salvato.');
                }

                $typed[$key] = true;
                $copied++;
            }

            $result = $points::update(['label' => ''], (int) $row['id']);

            if (!($result->success ?? false)) {
                throw new RuntimeException('Etichetta del recapito '.(int) $row['id'].' non aggiornata.');
            }
        }

        return $copied;
    }

    /** @return list<array{class-string, class-string, string}> */
    private static function stores(): array
    {
        return [
            [CompanyPoint::class, CompanyPointDepartment::class, 'company_id'],
            [ContactPoint::class, ContactPointDepartment::class, 'contact_id'],
        ];
    }

    /**
     * Una lettura che non tace gli errori: una query fallita non vuol dire
     * «niente da copiare».
     *
     * @param class-string $model
     * @return list<array<string, mixed>>
     */
    private static function select(string $model, array $condition): array
    {
        $result = $model::query()->Select($model::$table, $condition + ['deleted' => 'false']);

        if (!($result->success ?? false)) {
            throw new RuntimeException('Lettura di `'.$model::$table.'` non riuscita: tipi ufficio dei recapiti non copiati.');
        }

        $rows = $result->row ?? [];

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }

    /** Crea la tabella dei tipi com'è nel Model registrato (anche se un modulo lo estende). */
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
