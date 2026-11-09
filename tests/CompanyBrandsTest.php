<?php
/** php tests/CompanyBrandsTest.php — brand delle aziende: creazione senza doppioni, legame N:N, eventi, campo del form, record. */
declare(strict_types=1);

require __DIR__.'/support/FakeDb.php';
require __DIR__.'/bootstrap.php';

use Wonder\Plugin\CustomerAccount\Resources\CompanyBrandResource;
use Wonder\Plugin\CustomerAccount\Resources\CompanyResource;
use Wonder\Plugin\CustomerAccount\Support\CompanyBrands;
use Wonder\Plugin\CustomerAccount\Support\CompanyEvents;
use Wonder\Plugin\CustomerAccount\Support\CompanyRecord;
use Wonder\Plugin\CustomerAccount\Support\ContactEvents;
use Wonder\Sql\Query;

$seed = static fn (): array => [
    'companies' => [
        1 => ['id' => 1, 'business_name' => 'Steli Real Estate srl', 'deleted' => 'false', 'active' => 'true'],
        2 => ['id' => 2, 'business_name' => 'Royal Immobili srl', 'deleted' => 'false', 'active' => 'true'],
    ],
    'company_brands' => [
        1 => ['id' => 1, 'name' => 'RClass', 'deleted' => 'false'],
        2 => ['id' => 2, 'name' => 'La Piana', 'deleted' => 'false'],
        3 => ['id' => 3, 'name' => 'Vecchio', 'deleted' => 'true'],
    ],
    'company_brand_links' => [],
];

check('findOrCreate: riusa il brand con lo stesso nome, ne crea uno nuovo', function () use ($seed) {
    Query::reset($seed());
    $same = CompanyBrands::findOrCreate('  rclass ');
    $new = CompanyBrands::findOrCreate('Villa   Priula');

    return $same['id'] === 1 && $same['created'] === false
        && $new['created'] === true && $new['id'] === 4
        && (Query::$tables['company_brands'][4]['name'] ?? '') === 'Villa Priula'
        && CompanyBrands::findOrCreate('   ') === null;
});

check('sync: un brand su più aziende, ordine, stacco e brand cancellati ignorati', function () use ($seed) {
    Query::reset($seed());
    $events = [];
    ContactEvents::reset();
    foreach ([CompanyEvents::BRAND_LINKED, CompanyEvents::BRAND_UNLINKED] as $event) {
        CompanyEvents::listen($event, function (string $event, array $payload) use (&$events) { $events[] = $event.':'.$payload['brand_id']; });
    }

    $first = CompanyBrands::sync(1, ['2', 1, 1, 3, 99]);
    CompanyBrands::sync(2, [1]);
    $order = CompanyBrands::idsOf(1);
    $after = CompanyBrands::sync(1, [1]);

    return $first === [2, 1] && $order === [2, 1] && $after === [1]
        && array_column(CompanyBrands::companiesOf(1), 'id') === [1, 2]
        && CompanyBrands::belongsTo(1, 2) && !CompanyBrands::belongsTo(2, 1)
        && $events === ['company.brand_linked:2', 'company.brand_linked:1', 'company.brand_linked:1', 'company.brand_unlinked:2'];
    ContactEvents::reset();
});

check('options: solo i brand vivi, con le entità decodificate', function () use ($seed) {
    $tables = $seed();
    $tables['company_brands'][4] = ['id' => 4, 'name' => 'Rossi &amp; Figli', 'deleted' => 'false'];
    Query::reset($tables);
    $options = CompanyBrands::options();

    return !isset($options[3]) && ($options[4] ?? '') === 'Rossi & Figli' && count($options) === 3;
});

check('quickCreateStore: crea o seleziona quello esistente', function () use ($seed) {
    Query::reset($seed());
    $new = CompanyBrandResource::quickCreateStore(['name' => 'Albergo Villa Priula'], 'name');
    $old = CompanyBrandResource::quickCreateStore(['name' => 'RCLASS'], 'name');
    $empty = CompanyBrandResource::quickCreateStore(['name' => ''], 'name');

    return $new['id'] === 4 && $new['label'] === 'Albergo Villa Priula'
        && $old['id'] === 1 && ($old['message'] ?? '') !== ''
        && isset($empty['error']);
});

check('CompanyResource: il brand è il primo campo, si salva solo se il form l\'ha mostrato', function () use ($seed) {
    Query::reset($seed());
    $names = array_map(static fn ($field) => $field->name, CompanyResource::formSchema());
    $stripped = CompanyResource::stripRelationInputValues(['business_name' => 'X', 'brands' => [1], 'company_brands_form' => '1']);

    CompanyResource::syncRepeaterRelations(1, ['brands' => [1, 2], 'company_brands_form' => '1']);
    $saved = CompanyBrands::idsOf(1);
    CompanyResource::syncRepeaterRelations(1, []);
    $untouched = CompanyBrands::idsOf(1);
    CompanyResource::syncRepeaterRelations(1, ['company_brands_form' => '1']);

    return $names[0] === 'brands' && $names[1] === 'company_brands_form'
        && !isset($stripped['brands']) && !isset($stripped['company_brands_form'])
        && $saved === [1, 2] && $untouched === [1, 2] && CompanyBrands::idsOf(1) === []
        && CompanyResource::withBrandValues(['id' => 2])['brands'] === [];
});

check('CompanyRecord: i brand nel record per export e sync', function () use ($seed) {
    Query::reset($seed());
    CompanyBrands::sync(1, [2, 1]);
    $record = CompanyRecord::from(1);

    return $record['brands'] === [['id' => 2, 'name' => 'La Piana'], ['id' => 1, 'name' => 'RClass']];
});

summary();
