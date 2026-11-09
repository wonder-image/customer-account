<?php

namespace Wonder\Plugin\CustomerAccount\Migrations;

use mysqli;
use RuntimeException;
use Throwable;
use Wonder\App\Model;
use Wonder\App\ModelRegistry;
use Wonder\App\Module\Contracts\ModuleMigration;
use Wonder\Plugin\CustomerAccount\Models\Company;
use Wonder\Plugin\CustomerAccount\Models\CompanyAddress;
use Wonder\Plugin\CustomerAccount\Models\ContactRelation;

/**
 * Separa le aziende dai contatti: fino alla 0.1 una scheda di `contacts`
 * poteva essere `business`, ora le aziende stanno in `companies`.
 *
 * Gira prima della sincronizzazione delle tabelle, che poi toglie da
 * `contacts` le colonne `type`, `business_name`, `pi`, `sdi`, `pec` e
 * `is_supplier`: per questo crea da sé `companies` e copia i dati adesso.
 *
 * - azienda senza account → diventa un'azienda, con gli indirizzi; la scheda
 *   contatto va nel cestino (`deleted = true`);
 * - azienda con account → la persona resta e si collega alla nuova azienda
 *   con accesso `admin`;
 * - privato fornitore → nasce un'azienda «Nome Cognome» fornitore, collegata;
 * - azienda senza ragione sociale → resta un contatto e finisce nel riepilogo.
 *
 * Ogni scheda trattata va in `contact_company_map` (contatto → azienda): i
 * moduli che puntano ai contatti (documenti, fornitori) la usano per spostare
 * i loro riferimenti. Senza la colonna `type` non c'è niente da fare.
 */
final class CompanySplitMigration implements ModuleMigration
{
    public const MAP_TABLE = 'contact_company_map';

    public const MOVE = 'moved';
    public const LINK = 'linked';
    public const SUPPLIER = 'supplier';
    public const SKIP = 'skipped';
    public const KEEP = 'kept';

    /** Colonne che non si copiano mai da un contatto a un'azienda. */
    private const NOT_COPIED = ['id', 'code', 'type', 'user_id', 'birth_date', 'name', 'surname', 'is_supplier', 'deleted'];

    /** Cosa succede a una scheda di `contacts`. */
    public static function classify(array $row): string
    {
        $business = ($row['type'] ?? '') === 'business';

        if (!$business) {
            return ($row['is_supplier'] ?? '') === 'true' ? self::SUPPLIER : self::KEEP;
        }

        if (trim((string) ($row['business_name'] ?? '')) === '') {
            return self::SKIP;
        }

        return (int) ($row['user_id'] ?? 0) > 0 ? self::LINK : self::MOVE;
    }

    /**
     * Le colonne che si copiano: quelle che hanno tutte e due le tabelle.
     *
     * @param list<string> $from
     * @param list<string> $to
     * @return list<string>
     */
    public static function commonColumns(array $from, array $to, array $exclude = self::NOT_COPIED): array
    {
        return array_values(array_diff(array_intersect($from, $to), $exclude));
    }

    /**
     * Cosa farebbe, senza scrivere niente.
     *
     * @return array{needed: bool, moved: list<int>, linked: list<int>, supplier: list<int>, skipped: list<int>, references: int}
     */
    public static function plan(mysqli $connection): array
    {
        $plan = ['needed' => false, 'moved' => [], 'linked' => [], 'supplier' => [], 'skipped' => [], 'references' => 0];

        if (!self::needed($connection)) {
            return $plan;
        }

        $plan['needed'] = true;
        $done = self::mapped($connection);

        foreach (self::rows($connection) as $row) {
            $action = self::classify($row);

            if ($action !== self::KEEP && !isset($done[(int) $row['id']])) {
                $plan[$action][] = (int) $row['id'];
            }
        }

        if (self::tableExists($connection, 'external_references')) {
            $plan['references'] = (int) ($connection->query("SELECT COUNT(*) FROM `external_references` WHERE `entity_type` = 'contacts'")->fetch_row()[0] ?? 0);
        }

        return $plan;
    }

    public static function run(mysqli $connection): array
    {
        $plan = self::plan($connection);

        if (!$plan['needed']) {
            return [];
        }

        $work = count($plan[self::MOVE]) + count($plan[self::LINK]) + count($plan[self::SUPPLIER]) + $plan['references'];

        if ($work === 0) {
            return $plan[self::SKIP] === [] ? [] : ['skipped' => $plan[self::SKIP]];
        }

        // Il DDL chiude le transazioni: le tabelle si preparano prima.
        self::syncTable(Company::class);
        self::syncTable(CompanyAddress::class);
        self::syncTable(ContactRelation::class);
        self::createMapTable($connection);

        $companyColumns = self::columns($connection, self::table(Company::class));
        $common = self::commonColumns(self::columns($connection, 'contacts'), $companyColumns);
        $addressCommon = self::commonColumns(
            self::columns($connection, 'contact_addresses'),
            self::columns($connection, self::table(CompanyAddress::class)),
            ['id', 'contact_id', 'deleted']
        );

        $connection->begin_transaction();
        $connection->query('SET FOREIGN_KEY_CHECKS = 0');

        try {
            $connection->query("UPDATE `external_references` SET `entity_type` = 'contact' WHERE `entity_type` = 'contacts'");

            // I legami da spostare si leggono prima: un id nuovo di `companies` può coincidere con un id vecchio di `contacts`.
            $relations = self::relationsOf($connection, $plan[self::MOVE]);
            $moved = [];

            foreach (self::rows($connection) as $row) {
                $id = (int) $row['id'];
                $action = self::classify($row);

                if (!in_array($id, $plan[self::MOVE], true) && !in_array($id, $plan[self::LINK], true) && !in_array($id, $plan[self::SUPPLIER], true)) {
                    continue;
                }

                $companyId = self::insertCompany($connection, $row, $common, $action);

                if ($action === self::MOVE) {
                    self::moveAddresses($connection, $id, $companyId, $addressCommon);
                    $moved[$id] = $companyId;
                    self::exec($connection, "UPDATE `external_references` SET `entity_type` = 'company', `entity_id` = ? WHERE `entity_type` = 'contact' AND `entity_id` = ?", [$companyId, $id]);
                    self::exec($connection, "UPDATE `contacts` SET `deleted` = 'true' WHERE `id` = ?", [$id]);
                } else {
                    // Le relazioni vecchie puntano ancora a `contacts`: si spostano solo quelle dell'azienda trasferita.
                    self::exec(
                        $connection,
                        "INSERT INTO `contact_relations` (`company_id`, `person_id`, `is_primary`, `receives_documents`, `access`, `position`) VALUES (?, ?, 'true', 'true', ?, 0)",
                        [$companyId, $id, $action === self::LINK ? ContactRelation::ACCESS_ADMIN : ContactRelation::ACCESS_NONE]
                    );
                }

                self::exec($connection, 'INSERT INTO `'.self::MAP_TABLE.'` (`contact_id`, `company_id`, `action`) VALUES (?, ?, ?)', [$id, $companyId, $action]);
            }

            foreach ($relations as $relationId => $contactId) {
                self::exec($connection, 'UPDATE `contact_relations` SET `company_id` = ? WHERE `id` = ?', [$moved[$contactId], $relationId]);
            }

            $connection->query('SET FOREIGN_KEY_CHECKS = 1');
            $connection->commit();
        } catch (Throwable $throwable) {
            $connection->rollback();
            $connection->query('SET FOREIGN_KEY_CHECKS = 1');

            throw $throwable;
        }

        return array_filter([
            'moved' => $plan[self::MOVE],
            'linked' => $plan[self::LINK],
            'supplier' => $plan[self::SUPPLIER],
            'skipped' => $plan[self::SKIP],
            'references' => $plan['references'],
        ]);
    }

    /** L'azienda nata da una scheda contatto. */
    private static function insertCompany(mysqli $connection, array $row, array $common, string $action): int
    {
        $values = [];

        foreach ($common as $column) {
            $values[$column] = $row[$column] ?? null;
        }

        if ($action === self::SUPPLIER) {
            $values['business_name'] = trim(((string) ($row['name'] ?? '')).' '.((string) ($row['surname'] ?? '')));
            // Il fornitore privato non è anche cliente come azienda: lo resta la persona.
            $values['is_customer'] = 'false';
        }

        $values['code'] = self::companyCode($connection, (string) ($row['code'] ?? ''));
        $values['is_supplier'] = ($row['is_supplier'] ?? '') === 'true' ? 'true' : 'false';
        $values['deleted'] = 'false';

        $table = self::table(Company::class);
        $columns = array_values(array_intersect(array_keys($values), self::columns($connection, $table)));
        $sql = 'INSERT INTO `'.$table.'` (`'.implode('`, `', $columns).'`) VALUES ('.implode(', ', array_fill(0, count($columns), '?')).')';

        self::exec($connection, $sql, array_map(static fn (string $column) => $values[$column], $columns));

        return (int) $connection->insert_id;
    }

    /**
     * Il codice dell'azienda nato da quello del contatto: stesso suffisso con
     * `cmp_`, così chi riconosce le righe dal codice (i dati di prova) le
     * ritrova. `null` se il contatto non aveva un codice suo.
     */
    public static function codeFromContact(string $contactCode): ?string
    {
        return str_starts_with($contactCode, 'con_') && strlen($contactCode) > 4
            ? 'cmp_'.substr($contactCode, 4)
            : null;
    }

    /** Il codice dell'azienda: quello del contatto, o uno nuovo se manca o è già preso. */
    private static function companyCode(mysqli $connection, string $contactCode): string
    {
        $code = self::codeFromContact($contactCode);

        if ($code === null) {
            return Company::newCode();
        }

        $statement = $connection->prepare('SELECT 1 FROM `'.self::table(Company::class).'` WHERE `code` = ? LIMIT 1');

        if ($statement === false) {
            return Company::newCode();
        }

        $statement->bind_param('s', $code);
        $statement->execute();
        $taken = ($statement->get_result()?->num_rows ?? 0) > 0;
        $statement->close();

        return $taken ? Company::newCode() : $code;
    }

    /**
     * I legami che hanno come azienda una delle schede da spostare.
     *
     * @param list<int> $contactIds
     * @return array<int, int> id del legame → id del contatto
     */
    private static function relationsOf(mysqli $connection, array $contactIds): array
    {
        if ($contactIds === [] || !self::tableExists($connection, 'contact_relations')) {
            return [];
        }

        $ids = implode(', ', array_map('intval', $contactIds));
        $out = [];

        foreach ($connection->query("SELECT `id`, `company_id` FROM `contact_relations` WHERE `company_id` IN ({$ids})")->fetch_all() as [$id, $contactId]) {
            $out[(int) $id] = (int) $contactId;
        }

        return $out;
    }

    private static function moveAddresses(mysqli $connection, int $contactId, int $companyId, array $common): void
    {
        $table = self::table(CompanyAddress::class);
        $list = $common === [] ? '' : ', `'.implode('`, `', $common).'`';

        self::exec(
            $connection,
            'INSERT INTO `'.$table.'` (`company_id`'.$list.') SELECT ?'.$list." FROM `contact_addresses` WHERE `contact_id` = ? AND `deleted` = 'false'",
            [$companyId, $contactId]
        );
        self::exec($connection, "UPDATE `contact_addresses` SET `deleted` = 'true' WHERE `contact_id` = ?", [$contactId]);
    }

    private static function needed(mysqli $connection): bool
    {
        return self::tableExists($connection, 'contacts') && in_array('type', self::columns($connection, 'contacts'), true);
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mysqli $connection): array
    {
        $result = $connection->query("SELECT * FROM `contacts` WHERE `deleted` = 'false' ORDER BY `id`");

        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    /** @return array<int, true> */
    private static function mapped(mysqli $connection): array
    {
        if (!self::tableExists($connection, self::MAP_TABLE)) {
            return [];
        }

        $ids = [];

        foreach ($connection->query('SELECT `contact_id` FROM `'.self::MAP_TABLE.'`')->fetch_all() as [$id]) {
            $ids[(int) $id] = true;
        }

        return $ids;
    }

    private static function createMapTable(mysqli $connection): void
    {
        $connection->query('CREATE TABLE IF NOT EXISTS `'.self::MAP_TABLE.'` (
            `contact_id` INT NOT NULL PRIMARY KEY,
            `company_id` INT NOT NULL,
            `action` VARCHAR(20) NOT NULL,
            `creation` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `ind_company` (`company_id`)
        ) ENGINE=InnoDB');
    }

    /** Crea o allinea la tabella del Model registrato (anche se un modulo lo estende). */
    private static function syncTable(string $base): void
    {
        global $TABLE;

        $table = $base::$table;
        $schema = isset($TABLE->{$table}) && is_array($TABLE->{$table}) ? $TABLE->{$table} : self::model($base)::rawTableSchema();

        if (!function_exists('sqlTable')) {
            throw new RuntimeException('sqlTable() non disponibile: la migrazione gira con forge update.');
        }

        sqlTable($table, $schema);
    }

    /** @return class-string<Model> */
    private static function model(string $base): string
    {
        return ModelRegistry::all()[$base::$table] ?? $base;
    }

    private static function table(string $base): string
    {
        return $base::$table;
    }

    /** @return list<string> */
    private static function columns(mysqli $connection, string $table): array
    {
        $result = $connection->query('SHOW COLUMNS FROM `'.$connection->real_escape_string($table).'`');

        return $result ? array_column($result->fetch_all(MYSQLI_ASSOC), 'Field') : [];
    }

    private static function tableExists(mysqli $connection, string $table): bool
    {
        $result = $connection->query("SHOW TABLES LIKE '".$connection->real_escape_string($table)."'");

        return $result && $result->num_rows > 0;
    }

    private static function exec(mysqli $connection, string $sql, array $params): void
    {
        $statement = $connection->prepare($sql);

        if ($statement === false) {
            throw new RuntimeException('Migrazione aziende: '.$connection->error);
        }

        $statement->bind_param(str_repeat('s', count($params)), ...array_map(static fn ($value) => $value === null ? null : (string) $value, $params));

        if (!$statement->execute()) {
            throw new RuntimeException('Migrazione aziende: '.$statement->error);
        }

        $statement->close();
    }
}
