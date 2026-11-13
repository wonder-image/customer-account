<?php

namespace Wonder\Plugin\CustomerAccount\Migrations;

use mysqli;
use Wonder\App\Media\LegacyMediaMigration;
use Wonder\App\Module\Contracts\ModuleMigration;
use Wonder\Plugin\CustomerAccount\Models\CompanyBrand;

/**
 * Il logo dei brand passa dalla cartella `contacts/brand-logos` alla
 * libreria Media del core: il lavoro lo fa {@see LegacyMediaMigration}. I
 * file si copiano, non si spostano; la colonna `logo` resta, deprecata.
 *
 * Gira dopo l'allineamento delle tabelle (`migrations_after_tables`), quando
 * `logo_id` c'è già. Idempotente: tocca solo i brand con l'id vuoto.
 */
final class CompanyBrandMediaMigration implements ModuleMigration
{
    public static function run(mysqli $connection, ?string $uploadRoot = null): array
    {
        return LegacyMediaMigration::run($connection, CompanyBrand::legacyMediaDefinitions(), $uploadRoot);
    }
}
