<?php
/** php tests/CompanyAddressesTest.php — indirizzi delle aziende per brand: vuoto = tutti, brand solo dell'azienda, stacco e unione dei brand, form (database finto). */
declare(strict_types=1);

require __DIR__.'/support/FakeDb.php';
require __DIR__.'/bootstrap.php';

use Wonder\Plugin\CustomerAccount\Resources\CompanyAddressResource;
use Wonder\Plugin\CustomerAccount\Support\CompanyAddresses;
use Wonder\Plugin\CustomerAccount\Support\CompanyBrands;
use Wonder\Sql\Query;

$brand = static fn (int $id, string $name): array => ['id' => $id, 'code' => 'cbr_'.$id, 'name' => $name, 'slug' => 'b'.$id, 'name_key' => 'b'.$id, 'archived' => 'false', 'deleted' => 'false'];
$link = static fn (int $id, int $company, int $brandId): array => ['id' => $id, 'company_id' => $company, 'brand_id' => $brandId, 'role' => 'owner', 'is_default' => 'false', 'position' => $id, 'ended_at' => null, 'deleted' => 'false'];
$address = static fn (int $id, int $company, int $position, string $label): array => ['id' => $id, 'company_id' => $company, 'label' => $label, 'position' => $position, 'is_default' => 'false', 'deleted' => 'false'];
// L'azienda 1 ha i brand 1 e 2; il brand 3 è solo dell'azienda 2.
$seed = static fn (): array => [
    'companies' => [
        1 => ['id' => 1, 'business_name' => 'Steli srl', 'deleted' => 'false'],
        2 => ['id' => 2, 'business_name' => 'Altra srl', 'deleted' => 'false'],
    ],
    'company_brands' => [1 => $brand(1, 'RClass'), 2 => $brand(2, 'La Piana'), 3 => $brand(3, 'Altro')],
    'company_brand_links' => [1 => $link(1, 1, 1), 2 => $link(2, 1, 2), 3 => $link(3, 2, 3)],
    'company_addresses' => [
        1 => $address(1, 1, 0, 'Sede'),
        2 => $address(2, 1, 1, 'Ristorante La Piana'),
        3 => $address(3, 1, 2, 'Magazzino RClass'),
        4 => $address(4, 2, 0, 'Altra sede'),
    ],
    'company_address_brands' => [],
    'contact_relations' => [],
    'contact_relation_brands' => [],
];
$labels = static fn (array $rows): array => array_column($rows, 'label');

check('setBrands: solo brand legati oggi all\'azienda dell\'indirizzo, lista vuota = tutti', function () use ($seed) {
    Query::reset($seed());
    $saved = CompanyAddresses::setBrands(2, [2, '3', 99, 2, '', ['x']]);
    $missing = CompanyAddresses::setBrands(99, [1]);
    $read = CompanyAddresses::brandsOf(2);
    $cleared = CompanyAddresses::setBrands(2, []);

    return $saved === [2] && $read === [2] && $missing === [] && $cleared === []
        && CompanyAddresses::brandsOf(2) === [] && Query::$tables['company_address_brands'] === [];
});

check('addressesFor: prima quelli del brand, poi quelli per tutti; senza brand tutti; in poche letture', function () use ($seed, $labels) {
    Query::reset($seed());
    CompanyAddresses::setBrands(2, [2]);
    CompanyAddresses::setBrands(3, [1, 2]);
    Query::$selects = [];
    $piana = CompanyAddresses::addressesFor(1, 2);
    $reads = count(Query::$selects);

    return $labels($piana) === ['Ristorante La Piana', 'Magazzino RClass', 'Sede'] && $reads <= 3
        && $labels(CompanyAddresses::addressesFor(1, 1)) === ['Magazzino RClass', 'Sede']
        && $labels(CompanyAddresses::addressesFor(1)) === ['Sede', 'Ristorante La Piana', 'Magazzino RClass']
        && $labels(CompanyAddresses::addressesFor(1, 3)) === ['Sede']
        && $piana[0]['brand_ids'] === [2] && $piana[2]['brand_ids'] === []
        && CompanyAddresses::addressesFor(0) === [];
});

check('staccare un brand dall\'azienda toglie le righe degli indirizzi; un brand orfano vale come tutti', function () use ($seed, $labels) {
    $tables = $seed();
    $tables['company_address_brands'] = [
        1 => ['id' => 1, 'address_id' => 2, 'brand_id' => 2, 'deleted' => 'false'],
        2 => ['id' => 2, 'address_id' => 3, 'brand_id' => 1, 'deleted' => 'false'],
        3 => ['id' => 3, 'address_id' => 4, 'brand_id' => 3, 'deleted' => 'false'],
    ];
    Query::reset($tables);
    CompanyBrands::sync(1, [1]);

    return array_column(Query::$tables['company_address_brands'], 'brand_id', 'address_id') === [3 => 1, 4 => 3]
        && CompanyAddresses::brandsOf(2) === [] && $labels(CompanyAddresses::addressesFor(1, 1)) === ['Magazzino RClass', 'Sede', 'Ristorante La Piana'];
});

check('merge: gli indirizzi passano al brand di arrivo, senza righe doppie', function () use ($seed) {
    $tables = $seed();
    $tables['company_address_brands'] = [
        1 => ['id' => 1, 'address_id' => 2, 'brand_id' => 2, 'deleted' => 'false'],
        2 => ['id' => 2, 'address_id' => 3, 'brand_id' => 1, 'deleted' => 'false'],
        3 => ['id' => 3, 'address_id' => 3, 'brand_id' => 2, 'deleted' => 'false'],
    ];
    Query::reset($tables);
    $ok = CompanyBrands::merge(2, 1);

    return $ok && array_column(Query::$tables['company_address_brands'], 'brand_id', 'address_id') === [2 => 1, 3 => 1]
        && count(Query::$tables['company_address_brands']) === 2;
});

check('form degli indirizzi dell\'azienda: campo «Brand» multiplo, valori ripresi, scrittura solo col segnaposto', function () use ($seed) {
    Query::reset($seed());
    $field = CompanyAddressResource::formFieldsByKey()[CompanyAddressResource::BRANDS_INPUT] ?? null;
    CompanyAddressResource::syncRepeaterRelations(2, ['brand_ids' => ['1']]);
    $untouched = Query::$tables['company_address_brands'];
    CompanyAddressResource::syncRepeaterRelations(2, ['address_brands_form' => '1', 'brand_ids' => ['', '1', '3']]);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $values = CompanyAddressResource::mutateFormValues(['id' => 2], 'edit');
    unset($_SERVER['REQUEST_METHOD']);
    return $field !== null && $field->get('multiple') === true && $untouched === []
        && CompanyAddresses::brandsOf(2) === [1] && $values['brand_ids'] === [1]
        // Non sono colonne: `mutateRequestValues()` tiene solo le chiavi di `labelSchema()`.
        && !array_key_exists('brand_ids', CompanyAddressResource::labelSchema()) && !array_key_exists('address_brands_form', CompanyAddressResource::labelSchema());
});

summary();
