<?php

namespace Wonder\Plugin\CustomerAccount\Migrations;

use mysqli;
use Wonder\App\Module\Contracts\ModuleMigration;
use Wonder\App\Support\LegacyTableRenames;

/**
 * Promuove le tabelle dei contatti del gestionale (`gst_*`) a quelle del modulo.
 *
 * Le due tabelle si rinominano insieme, in un solo `RENAME TABLE`: ID, dati e
 * chiavi esterne restano intatti. Se esistono sia la vecchia sia la nuova si
 * ferma senza cancellare né unire nulla.
 */
final class LegacyContactTablesMigration implements ModuleMigration
{
    public const TABLES = [
        'gst_contacts' => 'contacts',
        'gst_contact_addresses' => 'contact_addresses',
    ];

    public static function plan(array $existing): array
    {
        return LegacyTableRenames::plan(self::TABLES, $existing, 'contatti');
    }

    public static function run(mysqli $connection): array
    {
        return LegacyTableRenames::run($connection, self::TABLES, 'contatti');
    }
}
