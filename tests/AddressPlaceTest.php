<?php
/** php tests/AddressPlaceTest.php — indirizzi: ricerca per nome dell'attività, place ID e coordinate anche in fatturazione, «Presso» negli indirizzi di consegna, geo nei record (database finto). */
declare(strict_types=1);

require __DIR__.'/support/FakeDb.php';
require __DIR__.'/bootstrap.php';
require_once WI_APP_ROOT.'/app/function/string/date.php';
require_once WI_APP_ROOT.'/app/function/other/geo.php';

use Wonder\Http\Csrf;
use Wonder\Plugin\CustomerAccount\Account\AccountBilling;
use Wonder\Plugin\CustomerAccount\Models\Company;
use Wonder\Plugin\CustomerAccount\Models\CompanyAddress;
use Wonder\Plugin\CustomerAccount\Models\Contact;
use Wonder\Plugin\CustomerAccount\Models\ContactAddress;
use Wonder\Plugin\CustomerAccount\Resources\CompanyAddressResource;
use Wonder\Plugin\CustomerAccount\Resources\CompanyResource;
use Wonder\Plugin\CustomerAccount\Resources\ContactAddressResource;
use Wonder\Plugin\CustomerAccount\Resources\ContactResource;
use Wonder\Plugin\CustomerAccount\Support\ContactRecord;
use Wonder\Sql\Query;

$tables = static fn (): array => [
    'contacts' => [2 => ['id' => 2, 'name' => 'Anna', 'surname' => 'Bianchi', 'email' => 'anna@rossi.test', 'user_id' => 0, 'place_id' => 'ChIJsalvato_123', 'lat' => '45.1', 'lng' => '9.1', 'deleted' => 'false']],
    'companies' => [2 => ['id' => 2, 'business_name' => 'Rossi srl', 'deleted' => 'false']],
    'contact_addresses' => [],
    'company_addresses' => [],
    'contact_points' => [],
    'company_points' => [],
    'contact_relations' => [],
    'external_references' => [],
];
$_SESSION = [];
$_POST = [];
$token = Csrf::token();
$reject = static function (callable $fn): ?string {
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }

    return null;
};
$street = static function (string $resource): string {
    foreach ($resource::formSchema() as $field) {
        if ($field->name === 'street') {
            return (string) $field->get('attribute');
        }
    }

    return '';
};

check('colonne: place ID e coordinate in fatturazione (persone e aziende), «Presso» negli indirizzi di consegna', function () {
    $geo = ['place_id', 'lat', 'lng'];

    return array_diff($geo, array_keys(Contact::getColumns())) === [] && array_diff($geo, array_keys(Company::getColumns())) === []
        && array_diff([...$geo, 'business_name'], array_keys(ContactAddress::getColumns())) === []
        && array_diff([...$geo, 'business_name'], array_keys(CompanyAddress::getColumns())) === []
        && isset(ContactAddress::dataFields()['business_name']) && Contact::getColumns()['lat']['type'] === 'DECIMAL';
});

check('form: la via cerca anche le attività per nome; il nome va nella ragione sociale (aziende) o in «Presso» (consegna), non nella persona', function () use ($street) {
    return str_contains($street(ContactResource::class), 'data-wi-place-names') && !str_contains($street(ContactResource::class), 'data-ca-place-name')
        && str_contains($street(CompanyResource::class), 'data-ca-place-name="business_name"')
        && str_contains($street(ContactAddressResource::class), 'data-wi-place-names') && str_contains($street(ContactAddressResource::class), 'data-ca-place-name="business_name"')
        && str_contains($street(CompanyAddressResource::class), 'data-ca-place-name="business_name"')
        && in_array('place_id', array_map(static fn ($field) => $field->name, ContactResource::formSchema()), true);
});

check('form: il Paese di una persona ha l\'opzione vuota e parte da IT; quello di un\'azienda no', function () {
    $country = static function (string $resource) {
        foreach ($resource::formSchema() as $field) {
            if ($field->name === 'country') {
                return $field;
            }
        }

        return null;
    };
    $person = $country(ContactResource::class);
    $company = $country(CompanyResource::class);
    $options = (array) $person->get('options');

    return array_key_first($options) === '' && isset($options['IT']) && $person->get('value') === 'IT'
        && !array_key_exists('', (array) ($company->get('options') ?? []));
});

check('scheda: geo dal form ripulito (fuori limiti e caratteri strani = null), senza geo nel POST non si azzera', function () use ($tables, $token) {
    Query::reset($tables());
    $base = ['name' => 'Anna', 'surname' => 'Bianchi', 'active' => 'true', '_csrf' => $token];
    $bad = ContactResource::mutateRequestValues($base + ['place_id' => 'ChIJ<script>', 'lat' => '91', 'lng' => '9'], 'update', 'backend', Query::$tables['contacts'][2]);
    $ok = ContactResource::mutateRequestValues($base + ['place_id' => ' ChIJabc_def-123 ', 'lat' => '45.4642035', 'lng' => '9.189982'], 'update', 'backend', Query::$tables['contacts'][2]);
    $none = ContactResource::mutateRequestValues($base, 'update', 'backend', Query::$tables['contacts'][2]);
    $array = ContactResource::mutateRequestValues($base + ['place_id' => ['x'], 'lat' => ['1'], 'lng' => '2'], 'update', 'backend', Query::$tables['contacts'][2]);

    return $bad['place_id'] === null && $bad['lat'] === null && $bad['lng'] === null
        && $ok['place_id'] === 'ChIJabc_def-123' && $ok['lat'] === 45.4642035 && $ok['lng'] === 9.189982
        && !array_key_exists('place_id', $none) && !array_key_exists('lat', $none)
        && $array['place_id'] === null && $array['lat'] === null;
});

check('indirizzo di consegna: «Presso» salvato, troppo lungo o non testo rifiutato', function () use ($tables, $token, $reject) {
    Query::reset($tables());
    $base = ['_csrf' => $token, 'contact_id' => '2', 'country' => 'IT', 'province' => 'MI', 'city' => 'Milano', 'cap' => '20121', 'street' => 'Via Roma', 'number' => '1', 'name' => 'Anna', 'surname' => 'Bianchi'];
    $ok = ContactAddressResource::mutateRequestValues($base + ['business_name' => 'Bar Sport', 'place_id' => 'ChIJabc_def-123', 'lat' => '45.46', 'lng' => '9.18'], 'store');
    $long = $reject(static fn () => ContactAddressResource::mutateRequestValues($base + ['business_name' => str_repeat('x', 151)], 'store'));
    $array = $reject(static fn () => ContactAddressResource::mutateRequestValues($base + ['business_name' => ['x']], 'store'));

    return $ok['business_name'] === 'Bar Sport' && $ok['place_id'] === 'ChIJabc_def-123' && $ok['lat'] === 45.46
        && $long === 'contacts.field_invalid' && $array === 'contacts.field_invalid';
});

check('area account: la fatturazione salva place ID e coordinate solo se postati, ripuliti', function () use ($tables) {
    $values = ['name' => 'Anna', 'surname' => 'Bianchi', 'cf' => '', 'country' => 'IT', 'province' => 'MI', 'city' => 'Milano', 'cap' => '20121', 'street' => 'Via Roma', 'number' => '1'];
    Query::reset($tables());
    $saved = AccountBilling::save(2, $values + ['place_id' => 'ChIJnuovo_456', 'lat' => '45.5', 'lng' => '200', 'user_id' => '9']);
    $first = Query::$tables['contacts'][2];
    Query::reset($tables());
    AccountBilling::save(2, $values);
    $kept = Query::$tables['contacts'][2];

    return $saved->success && $first['place_id'] === 'ChIJnuovo_456' && $first['lat'] === null && $first['lng'] === null && (int) $first['user_id'] === 0
        && $kept['place_id'] === 'ChIJsalvato_123' && $kept['lat'] === '45.1'
        && AccountBilling::geo(['street' => 'x']) === [];
});

check('record: place ID e coordinate della fatturazione; fromArray li ripulisce, senza chiavi niente', function () use ($tables) {
    Query::reset($tables());
    $record = ContactRecord::from(2);
    $in = ContactRecord::fromArray(['name' => 'X', 'place_id' => 'bad id!', 'lat' => '45', 'lng' => '9']);

    return $record['place_id'] === 'ChIJsalvato_123' && $record['lat'] === 45.1 && $record['lng'] === 9.1
        && $in['place_id'] === null && $in['lat'] === 45.0 && $in['lng'] === 9.0
        && !array_key_exists('place_id', ContactRecord::fromArray(['name' => 'X']));
});

summary();
