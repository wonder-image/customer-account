<?php
/** php tests/CompanyBrandsTest.php — brand delle aziende: codice e slug, doppioni, legame N:N con ruolo e storia, unione, archivio, eventi, form, record. */
declare(strict_types=1);

require __DIR__.'/support/FakeDb.php';
require __DIR__.'/bootstrap.php';

use Wonder\Plugin\CustomerAccount\Migrations\CompanyBrandCodesMigration;
use Wonder\Plugin\CustomerAccount\Models\CompanyBrandLink;
use Wonder\Plugin\CustomerAccount\Resources\CompanyBrandResource;
use Wonder\Plugin\CustomerAccount\Resources\CompanyResource;
use Wonder\Plugin\CustomerAccount\Support\Commissioner;
use Wonder\Plugin\CustomerAccount\Support\CompanyBrands;
use Wonder\Plugin\CustomerAccount\Support\CompanyEvents;
use Wonder\Plugin\CustomerAccount\Support\CompanyRecord;
use Wonder\Plugin\CustomerAccount\Support\ContactEvents;
use Wonder\Sql\Query;

$brand = static fn (int $id, string $name, string $slug, string $key, array $extra = []): array
    => $extra + ['id' => $id, 'code' => 'cbr_'.$slug, 'name' => $name, 'slug' => $slug, 'name_key' => $key, 'archived' => 'false', 'deleted' => 'false'];
$seed = static fn (): array => [
    'companies' => [
        1 => ['id' => 1, 'business_name' => 'Steli Real Estate srl', 'deleted' => 'false', 'active' => 'true'],
        2 => ['id' => 2, 'business_name' => 'Royal Immobili srl', 'deleted' => 'false', 'active' => 'true'],
    ],
    'contacts' => [
        5 => ['id' => 5, 'name' => 'Anna', 'surname' => 'Bianchi', 'deleted' => 'false'],
    ],
    'company_brands' => [
        1 => $brand(1, 'RClass', 'rclass', 'rclass'),
        2 => $brand(2, 'La Piana', 'la-piana', 'lapiana'),
        3 => $brand(3, 'Vecchio', 'vecchio', 'vecchio', ['deleted' => 'true']),
    ],
    'company_brand_links' => [],
    'contact_relations' => [],
    'contact_relation_brands' => [],
];
$link = static fn (int $id, int $company, int $brand, array $extra = []): array
    => $extra + ['id' => $id, 'company_id' => $company, 'brand_id' => $brand, 'role' => 'owner', 'is_default' => 'false', 'position' => 0, 'ended_at' => null, 'creation' => '2026-01-01 00:00:00', 'deleted' => 'false'];
// Gli eventi dei brand ricevuti, in ordine: [evento, payload].
$events = static function (): void {
    $GLOBALS['__brand_events'] = [];
    ContactEvents::reset();
    foreach ([CompanyEvents::BRAND_LINKED, CompanyEvents::BRAND_UNLINKED, CompanyEvents::BRAND_MERGED] as $event) {
        CompanyEvents::listen($event, function (string $event, array $payload) { $GLOBALS['__brand_events'][] = [$event, $payload]; });
    }
};

check('findOrCreate: riusa il brand con lo stesso nome, ne crea uno nuovo con codice, slug e chiave', function () use ($seed) {
    Query::reset($seed());
    $same = CompanyBrands::findOrCreate('  rclass ');
    $new = CompanyBrands::findOrCreate('Villa   Priula');
    $row = Query::$tables['company_brands'][4] ?? [];

    return $same['id'] === 1 && $same['created'] === false
        && $new['created'] === true && $new['id'] === 4
        && $row['name'] === 'Villa Priula' && $row['slug'] === 'villa-priula' && $row['name_key'] === 'villapriula'
        && preg_match('/^cbr_[a-z0-9]{7}$/', $row['code']) === 1 && $row['archived'] === 'false'
        && CompanyBrands::findOrCreate('   ') === null;
});

check('slug: univoco anche fra gli omonimi e le righe cancellate, senza caratteri pericolosi', function () use ($seed, $brand) {
    $tables = $seed();
    $tables['company_brands'][4] = $brand(4, 'La Piana', 'la-piana-1', 'lapiana', ['deleted' => 'true']);
    Query::reset($tables);

    $values = CompanyBrandResource::mutateRequestValues(['name' => 'La  Piana.', 'slug' => 'scelto-da-me', 'code' => 'x'], 'store');

    return CompanyBrands::slugFor('La Piana') === 'la-piana-2'
        && CompanyBrands::slugFor('La Piana', 2) === 'la-piana'
        && CompanyBrands::slugFor('<script>alert("x")</script> Caffè') === 'scriptalertxscript-caffe'
        && CompanyBrands::slugFor('★★') === 'brand'
        && $values['slug'] === 'la-piana-2' && !isset($values['code']) && $values['name_key'] === 'lapiana';
});

check('migrazione: riempie codice, slug e chiave dei brand vecchi; rilanciata non fa niente', function () {
    Query::reset(['company_brands' => [
        1 => ['id' => 1, 'name' => 'La Piana', 'deleted' => 'false'],
        2 => ['id' => 2, 'name' => 'la piana', 'deleted' => 'false'],
        3 => ['id' => 3, 'name' => 'Rossi &amp; Figli', 'code' => 'cbr_tenuto', 'slug' => 'rossi', 'deleted' => 'true'],
    ]]);
    $first = CompanyBrandCodesMigration::backfill();
    $after = Query::$tables['company_brands'];
    $second = CompanyBrandCodesMigration::backfill();

    return $first === 3 && $second === 0 && Query::$tables['company_brands'] === $after
        && $after[1]['slug'] === 'la-piana' && $after[2]['slug'] === 'la-piana-1'
        && str_starts_with($after[1]['code'], 'cbr_') && $after[1]['code'] !== $after[2]['code']
        && $after[3]['code'] === 'cbr_tenuto' && $after[3]['slug'] === 'rossi' && $after[3]['name_key'] === 'rossifigli';
});

check('sync: un brand su più aziende, ordine, stacco e brand cancellati ignorati', function () use ($seed, $events) {
    Query::reset($seed());
    $events();
    $first = CompanyBrands::sync(1, ['2', 1, 1, 3, 99]);
    CompanyBrands::sync(2, [1]);
    $order = CompanyBrands::idsOf(1);
    $after = CompanyBrands::sync(1, [1]);
    $names = array_map(static fn (array $e): string => $e[0].':'.$e[1]['brand_id'].':'.$e[1]['role'], $GLOBALS['__brand_events']);
    ContactEvents::reset();

    return $first === [2, 1] && $order === [2, 1] && $after === [1]
        && array_column(CompanyBrands::companiesOf(1), 'id') === [1, 2]
        && CompanyBrands::belongsTo(1, 2) && !CompanyBrands::belongsTo(2, 1)
        && $names === ['company.brand_linked:2:owner', 'company.brand_linked:1:owner', 'company.brand_linked:1:owner', 'company.brand_unlinked:2:owner'];
});

check('legame: staccare chiude la riga, ricollegare riapre la stessa', function () use ($seed, $events) {
    Query::reset($seed());
    CompanyBrands::sync(1, [1, 2]);
    $id = (int) array_values(array_filter(Query::$tables['company_brand_links'], static fn ($r) => $r['brand_id'] === 1))[0]['id'];
    CompanyBrands::sync(1, [2]);
    $closed = Query::$tables['company_brand_links'][$id];
    $events();
    CompanyBrands::sync(1, [2, 1]);
    $reopened = Query::$tables['company_brand_links'][$id];
    ContactEvents::reset();

    return count(Query::$tables['company_brand_links']) === 2
        && $closed['ended_at'] !== null && $closed['deleted'] === 'false'
        && $reopened['ended_at'] === null && $reopened['position'] === 1
        && CompanyBrands::idsOf(1) === [2, 1]
        && array_map(static fn (array $e): string => $e[0], $GLOBALS['__brand_events']) === ['company.brand_linked'];
});

check('belongsTo: con una data vale il legame aperto in quel momento', function () use ($seed, $link) {
    $tables = $seed();
    $tables['company_brand_links'][1] = $link(1, 1, 1, ['ended_at' => '2026-06-01 00:00:00']);
    $tables['company_brand_links'][2] = $link(2, 1, 2);
    Query::reset($tables);

    return CompanyBrands::belongsTo(1, 1, '2026-03-01') && CompanyBrands::belongsTo(1, 1, '2026-05-31 23:59:59')
        && !CompanyBrands::belongsTo(1, 1, '2026-06-01 00:00:00') && !CompanyBrands::belongsTo(1, 1, '2025-12-31')
        && !CompanyBrands::belongsTo(1, 1) && !CompanyBrands::belongsTo(1, 1, 'non una data')
        && CompanyBrands::belongsTo(2, 1) && CompanyBrands::belongsTo(2, 1, '2030-01-01') && !CompanyBrands::belongsTo(2, 2);
});

check('predefinito: uno solo per azienda, tenuto dalla lista di id, ripiego sul primo', function () use ($seed) {
    Query::reset($seed());
    CompanyBrands::sync(1, [['brand_id' => 1, 'is_default' => 'true'], ['brand_id' => 2, 'is_default' => 'true']]);
    $defaults = array_column(array_filter(Query::$tables['company_brand_links'], static fn ($r) => $r['is_default'] === 'true'), 'brand_id');
    CompanyBrands::sync(1, [2, 1]);
    $kept = CompanyBrands::defaultOf(1);
    CompanyBrands::sync(1, [['brand_id' => 2], ['brand_id' => 1, 'is_default' => 'false']]);

    return $defaults === [1] && $kept === 1 && CompanyBrands::defaultOf(1) === 2 && CompanyBrands::defaultOf(2) === 0;
});

check('ruoli: lista di base, ruolo sconosciuto tiene il precedente, aggiunta da un modulo', function () use ($seed) {
    Query::reset($seed());
    $base = array_keys(CompanyBrands::roles());
    CompanyBrands::sync(1, [['brand_id' => 1, 'role' => 'licensee'], ['brand_id' => 2, 'role' => 'boss']]);
    CompanyBrands::sync(1, [['brand_id' => 1, 'role' => 'boss'], 2]);
    $roles = array_column(CompanyBrands::linksOf(1), 'role', 'brand_id');
    CompanyBrands::addRole('Supplier', 'Fornitore');
    CompanyBrands::addRole('no spazi', 'X');
    CompanyBrands::sync(1, [['brand_id' => 1, 'role' => 'supplier']]);

    return $base === CompanyBrandLink::ROLES && $base === ['owner', 'licensee', 'intermediary']
        && $roles === [1 => 'licensee', 2 => 'owner']
        && CompanyBrands::linksOf(1)[0]['role'] === 'supplier' && CompanyBrands::roleLabel('supplier') === 'Fornitore'
        && CompanyBrands::role('no spazi') === '' && CompanyBrands::roleLabel('intermediary') === 'contacts.brand_role_intermediary';
});

check('merge: sposta i legami, tiene la coppia già presente, sposta i referenti, archivia, avvisa', function () use ($seed, $link, $events) {
    $tables = $seed();
    $tables['company_brand_links'] = [
        1 => $link(1, 1, 1, ['is_default' => 'true']),
        2 => $link(2, 2, 1, ['is_default' => 'true']),
        3 => $link(3, 2, 2, ['ended_at' => '2026-02-01 00:00:00', 'position' => 1]),
    ];
    $tables['contact_relation_brands'] = [
        1 => ['id' => 1, 'relation_id' => 7, 'brand_id' => 1, 'deleted' => 'false'],
        2 => ['id' => 2, 'relation_id' => 8, 'brand_id' => 1, 'deleted' => 'false'],
        3 => ['id' => 3, 'relation_id' => 8, 'brand_id' => 2, 'deleted' => 'false'],
    ];
    Query::reset($tables);
    $events();
    $ok = CompanyBrands::merge(1, 2);
    $links = Query::$tables['company_brand_links'];
    ContactEvents::reset();

    return $ok
        && $links[1]['brand_id'] === 2 && $links[1]['is_default'] === 'true'
        && $links[2]['brand_id'] === 1 && $links[2]['ended_at'] !== null && $links[2]['is_default'] === 'false'
        && $links[3]['ended_at'] === null && $links[3]['is_default'] === 'true'
        && CompanyBrands::idsOf(2) === [2] && CompanyBrands::defaultOf(2) === 2
        && Query::$tables['company_brands'][1]['archived'] === 'true'
        && array_column(Query::$tables['contact_relation_brands'], 'brand_id', 'relation_id') === [7 => 2, 8 => 2]
        && count($GLOBALS['__brand_events']) === 1 && $GLOBALS['__brand_events'][0][0] === CompanyEvents::BRAND_MERGED
        && $GLOBALS['__brand_events'][0][1]['from_id'] === 1 && $GLOBALS['__brand_events'][0][1]['into_id'] === 2 && (int) $GLOBALS['__brand_events'][0][1]['brand']['id'] === 2
        && !CompanyBrands::merge(2, 1) && !CompanyBrands::merge(2, 2) && !CompanyBrands::merge(3, 2);
});

check('archivio: fuori dalle scelte tranne dove è legato, leggibile, non si lega da nuovo', function () use ($seed, $link) {
    $tables = $seed();
    $tables['company_brand_links'][1] = $link(1, 1, 2);
    Query::reset($tables);
    $archived = CompanyBrands::archive(2);
    $all = CompanyBrands::options();
    $mine = CompanyBrands::options(1);
    $other = CompanyBrands::options(2);
    $kept = CompanyBrands::sync(1, [2, 1]);
    $refused = CompanyBrands::sync(2, [2, 1]);
    $restored = CompanyBrands::restore(2);

    return $archived && isset($all[2]) && isset($mine[2]) && !isset($other[2])
        && str_contains($mine[2], 'La Piana') && CompanyBrands::find(2) !== null
        && $kept === [2, 1] && $refused === [1]
        && $restored && isset(CompanyBrands::options(2)[2]) && !CompanyBrands::archive(99);
});

check('options: solo i brand vivi, con le entità decodificate', function () use ($seed, $brand) {
    $tables = $seed();
    $tables['company_brands'][4] = $brand(4, 'Rossi &amp; Figli', 'rossi-figli', 'rossifigli');
    $tables['company_brands'][5] = $brand(5, 'Chiuso', 'chiuso', 'chiuso', ['archived' => 'true']);
    Query::reset($tables);
    $options = CompanyBrands::options();

    return !isset($options[3]) && !isset($options[5]) && ($options[4] ?? '') === 'Rossi & Figli' && count($options) === 3;
});

check('similar: maiuscole, spazi e punteggiatura non contano; esclusi sé stesso e gli archiviati', function () use ($seed, $brand) {
    $tables = $seed();
    $tables['company_brands'][4] = $brand(4, 'La-Piana', 'la-piana-1', 'lapiana', ['archived' => 'true']);
    Query::reset($tables);

    return array_column(CompanyBrands::similar('LaPiana'), 'id') === [2]
        && array_column(CompanyBrands::similar('la piana!'), 'id') === [2]
        && CompanyBrands::similar('La Piana', 2) === [] && CompanyBrands::similar('...') === []
        && CompanyBrands::byName('LaPiana') === null && (int) CompanyBrands::byName('LA  PIANA')['id'] === 2;
});

check('quickCreateStore: crea o seleziona quello esistente, avvisa dei simili', function () use ($seed) {
    Query::reset($seed());
    $new = CompanyBrandResource::quickCreateStore(['name' => 'Albergo Villa Priula'], 'name');
    $old = CompanyBrandResource::quickCreateStore(['name' => 'RCLASS'], 'name');
    $twin = CompanyBrandResource::quickCreateStore(['name' => 'LaPiana'], 'name');
    $empty = CompanyBrandResource::quickCreateStore(['name' => ''], 'name');

    return $new['id'] === 4 && $new['label'] === 'Albergo Villa Priula' && $new['message'] === ''
        && $old['id'] === 1 && $old['message'] === 'contacts.brand_existing'
        && $twin['id'] === 5 && $twin['message'] === 'contacts.brand_similar'
        && isset($empty['error']);
});

check('form del brand: campi accettati, archiviazione solo in modifica, nome identico rifiutato, avviso dei simili', function () use ($seed) {
    Query::reset($seed());
    $store = CompanyBrandResource::mutateRequestValues(['name' => 'Nuovo', 'archived' => 'true', 'deleted' => 'true'], 'store');
    $update = CompanyBrandResource::mutateRequestValues(['name' => 'La Piana', 'archived' => 'true', 'slug' => 'altro'], 'update', 'backend', ['id' => 2, 'archived' => 'false']);
    $kept = CompanyBrandResource::mutateRequestValues(['name' => 'La Piana'], 'update', 'backend', ['id' => 2, 'archived' => 'true']);

    try {
        CompanyBrandResource::mutateRequestValues(['name' => 'la piana'], 'update', 'backend', ['id' => 1]);
        $taken = false;
    } catch (InvalidArgumentException) {
        $taken = true;
    }

    return $store === ['name' => 'Nuovo', 'archived' => 'false', 'name_key' => 'nuovo', 'slug' => 'nuovo']
        && $update === ['name' => 'La Piana', 'archived' => 'true', 'name_key' => 'lapiana']
        && $kept['archived'] === 'true' && $taken
        && CompanyBrandResource::formNotices(['id' => 1, 'name' => 'La.Piana'], 'edit')[0]['level'] === 'warning'
        && CompanyBrandResource::formNotices(['id' => 2, 'name' => 'La Piana'], 'edit') === [];
});

check('CompanyResource: il repeater dei brand è il primo campo, si salva solo se il form l\'ha mostrato', function () use ($seed) {
    Query::reset($seed());
    $names = array_map(static fn ($field) => $field->name, CompanyResource::formSchema());
    $stripped = CompanyResource::stripRelationInputValues(['business_name' => 'X', 'brands' => [1], 'company_brands_form' => '1']);
    $columns = array_map(static fn ($column) => $column->name, (array) CompanyResource::formFieldsByKey()['brands']->get('context')['columns']);

    CompanyResource::syncRepeaterRelations(1, ['brands' => [1, 2], 'company_brands_form' => '1']);
    $saved = CompanyBrands::idsOf(1);
    CompanyResource::syncRepeaterRelations(1, []);
    $untouched = CompanyBrands::idsOf(1);
    CompanyResource::syncRepeaterRelations(1, ['company_brands_form' => '1', 'brands' => [
        'row_1' => ['brand_id' => '2', 'role' => 'intermediary', 'is_default' => 'false', 'company_id' => 2],
        'row_2' => ['brand_id' => '', 'role' => 'owner', 'is_default' => 'false'],
        'row_3' => ['brand_id' => '1', 'role' => 'owner', 'is_default' => 'true'],
    ]]);
    $rows = CompanyResource::withBrandValues(['id' => 1])['brands'];
    CompanyResource::syncRepeaterRelations(1, ['company_brands_form' => '1']);

    return $names[0] === 'brands' && $names[1] === 'company_brands_form'
        && $columns === ['brand_id', 'role']
        && !isset($stripped['brands']) && !isset($stripped['company_brands_form'])
        && !array_key_exists('brands', CompanyResource::repeaterRelations())
        && $saved === [1, 2] && $untouched === [1, 2]
        && $rows === [['brand_id' => 2, 'role' => 'intermediary'], ['brand_id' => 1, 'role' => 'owner']]
        && CompanyBrands::idsOf(1) === [] && CompanyBrands::idsOf(2) === []
        && CompanyResource::withBrandValues(['id' => 2])['brands'] === [];
});

check('form senza «Predefinito»: il primo brand è il predefinito, quello di prima resta finché c\'è, poi vale il primo', function () use ($seed) {
    Query::reset($seed());
    $post = static fn (array $ids): array => ['company_brands_form' => '1', 'brands' => array_map(
        static fn (int $id): array => ['brand_id' => (string) $id, 'role' => 'owner'],
        $ids
    )];
    CompanyResource::syncRepeaterRelations(1, $post([2, 1]));
    $first = CompanyBrands::defaultOf(1);
    // Scelto da codice: il form che non lo posta lo tiene, anche in un altro ordine.
    CompanyBrands::sync(1, [['brand_id' => 2], ['brand_id' => 1, 'is_default' => 'true']]);
    CompanyResource::syncRepeaterRelations(1, $post([2, 1]));
    $kept = CompanyBrands::defaultOf(1);
    // Tolto il predefinito, vale il primo rimasto.
    CompanyResource::syncRepeaterRelations(1, $post([2]));

    return $first === 2 && $kept === 1 && CompanyBrands::defaultOf(1) === 2;
});

check('Commissioner: brand, poi ragione sociale, poi persona', function () use ($seed) {
    $tables = $seed();
    $tables['company_brands'][2]['name'] = 'La Piana &amp; C.';
    Query::reset($tables);

    return Commissioner::label(2, 1, 5) === 'La Piana & C.'
        && Commissioner::label(3, 1, 5) === 'Steli Real Estate srl'
        && Commissioner::label(0, 9, 5) === 'Anna Bianchi'
        && Commissioner::label(0, 0, 0) === '';
});

check('CompanyRecord: i brand legati con codice, slug, ruolo, predefinito e brand principale; la capogruppo', function () use ($seed) {
    $tables = $seed();
    $tables['company_brands'][2]['parent_id'] = 1;
    $tables['companies'][1]['parent_id'] = 2;
    $tables['companies'][2]['code'] = 'cmp_royal';
    Query::reset($tables);
    CompanyBrands::sync(1, [['brand_id' => 2, 'role' => 'licensee'], ['brand_id' => 1, 'is_default' => 'true']]);
    $record = CompanyRecord::from(1);
    $parent = CompanyRecord::from(2);

    $base = array_flip(['id', 'code', 'slug', 'name', 'role', 'is_default', 'parent_id', 'parent_code']);

    return $record['schema'] === 6 && array_map(static fn (array $brand): array => array_intersect_key($brand, $base), $record['brands']) === [
        ['id' => 2, 'code' => 'cbr_la-piana', 'slug' => 'la-piana', 'name' => 'La Piana', 'role' => 'licensee', 'is_default' => false, 'parent_id' => 1, 'parent_code' => 'cbr_rclass'],
        ['id' => 1, 'code' => 'cbr_rclass', 'slug' => 'rclass', 'name' => 'RClass', 'role' => 'owner', 'is_default' => true, 'parent_id' => 0, 'parent_code' => ''],
    ] && $record['brands'][0]['website'] === '' && $record['brands'][0]['socials'] === [] && $record['brands'][0]['colors'] === []
        && $record['parent_id'] === 2 && $record['parent_code'] === 'cmp_royal'
        && $parent['parent_id'] === 0 && $parent['parent_code'] === '' && json_encode($record) !== false;
});

check('linee: un livello solo, ogni rifiuto, familyIds, fullLabel e options', function () use ($seed, $brand) {
    $tables = $seed();
    $tables['company_brands'][4] = $brand(4, 'Chiuso', 'chiuso', 'chiuso', ['archived' => 'true']);
    $tables['company_brands'][5] = $brand(5, 'Piana Mare', 'piana-mare', 'pianamare');
    Query::reset($tables);
    $ok = CompanyBrands::setParent(5, 2);
    $problems = [
        CompanyBrands::parentProblem(1, 1),   // sé stesso
        CompanyBrands::parentProblem(1, 99),  // non esiste
        CompanyBrands::parentProblem(1, 3),   // cancellato
        CompanyBrands::parentProblem(1, 4),   // archiviato
        CompanyBrands::parentProblem(1, 5),   // il padre è una linea
        CompanyBrands::parentProblem(2, 1),   // ha già linee
        CompanyBrands::parentProblem(0, 2),   // brand nuovo sotto un padre: va bene
        CompanyBrands::parentProblem(5, 0),   // togliere il padre: va bene
    ];
    $refused = !CompanyBrands::setParent(2, 1) && !CompanyBrands::setParent(99, 1) && (int) (Query::$tables['company_brands'][2]['parent_id'] ?? 0) === 0;
    $options = CompanyBrands::options();
    CompanyBrands::archive(2);
    $kept = (int) Query::$tables['company_brands'][5]['parent_id'];

    return $ok && $refused && $problems === ['self', 'missing', 'missing', 'archived', 'nested', 'has_children', '', '']
        && CompanyBrands::familyIds(2) === [2, 5] && CompanyBrands::familyIds(5) === [5] && CompanyBrands::familyIds(99) === []
        && (int) CompanyBrands::parentOf(5)['id'] === 2 && CompanyBrands::parentOf(2) === null
        && array_column(CompanyBrands::childrenOf(2), 'id') === [5]
        && CompanyBrands::fullLabel(Query::$tables['company_brands'][5]) === 'La Piana › Piana Mare'
        && CompanyBrands::fullLabel(Query::$tables['company_brands'][1]) === 'RClass'
        && $options[5] === 'La Piana › Piana Mare' && array_keys($options) === [2, 5, 1]
        && $kept === 2 && !isset(CompanyBrands::parentOptions()[2]) && !isset(CompanyBrands::parentOptions()[5]);
});

check('form del brand: il brand principale si valida lato server', function () use ($seed, $brand) {
    $tables = $seed();
    $tables['company_brands'][5] = $brand(5, 'Piana Mare', 'piana-mare', 'pianamare', ['parent_id' => 2]);
    Query::reset($tables);
    $ok = CompanyBrandResource::mutateRequestValues(['name' => 'RClass', 'parent_id' => '2'], 'update', 'backend', ['id' => 1, 'name' => 'RClass']);
    $none = CompanyBrandResource::mutateRequestValues(['name' => 'RClass', 'parent_id' => ''], 'update', 'backend', ['id' => 1, 'name' => 'RClass']);
    $errors = [];

    foreach ([['1', 1], ['5', 1], ['1', 2], ['3', 1], ['x', 1], ['-2', 1]] as [$parent, $id]) {
        try {
            CompanyBrandResource::mutateRequestValues(['name' => Query::$tables['company_brands'][$id]['name'], 'parent_id' => $parent], 'update', 'backend', ['id' => $id, 'name' => Query::$tables['company_brands'][$id]['name']]);
            $errors[] = 'ok';
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }
    }

    return $ok['parent_id'] === 2 && $none['parent_id'] === 0
        && $errors === ['contacts.brand_parent_self', 'contacts.brand_parent_nested', 'contacts.brand_parent_has_children', 'contacts.brand_parent_missing', 'contacts.brand_parent_missing', 'contacts.brand_parent_missing'];
});

check('merge con linee: le linee passano all\'arrivo, o al suo padre; mai due livelli', function () use ($seed, $brand) {
    $tables = $seed();
    $tables['company_brands'][4] = $brand(4, 'Linea A', 'linea-a', 'lineaa', ['parent_id' => 1]);
    $tables['company_brands'][5] = $brand(5, 'Linea B', 'linea-b', 'lineab', ['parent_id' => 1]);
    $tables['company_brands'][6] = $brand(6, 'Altro', 'altro', 'altro');
    $tables['company_brands'][7] = $brand(7, 'Linea C', 'linea-c', 'lineac', ['parent_id' => 6]);
    Query::reset($tables);
    $parent = static fn (int $id): int => (int) (Query::$tables['company_brands'][$id]['parent_id'] ?? 0);

    CompanyBrands::merge(1, 2);       // padre in un brand semplice: le linee vanno sotto l'arrivo
    $plain = [$parent(4), $parent(5), $parent(2)];
    CompanyBrands::merge(2, 7);       // l'arrivo è una linea: le linee vanno sotto il suo padre
    $nested = [$parent(4), $parent(5), $parent(7)];
    CompanyBrands::merge(6, 4);       // l'arrivo era una linea della partenza: resta senza padre
    $own = [$parent(4), $parent(5), $parent(7)];

    return $plain === [2, 2, 0] && $nested === [6, 6, 6] && $own === [0, 4, 4];
});

check('Commissioner::resolve: privato, azienda senza brand, brand non suo, persona non referente, niente', function () use ($seed, $link) {
    $tables = $seed();
    $tables['contacts'][6] = ['id' => 6, 'name' => 'Luca', 'surname' => 'Verdi', 'deleted' => 'false'];
    $tables['company_brand_links'][1] = $link(1, 1, 2);
    $tables['contact_relations'][1] = ['id' => 1, 'company_id' => 1, 'person_id' => 5, 'position' => 0, 'deleted' => 'false'];
    Query::reset($tables);

    return Commissioner::resolve(2, 0, 6) === ['brand_id' => 0, 'company_id' => 0, 'person_id' => 6, 'label' => 'Luca Verdi']
        && Commissioner::resolve(0, 2, 0) === ['brand_id' => 0, 'company_id' => 2, 'person_id' => 0, 'label' => 'Royal Immobili srl']
        && Commissioner::resolve(2, 1, 5) === ['brand_id' => 2, 'company_id' => 1, 'person_id' => 5, 'label' => 'La Piana']
        && Commissioner::resolve(1, 1, 5)['brand_id'] === 0
        && Commissioner::resolve(2, 1, 6) === ['brand_id' => 2, 'company_id' => 1, 'person_id' => 0, 'label' => 'La Piana']
        && Commissioner::resolve(2, 9, 99) === null && Commissioner::resolve(0, 0, 0) === null
        && Commissioner::resolve(2, 9, 6) === ['brand_id' => 0, 'company_id' => 0, 'person_id' => 6, 'label' => 'Luca Verdi'];
});

check('findOrCreate: se l\'insert fallisce (un altro «+» con lo stesso nome) riprende il brand appena nato', function () use ($seed, $brand, $events) {
    Query::reset($seed());
    $events();
    Query::$beforeInsert = static function (string $table) use ($brand): void {
        Query::$tables['company_brands'][9] = $brand(9, 'Villa Priula', 'villa-priula', 'villapriula');
        throw new RuntimeException('Duplicate entry');
    };
    $race = CompanyBrands::findOrCreate('villa priula');
    Query::$beforeInsert = static function (): void { throw new RuntimeException('Duplicate entry'); };
    $lost = CompanyBrands::findOrCreate('Nessuno');
    Query::$beforeInsert = null;
    ContactEvents::reset();

    return $race['id'] === 9 && $race['created'] === false && $lost === null;
});

check('sync: un errore annulla tutto il salvataggio e non emette eventi', function () use ($seed, $link, $events) {
    $tables = $seed();
    $tables['company_brand_links'][1] = $link(1, 1, 1);
    Query::reset($tables);
    $before = Query::$tables;
    $events();
    Query::$beforeInsert = static function (string $table): void {
        if ($table === 'company_brand_links') {
            throw new RuntimeException('Duplicate entry');
        }
    };

    try {
        CompanyBrands::sync(1, [2]);
        $thrown = false;
    } catch (RuntimeException) {
        $thrown = true;
    }

    Query::$beforeInsert = null;
    $seen = $GLOBALS['__brand_events'];
    ContactEvents::reset();

    return $thrown && Query::$tables === $before && $seen === [] && CompanyBrands::idsOf(1) === [1];
});

check('belongsTo: una data senza ora vale fino a fine giornata', function () use ($seed, $link) {
    $tables = $seed();
    $tables['company_brand_links'][1] = $link(1, 1, 1, ['creation' => '2026-03-01 10:00:00', 'ended_at' => '2026-03-05 09:00:00']);
    Query::reset($tables);

    return CompanyBrands::belongsTo(1, 1, '2026-03-01') && !CompanyBrands::belongsTo(1, 1, '2026-03-01 09:00')
        && CompanyBrands::belongsTo(1, 1, '2026-03-04') && !CompanyBrands::belongsTo(1, 1, '2026-03-05');
});

check('form del brand: un doppione storico si modifica e si archivia; rinominarlo su un nome preso no', function () use ($seed, $brand) {
    $tables = $seed();
    $tables['company_brands'][4] = $brand(4, 'La Piana', 'la-piana-1', 'lapiana');
    Query::reset($tables);
    $edit = CompanyBrandResource::mutateRequestValues(['name' => 'La Piana', 'note' => 'doppione'], 'update', 'backend', ['id' => 4, 'name' => 'La Piana', 'archived' => 'false']);
    $archive = CompanyBrandResource::mutateRequestValues(['name' => 'la  piana', 'archived' => 'true'], 'update', 'backend', ['id' => 4, 'name' => 'La Piana', 'archived' => 'false']);
    $archivedRename = CompanyBrandResource::mutateRequestValues(['name' => 'RClass', 'archived' => 'true'], 'update', 'backend', ['id' => 4, 'name' => 'La Piana', 'archived' => 'true']);

    try {
        CompanyBrandResource::mutateRequestValues(['name' => 'rclass'], 'update', 'backend', ['id' => 4, 'name' => 'La Piana', 'archived' => 'false']);
        $taken = false;
    } catch (InvalidArgumentException) {
        $taken = true;
    }

    return $edit['note'] === 'doppione' && $archive['archived'] === 'true' && $archivedRename['name'] === 'RClass' && $taken;
});

check('nameKey: mai vuota per un nome di soli simboli, e dentro la colonna anche in cirillico', function () {
    Query::reset(['company_brands' => [
        1 => ['id' => 1, 'name' => '★ ★', 'deleted' => 'false'],
        2 => ['id' => 2, 'name' => '★★', 'deleted' => 'false'],
    ]]);
    CompanyBrandCodesMigration::backfill();
    $again = CompanyBrandCodesMigration::backfill();
    $long = CompanyBrands::nameKey(str_repeat('Москва', 40));
    $encoded = preg_replace_callback('/[^\x{0000}-\x{00FF}]/u', static fn (array $m): string => '&#'.mb_ord($m[0], 'UTF-8').';', $long);

    return CompanyBrands::nameKey('★ ★') === '★★' && $again === 0
        && array_column(CompanyBrands::similar('★★'), 'id') === [1, 2]
        && $long !== '' && strlen($encoded) <= 150 && strlen($encoded) > 140
        && CompanyBrands::nameKey('Москва') === 'москва';
});

summary();
