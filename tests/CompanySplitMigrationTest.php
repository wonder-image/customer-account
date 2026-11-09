<?php
/** php tests/CompanySplitMigrationTest.php — cosa succede a ogni scheda di `contacts` quando le aziende vanno in `companies` (nessun database). */
declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use Wonder\Plugin\CustomerAccount\Migrations\CompanySplitMigration as Migration;

check('azienda senza account: diventa un\'azienda', fn () => Migration::classify(['type' => 'business', 'business_name' => 'Rossi srl', 'user_id' => 0]) === Migration::MOVE);

check('azienda con account: la persona resta e si collega', fn () => Migration::classify(['type' => 'business', 'business_name' => 'Rossi srl', 'user_id' => '12']) === Migration::LINK);

check('azienda senza ragione sociale: resta un contatto, nel riepilogo', fn () => Migration::classify(['type' => 'business', 'business_name' => '  ', 'user_id' => 0]) === Migration::SKIP);

check('privato fornitore: nasce un\'azienda fornitore', fn () => Migration::classify(['type' => 'private', 'is_supplier' => 'true']) === Migration::SUPPLIER);

check('privato e basta: non si tocca', function () {
    return Migration::classify(['type' => 'private', 'is_supplier' => 'false']) === Migration::KEEP
        && Migration::classify(['type' => '']) === Migration::KEEP;
});

check('colonne copiate: solo quelle comuni, senza id, codice e campi della persona', function () {
    $contacts = ['id', 'code', 'type', 'user_id', 'name', 'surname', 'birth_date', 'business_name', 'pi', 'email', 'price_list_id', 'is_supplier', 'deleted', 'creation'];
    $companies = ['id', 'code', 'business_name', 'pi', 'email', 'is_customer', 'is_supplier', 'deleted', 'creation'];

    return Migration::commonColumns($contacts, $companies) === ['business_name', 'pi', 'email', 'creation'];
});

check('colonne degli indirizzi: esclusione scelta da chi chiama', function () {
    return Migration::commonColumns(['id', 'contact_id', 'street', 'city', 'deleted'], ['id', 'company_id', 'street', 'city', 'deleted'], ['id', 'contact_id', 'deleted'])
        === ['street', 'city'];
});

summary();
