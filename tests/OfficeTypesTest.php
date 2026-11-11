<?php
/** php tests/OfficeTypesTest.php — tipi ufficio creati a mano: creazione rapida, uso su recapiti e referenti, migrazione dell'etichetta «Fatturazione». */
declare(strict_types=1);

require __DIR__.'/support/FakeDb.php';
require __DIR__.'/bootstrap.php';

use Wonder\Plugin\CustomerAccount\Migrations\PointOfficeTypesMigration;
use Wonder\Plugin\CustomerAccount\Models\CompanyPoint;
use Wonder\Plugin\CustomerAccount\Models\CompanyPointDepartment;
use Wonder\Plugin\CustomerAccount\Models\ContactPoint;
use Wonder\Plugin\CustomerAccount\Models\ContactPointDepartment;
use Wonder\Plugin\CustomerAccount\Resources\OfficeTypeResource;
use Wonder\Plugin\CustomerAccount\Support\BrandData;
use Wonder\Plugin\CustomerAccount\Support\ContactPoints;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;
use Wonder\Plugin\CustomerAccount\Support\OfficeTypes;
use Wonder\Sql\Query;

// Gli helper globali che Repeater::syncRelatedRows() usa, sopra il FakeDb.
if (!function_exists('sqlSelect')) {
    function sqlSelect($table, $condition = null, $limit = null, $order = null, $orderDirection = null, $attributes = '*') { return (new Query)->Select($table, $condition, $limit, $order, $orderDirection, $attributes); }
    function sqlInsert($table, $values) { return (new Query)->Insert($table, $values); }
    function sqlModify($table, $values, $column, $value) { return (new Query)->Update($table, $values, $column, $value); }
    function sqlDelete($table, $condition = null) { return (new Query)->Delete($table, $condition); }
}

$seed = static fn (): array => [
    'contacts' => [
        2 => ['id' => 2, 'name' => 'Anna', 'surname' => 'Bianchi', 'email' => 'anna@rossi.test', 'phone_prefix' => '', 'phone' => '', 'user_id' => 0, 'deleted' => 'false', 'active' => 'true'],
    ],
    'companies' => [
        1 => ['id' => 1, 'business_name' => 'Rossi srl', 'email' => 'info@rossi.test', 'phone_prefix' => '', 'phone' => '', 'deleted' => 'false', 'active' => 'true'],
    ],
    'contact_points' => [],
    'company_points' => [],
    'company_point_departments' => [],
    'contact_point_departments' => [],
    'contact_office_types' => [
        12 => ['id' => 12, 'name' => 'Marketing', 'deleted' => 'false'],
    ],
    'contact_relations' => [],
    'contact_relation_departments' => [],
    'external_references' => [],
];
$writes = static fn (string $table): array => array_values(array_filter(Query::$writes, static fn (array $w): bool => ($w[1] ?? '') === $table));

check('elenco: i tipi creati seguono quelli di serie, con l\'id per chiave', function () use ($seed) {
    Query::reset($seed());
    $all = ContactRelations::departments();

    return $all[12] === 'Marketing' && isset($all['administration'])
        && array_key_last($all) === 12 && OfficeTypes::options() === [12 => 'Marketing']
        && ContactRelations::departmentLabel('12') === 'Marketing';
});

check('creazione rapida: un nome nuovo nasce, uno già creato si riusa, uno di serie o vuoto si rifiuta', function () use ($seed) {
    Query::reset($seed());
    $new = OfficeTypeResource::quickCreateStore(['name' => '  Post   vendita '], 'name');
    $again = OfficeTypeResource::quickCreateStore(['name' => 'post vendita'], 'name');
    $existing = OfficeTypeResource::quickCreateStore(['name' => 'MARKETING'], 'name');
    $builtIn = OfficeTypeResource::quickCreateStore(['name' => ContactRelations::departments()['administration']], 'name');
    $empty = OfficeTypeResource::quickCreateStore(['name' => '  '], 'name');

    return $new['id'] > 12 && $new['label'] === 'Post vendita' && $new['message'] === ''
        && $again['id'] === $new['id'] && $again['message'] !== ''
        && $existing['id'] === 12 && $existing['label'] === 'Marketing'
        && isset($builtIn['error']) && !isset($builtIn['id']) && isset($empty['error'])
        && count(Query::$tables['contact_office_types']) === 2;
});

check('form: rinominare con il nome di un altro tipo, o di uno di serie, si rifiuta; il proprio nome passa', function () use ($seed) {
    Query::reset($seed());
    Query::$tables['contact_office_types'][13] = ['id' => 13, 'name' => 'Logistica interna', 'deleted' => 'false'];
    $refused = static function (array $values, ?array $old): bool {
        try {
            OfficeTypeResource::mutateRequestValues($values, $old === null ? 'store' : 'update', 'backend', $old);
        } catch (InvalidArgumentException) {
            return true;
        }

        return false;
    };

    return $refused(['name' => 'marketing'], ['id' => 13]) && $refused(['name' => ContactRelations::departments()['sales']], null) && $refused(['name' => ''], null)
        && OfficeTypeResource::mutateRequestValues(['name' => ' Marketing ', 'x' => 1], 'update', 'backend', ['id' => 12]) === ['name' => 'Marketing'];
});

check('recapiti: un tipo creato si salva con il suo id su azienda e persona, e salvare di nuovo non scrive niente', function () use ($seed, $writes) {
    Query::reset($seed());
    $company = [
        ['kind' => 'email', 'email' => 'info@rossi.test', 'departments' => ['12', 'administration']],
        ['kind' => 'email', 'email' => 'ordini@rossi.test', 'departments' => [12]],
    ];
    $person = [['kind' => 'email', 'email' => 'anna@rossi.test', 'departments' => ['12']]];
    ContactPoints::syncAll(ContactPoints::COMPANY, 1, $company);
    ContactPoints::syncAll(ContactPoints::CONTACT, 2, $person);
    $form = [
        array_column(ContactPoints::formRows(ContactPoints::all(ContactPoints::COMPANY, 1)), 'departments', 'email'),
        array_column(ContactPoints::formRows(ContactPoints::all(ContactPoints::CONTACT, 2)), 'departments', 'email'),
    ];
    Query::$writes = [];
    ContactPoints::syncAll(ContactPoints::COMPANY, 1, $company);
    ContactPoints::syncAll(ContactPoints::CONTACT, 2, $person);

    return array_map(static fn (array $list): array => array_map('strval', $list), $form[0]) === ['info@rossi.test' => ['12', 'administration'], 'ordini@rossi.test' => ['12']]
        && array_map(static fn (array $list): array => array_map('strval', $list), $form[1]) === ['anna@rossi.test' => ['12']]
        && $writes('company_point_departments') === [] && $writes('contact_point_departments') === []
        && ContactPoints::companyEmailsFor(1, '12') === ['info@rossi.test', 'ordini@rossi.test'];
});

check('recapiti: senza «Principale» il primo resta quello di prima, poi la prima riga; l\'etichetta salvata non si perde', function () use ($seed) {
    $tables = $seed();
    $tables['company_points'][1] = ['id' => 1, 'company_id' => 1, 'kind' => 'email', 'value' => 'ordini@rossi.test', 'phone_prefix' => '', 'label' => 'work', 'position' => 0, 'deleted' => 'false'];
    Query::reset($tables);
    // L'ordine cambia, nessuna riga dice chi è il principale: resta info@.
    ContactPoints::syncAll(ContactPoints::COMPANY, 1, [
        ['kind' => 'email', 'email' => 'ordini@rossi.test'],
        ['kind' => 'email', 'email' => 'info@rossi.test'],
    ]);
    $kept = [Query::$tables['companies'][1]['email'], array_column(ContactPoints::of(ContactPoints::COMPANY, 1), 'label', 'value')];
    // Il principale se ne va: lo diventa la prima riga rimasta.
    ContactPoints::syncAll(ContactPoints::COMPANY, 1, [
        ['kind' => 'email', 'email' => 'nuova@rossi.test'],
        ['kind' => 'email', 'email' => 'ordini@rossi.test'],
    ]);

    return $kept === ['info@rossi.test', ['ordini@rossi.test' => 'work']]
        && Query::$tables['companies'][1]['email'] === 'nuova@rossi.test'
        && array_column(ContactPoints::of(ContactPoints::COMPANY, 1), 'label', 'value') === ['ordini@rossi.test' => 'work'];
});

check('referenti: un tipo creato si salva con il suo id e salvare di nuovo non scrive niente', function () use ($seed, $writes) {
    Query::reset($seed());
    $rows = [['person_id' => '2', 'departments' => ['12', 'sales']]];
    ContactRelations::sync(ContactRelations::COMPANY, 1, $rows);
    $relationId = (int) array_key_first(Query::$tables['contact_relations']);
    $first = array_map('strval', ContactRelations::departmentsOf($relationId));
    Query::$writes = [];
    ContactRelations::sync(ContactRelations::COMPANY, 1, $rows);

    return $first === ['12', 'sales'] && $writes('contact_relation_departments') === []
        && array_column(ContactRelations::peopleFor(1, '12'), 'person_id') === [2];
});

check('brand: la vecchia etichetta «Fatturazione» si legge come Amministrazione; un tipo resta, un\'etichetta libera resta com\'è', function () use ($seed) {
    Query::reset($seed());

    return BrandData::officeType('billing') === 'administration' && BrandData::officeType('sales') === 'sales'
        && BrandData::officeType('12') === '12' && BrandData::officeType('') === '';
});

check('migrazione: «Fatturazione» diventa Amministrazione una volta sola; chi ha già un tipo tiene il suo; le altre etichette restano', function () use ($seed) {
    $tables = $seed();
    $point = static fn (int $id, string $fk, string $value, string $label): array => ['id' => $id, $fk => $fk === 'company_id' ? 1 : 2, 'kind' => 'email', 'value' => $value, 'phone_prefix' => '', 'label' => $label, 'position' => $id, 'deleted' => 'false'];
    $tables['company_points'] = [
        1 => $point(1, 'company_id', 'fatture@rossi.test', 'billing'),
        2 => $point(2, 'company_id', 'ordini@rossi.test', 'billing'),
        3 => $point(3, 'company_id', 'lavoro@rossi.test', 'work'),
    ];
    $tables['company_point_departments'] = [
        1 => ['id' => 1, 'company_id' => 1, 'kind' => 'email', 'value' => 'ordini@rossi.test', 'department' => 'purchasing', 'deleted' => 'false'],
    ];
    $tables['contact_points'] = [1 => $point(1, 'contact_id', 'conti@rossi.test', 'billing')];
    Query::reset($tables);
    $first = [
        PointOfficeTypesMigration::backfill(CompanyPoint::class, CompanyPointDepartment::class, 'company_id'),
        PointOfficeTypesMigration::backfill(ContactPoint::class, ContactPointDepartment::class, 'contact_id'),
    ];
    // Il tipo tolto a mano non torna al giro dopo.
    Query::$tables['contact_point_departments'] = [];
    $second = [
        PointOfficeTypesMigration::backfill(CompanyPoint::class, CompanyPointDepartment::class, 'company_id'),
        PointOfficeTypesMigration::backfill(ContactPoint::class, ContactPointDepartment::class, 'contact_id'),
    ];

    return $first === [1, 1] && $second === [0, 0]
        && array_column(Query::$tables['company_point_departments'], 'department', 'value') === ['ordini@rossi.test' => 'purchasing', 'fatture@rossi.test' => 'administration']
        && array_column(Query::$tables['company_points'], 'label', 'value') === ['fatture@rossi.test' => '', 'ordini@rossi.test' => '', 'lavoro@rossi.test' => 'work']
        && Query::$tables['contact_point_departments'] === [] && Query::$tables['contact_points'][1]['label'] === '';
});

check('migrazione: una lettura o una scrittura fallita ferma l\'aggiornamento', function () use ($seed) {
    $tables = $seed();
    $tables['company_points'][1] = ['id' => 1, 'company_id' => 1, 'kind' => 'email', 'value' => 'fatture@rossi.test', 'phone_prefix' => '', 'label' => 'billing', 'position' => 0, 'deleted' => 'false'];
    $thrown = static function (): bool {
        try {
            PointOfficeTypesMigration::backfill(CompanyPoint::class, CompanyPointDepartment::class, 'company_id');
        } catch (RuntimeException) {
            return true;
        }

        return false;
    };
    Query::reset($tables);
    Query::$failReads = true;
    $read = $thrown();
    Query::$failReads = false;
    Query::reset($tables);
    Query::$failWrites = true;
    $write = $thrown();
    Query::$failWrites = false;

    return $read && $write && Query::$tables['company_points'][1]['label'] === 'billing';
});

summary();
