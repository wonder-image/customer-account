<?php
/** php tests/MigrationPlanTest.php — piano della migrazione delle tabelle dei contatti `gst_*` (nessun database). */
declare(strict_types=1);

require __DIR__.'/bootstrap.php';

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

summary();
