<?php
/** php tests/AddressGeoTest.php — indirizzi di spedizione con place ID e coordinate. */
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require_once WI_APP_ROOT.'/app/function/string/date.php';
require_once WI_APP_ROOT.'/app/function/other/geo.php';

use Wonder\Auth\Frontend\AccountAddressValidation;
use Wonder\Plugin\CustomerAccount\Account\AccountAddresses;
use Wonder\Plugin\CustomerAccount\Models\ContactAddress;

check('indirizzi dei contatti: place ID e coordinate', function () {
    return array_diff(['place_id', 'lat', 'lng'], array_keys(ContactAddress::getColumns())) === [];
});

check('salvataggio dal frontend: campi dell\'indirizzo più geo pulito', function () {
    $values = AccountAddresses::values([
        'street' => 'Via Roma',
        'place_id' => 'ChIJabc_def-123',
        'lat' => '45.1',
        'lng' => '999',
        'user_id' => '7',
    ]);

    return ($values['street'] ?? null) === 'Via Roma'
        && $values['place_id'] === 'ChIJabc_def-123'
        && $values['lat'] === null && $values['lng'] === null
        && !array_key_exists('user_id', $values);
});

check('indirizzo di spedizione con coordinate: nessun errore su lat e lng', function () {
    $values = AccountAddresses::values([
        'name' => 'Mario', 'surname' => 'Rossi', 'country' => 'IT', 'province' => 'MI',
        'city' => 'Milano', 'cap' => '20121', 'street' => 'Via Roma', 'number' => '12',
        'place_id' => 'ChIJabc_def-123', 'lat' => '45.4642035', 'lng' => '9.189982',
    ]);

    return AccountAddressValidation::validate(ContactAddress::address(), $values) === [];
});

check('modello con coordinate decimali: valide', function () {
    return ContactAddress::validate(['place_id' => 'ChIJabc_def-123', 'lat' => 45.4642035, 'lng' => 9.189982])->valid === true;
});

check('coordinate al salvataggio: precisione intatta, vuoti a null', function () {
    $full = ContactAddress::prepare(['lat' => 45.4642035, 'lng' => '9.189982']);
    $empty = ContactAddress::prepare(['lat' => null, 'lng' => '']);

    return $full['lat'] === 45.4642035 && $full['lng'] === 9.189982
        && $empty['lat'] === null && $empty['lng'] === null
        && ContactAddress::validate(['lat' => null, 'lng' => null])->valid === true;
});

summary();
