<?php

namespace Wonder\Plugin\CustomerAccount\Migrations;

use mysqli;
use RuntimeException;
use Throwable;
use Wonder\App\ModelRegistry;
use Wonder\App\Module\Contracts\ModuleMigration;
use Wonder\Plugin\CustomerAccount\Models\CompanyBrand;
use Wonder\Plugin\CustomerAccount\Support\CompanyBrands;

/**
 * Dà `code`, `slug` e `name_key` ai brand nati prima che esistessero.
 *
 * Gira prima di `runTables()`: alla prima volta le colonne non ci sono
 * ancora, quindi allinea da sé `company_brands` (le colonne nuove nascono
 * NULL, l'indice univoco di `slug` e `code` regge) e poi riempie. Tocca solo
 * le righe con un valore vuoto: rilanciata non fa niente. Non cancella né
 * unisce nulla.
 */
final class CompanyBrandCodesMigration implements ModuleMigration
{
    private const COLUMNS = ['code', 'slug', 'name_key', 'archived'];

    public static function run(mysqli $connection): array
    {
        $table = CompanyBrand::$table;
        $exists = $connection->query("SHOW TABLES LIKE '".$connection->real_escape_string($table)."'");

        // Installazione nuova: la tabella la crea runTables(), vuota.
        if (!$exists || $exists->num_rows === 0) {
            return [];
        }

        $result = $connection->query('SHOW COLUMNS FROM `'.$table.'`');
        $columns = $result ? array_column($result->fetch_all(MYSQLI_ASSOC), 'Field') : [];

        if (array_diff(self::COLUMNS, $columns) !== []) {
            self::syncTable();
        }

        $filled = self::backfill();

        return $filled === 0 ? [] : ['filled' => $filled];
    }

    /**
     * Riempie codice, slug e chiave dei doppioni dove mancano.
     *
     * @return int quanti brand ha toccato
     */
    public static function backfill(): int
    {
        try {
            $rows = CompanyBrand::find(['deleted' => ['true', 'false']], null, 'id', 'ASC');
        } catch (Throwable) {
            return 0;
        }

        $rows = is_array($rows) && isset($rows['id']) ? [$rows] : array_filter((array) $rows, 'is_array');
        $filled = 0;

        foreach ($rows as $row) {
            $values = [];
            $name = CompanyBrands::label($row);

            if (trim((string) ($row['code'] ?? '')) === '') {
                $values['code'] = CompanyBrand::newCode();
            }

            if (trim((string) ($row['slug'] ?? '')) === '') {
                $values['slug'] = CompanyBrands::slugFor($name, (int) $row['id']);
            }

            if (trim((string) ($row['name_key'] ?? '')) === '') {
                $values['name_key'] = CompanyBrands::nameKey($name);
            }

            if ($values !== []) {
                // Una riga alla volta: lo slug che segue vede quelli appena dati.
                CompanyBrand::query()->Update(CompanyBrand::$table, $values, 'id', (int) $row['id']);
                $filled++;
            }
        }

        return $filled;
    }

    /** Allinea la tabella del Model registrato (anche se un modulo lo estende). */
    private static function syncTable(): void
    {
        global $TABLE;

        $table = CompanyBrand::$table;
        $model = ModelRegistry::all()[$table] ?? CompanyBrand::class;
        $schema = isset($TABLE->{$table}) && is_array($TABLE->{$table}) ? $TABLE->{$table} : $model::rawTableSchema();

        if (!function_exists('sqlTable')) {
            throw new RuntimeException('sqlTable() non disponibile: la migrazione gira con forge update.');
        }

        sqlTable($table, $schema);
    }
}
