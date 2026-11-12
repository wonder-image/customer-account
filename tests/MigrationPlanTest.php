<?php
/** php tests/MigrationPlanTest.php — piano della migrazione delle tabelle dei contatti `gst_*` (nessun database). */
declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use Wonder\Plugin\CustomerAccount\Migrations\CrmContactPointsMigration as CrmMigration;
use Wonder\Plugin\CustomerAccount\Migrations\LegacyContactTablesMigration as Migration;

check('database nuovo: niente da rinominare', fn () => Migration::plan([]) === []);

check('tabelle già del modulo: la migrazione non rifà nulla', fn () => Migration::plan(['contacts', 'contact_addresses']) === []);

check('tabelle del gestionale: le rinomina tutte e due', function () {
    return Migration::plan(['gst_contacts', 'gst_contact_addresses', 'users'])
        === ['gst_contacts' => 'contacts', 'gst_contact_addresses' => 'contact_addresses'];
});

check('vecchia e nuova insieme: si ferma senza piano', function () {
    foreach ([['gst_contacts', 'contacts'], ['gst_contact_addresses', 'contact_addresses', 'gst_contacts']] as $existing) {
        try {
            Migration::plan($existing);
            return false;
        } catch (\RuntimeException) {
        }
    }
    return true;
});

check('recapiti del CRM: database nuovo niente; allinea solo le tabelle che ci sono; i recapiti dei referenti nascono se ci sono i legami', function () {
    $all = ['contact_points', 'company_points', 'contact_point_departments', 'company_point_departments', 'contact_relation_points'];

    return CrmMigration::plan([]) === [] && CrmMigration::plan(['contacts', 'users']) === []
        && CrmMigration::plan(['contact_points', 'contacts']) === ['contact_points']
        && CrmMigration::plan(['contact_relations']) === ['contact_relation_points']
        // Rilanciata con tutto già creato ripassa le stesse tabelle: sqlTable() aggiunge soltanto.
        && CrmMigration::plan(['contact_relations', 'contact_relation_points', 'company_point_departments', 'contact_point_departments', 'company_points', 'contact_points']) === $all;
});

check('recapiti del CRM: la migrazione è nel module.json dopo quella dei tipi ufficio', function () {
    $migrations = json_encode(json_decode((string) file_get_contents(dirname(__DIR__).'/module.json'), true));

    return strpos($migrations, 'PointOfficeTypesMigration') < strpos($migrations, 'CrmContactPointsMigration');
});

summary();
