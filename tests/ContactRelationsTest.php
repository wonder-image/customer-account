<?php
/** php tests/ContactRelationsTest.php — legami aziende/persone: righe pulite, accessi, persone create al volo, sync dei repeater, record. */
declare(strict_types=1);

require __DIR__.'/support/FakeDb.php';
require __DIR__.'/bootstrap.php';

use Wonder\Sql\Query;

// Gli helper globali che Repeater::syncRelatedRows() usa, sopra il FakeDb.
if (!function_exists('sqlSelect')) {
    function sqlSelect($table, $condition = null, $limit = null, $order = null, $orderDirection = null, $attributes = '*') { return (new Query)->Select($table, $condition, $limit, $order, $orderDirection, $attributes); }
    function sqlInsert($table, $values) { return (new Query)->Insert($table, $values); }
    function sqlModify($table, $values, $column, $value) { return (new Query)->Update($table, $values, $column, $value); }
    function sqlDelete($table, $condition = null) { return (new Query)->Delete($table, $condition); }
}
use Wonder\Plugin\CustomerAccount\Resources\ContactResource;
use Wonder\Plugin\CustomerAccount\Support\ContactEvents;
use Wonder\Plugin\CustomerAccount\Support\ContactRecord;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;

use Wonder\Plugin\CustomerAccount\Migrations\RelationDepartmentsMigration;
use Wonder\Plugin\CustomerAccount\Models\ContactRelation;
use Wonder\Plugin\CustomerAccount\Resources\CompanyResource;
use Wonder\Plugin\CustomerAccount\Support\CompanyBrands;
use Wonder\Plugin\CustomerAccount\Support\CompanyRecord;

$row = static fn (int $id, array $extra = []): array => ['id' => $id, 'deleted' => 'false', 'active' => 'true'] + $extra;
// Persone in `contacts`, aziende in `companies`: gli id si possono ripetere fra le due tabelle.
$tables = static fn (): array => [
    'contacts' => [
        2 => $row(2, ['name' => 'Anna', 'surname' => 'Bianchi']),
        3 => $row(3, ['name' => 'Luca', 'surname' => 'Verdi']),
    ],
    'companies' => [
        1 => $row(1, ['business_name' => 'Rossi srl']),
        4 => $row(4, ['business_name' => 'Neri spa']),
    ],
];

check('normalize: scarta vuoti, doppioni e controparti inesistenti; un solo principale', function () {
    $rows = ContactRelations::normalize([
        ['person_id' => '2', 'job_title' => ' Acquisti ', 'department' => 'purchasing', 'is_primary' => 'true', 'receives_documents' => 'on', 'access' => 'buyer'],
        ['person_id' => ''],
        ['person_id' => '2', 'job_title' => 'doppione'],
        ['person_id' => '99'],
        ['person_id' => '3', 'is_primary' => 'true', 'access' => 'boss', 'department' => 'boss'],
    ], [], 'company_id', 1, [2, 3]);

    return count($rows) === 2
        && $rows[0] === ['person_id' => 2, 'job_title' => 'Acquisti', 'is_primary' => 'true', 'receives_documents' => 'true', 'access' => 'buyer', 'email' => '', 'phone_prefix' => '', 'phone' => '', 'deleted' => 'false']
        && $rows[1]['person_id'] === 3 && $rows[1]['is_primary'] === 'false' && $rows[1]['access'] === 'none' && !isset($rows[1]['department']);
});

check('normalize: una coppia già esistente riprende id e accesso, preferendo la riga viva', function () {
    $rows = ContactRelations::normalize(
        [['company_id' => 4]],
        [['id' => 7, 'company_id' => 4, 'deleted' => 'true', 'access' => 'buyer'], ['id' => 9, 'company_id' => 4, 'deleted' => 'false', 'access' => 'admin']],
        'person_id', 2, [4]
    );

    return count($rows) === 1 && $rows[0]['id'] === 9 && $rows[0]['access'] === 'admin';
});

check('flag: solo i valori «veri» diventano true', function () {
    return ContactRelations::flag('true') === 'true' && ContactRelations::flag('1') === 'true' && ContactRelations::flag(true) === 'true'
        && ContactRelations::flag('false') === 'false' && ContactRelations::flag(null) === 'false' && ContactRelations::flag('') === 'false';
});

check('createPerson: nuova persona non cliente, poi la riga punta a lei', function () use ($tables) {
    Query::reset($tables());
    $row = ContactRelations::createPerson(['person_id' => '', 'new_name' => 'Marta', 'new_surname' => 'Gialli']);
    $created = Query::$tables['contacts'][$row['person_id'] ?? 0] ?? [];

    return ($row['person_id'] ?? 0) === 4 && !isset($created['type'])
        && ($created['is_customer'] ?? '') === 'false' && ($created['name'] ?? '') === 'Marta';
});

check('createPerson: con una persona scelta o senza nome non crea nulla', function () use ($tables) {
    Query::reset($tables());
    ContactRelations::createPerson(['person_id' => '2', 'new_name' => 'X']);
    ContactRelations::createPerson(['person_id' => '', 'new_email' => 'solo@example.test']);

    return Query::$writes === [];
});

check('accessOf e companiesOf filtrano per accesso', function () use ($tables) {
    Query::reset($tables() + ['contact_relations' => [
        1 => ['id' => 1, 'company_id' => 1, 'person_id' => 2, 'access' => 'admin', 'position' => 1, 'deleted' => 'false'],
        2 => ['id' => 2, 'company_id' => 4, 'person_id' => 2, 'access' => 'none', 'position' => 2, 'deleted' => 'false'],
    ]]);

    return ContactRelations::accessOf(2, 1) === 'admin' && ContactRelations::accessOf(3, 1) === 'none'
        && array_column(ContactRelations::companiesOf(2, ['buyer', 'admin']), 'company_id') === [1]
        && count(ContactRelations::companiesOf(2)) === 2;
});

check('createPerson: senza email sua prende quella di lavoro della riga; i campi stanno solo lato azienda', function () use ($tables) {
    Query::reset($tables());
    $row = ContactRelations::createPerson(['person_id' => '', 'new_name' => 'Marta', 'email' => ' Marta@Rossi.test ']);
    $own = ContactRelations::createPerson(['person_id' => '', 'new_name' => 'Lia', 'new_email' => 'lia@example.test', 'email' => 'ufficio@rossi.test']);
    $names = static fn (string $resource, string $input) => array_map(
        static fn ($c) => $c->name,
        (array) $resource::formFieldsByKey()[$input]->get('context')['columns']
    );
    $people = $names(CompanyResource::class, CompanyResource::RELATION_PEOPLE);
    $companies = $names(ContactResource::class, ContactResource::RELATION_COMPANIES);

    return (Query::$tables['contacts'][$row['person_id']]['email'] ?? '') === 'marta@rossi.test'
        && (Query::$tables['contacts'][$own['person_id']]['email'] ?? '') === 'lia@example.test'
        && array_slice($people, array_search(ContactRelations::PERSON, $people, true) + 1, 2) === ['new_name', 'new_surname']
        && !in_array('new_name', $companies, true);
});

check('spenti dai test: nessun campo in più nel form', function () {
    ContactRelations::useEnabled(false);
    $keys = array_map(static fn ($input) => $input->get('key') ?? $input->get('name'), ContactResource::formSchema());
    ContactRelations::useEnabled(null);

    return !in_array(ContactResource::RELATION_COMPANIES, $keys, true) && !in_array(ContactResource::RELATION_MARKER, $keys, true);
});

check('sempre accesi: un repeater per lato e il segnaposto', function () use ($tables) {
    Query::reset($tables());

    return ContactRelations::enabled()
        && array_keys(ContactResource::repeaterRelations()) === [ContactResource::RELATION_COMPANIES]
        && array_keys(CompanyResource::repeaterRelations()) === [CompanyResource::RELATION_PEOPLE]
        && count(ContactResource::contactRelationFields()) === 2
        && ContactResource::relationInputName() === ContactResource::RELATION_COMPANIES
        && CompanyResource::relationInputName() === CompanyResource::RELATION_PEOPLE;
});

check('sync dal lato azienda: aggiunge, crea al volo, toglie chi non è più postato', function () use ($tables) {
    Query::reset($tables() + ['contact_relations' => [
        1 => ['id' => 1, 'company_id' => 1, 'person_id' => 3, 'is_primary' => 'true', 'receives_documents' => 'false', 'position' => 1, 'deleted' => 'false'],
    ]]);
    ContactRelations::useEnabled(true);
    CompanyResource::syncRepeaterRelations(1, [
        CompanyResource::RELATION_MARKER => '1',
        CompanyResource::RELATION_PEOPLE => [
            ['person_id' => '2', 'job_title' => 'Acquisti', 'is_primary' => 'true', 'receives_documents' => 'true'],
            ['person_id' => '', 'new_name' => 'Marta', 'new_surname' => 'Gialli'],
        ],
    ]);
    ContactRelations::useEnabled(null);

    $people = array_column(ContactRelations::peopleOf(1), 'person_id');
    $old = Query::$tables['contact_relations'][1];

    return array_map('intval', $people) === [2, 4] && $old['deleted'] === 'true';
});

check('sync senza segnaposto: le righe restano come sono', function () use ($tables) {
    $seed = $tables() + ['contact_relations' => [
        1 => ['id' => 1, 'company_id' => 1, 'person_id' => 3, 'is_primary' => 'true', 'receives_documents' => 'false', 'position' => 1, 'deleted' => 'false'],
    ]];
    Query::reset($seed);
    ContactRelations::useEnabled(true);
    CompanyResource::syncRepeaterRelations(1, ['business_name' => 'Rossi srl']);
    ContactRelations::useEnabled(null);

    return Query::$tables['contact_relations'] === $seed['contact_relations'];
});

check('sync dal lato persona: diventare principale toglie il titolo agli altri', function () use ($tables) {
    Query::reset($tables() + ['contact_relations' => [
        1 => ['id' => 1, 'company_id' => 1, 'person_id' => 3, 'is_primary' => 'true', 'receives_documents' => 'false', 'position' => 1, 'deleted' => 'false'],
    ]]);
    ContactRelations::useEnabled(true);
    ContactResource::syncRepeaterRelations(2, [
        ContactResource::RELATION_MARKER => '1',
        ContactResource::RELATION_COMPANIES => [['company_id' => '1', 'is_primary' => 'true']],
    ]);
    ContactRelations::useEnabled(null);

    $companies = ContactRelations::companiesOf(2);

    return count($companies) === 1 && (int) $companies[0]['company_id'] === 1
        && Query::$tables['contact_relations'][1]['is_primary'] === 'false';
});

check('eventi: sync emette linked e unlinked, createPerson emette created', function () use ($tables) {
    Query::reset($tables() + ['contact_relations' => [
        1 => ['id' => 1, 'company_id' => 1, 'person_id' => 3, 'is_primary' => 'false', 'receives_documents' => 'false', 'position' => 1, 'deleted' => 'false'],
    ]]);
    ContactEvents::reset();
    $seen = [];
    foreach (ContactEvents::EVENTS as $event) {
        ContactEvents::listen($event, function (string $name, array $payload) use (&$seen) {
            $seen[] = $name.':'.($payload['person_id'] ?? $payload['id'] ?? 0);
        });
    }
    ContactRelations::sync(ContactRelations::COMPANY, 1, [
        ['person_id' => '2'],
        ['person_id' => '', 'new_name' => 'Marta'],
    ]);
    ContactEvents::reset();
    sort($seen);

    return $seen === ['contact.created:4', 'contact.linked:2', 'contact.linked:4', 'contact.unlinked:3'];
});

check('eventi: un ascoltatore che lancia non ferma il salvataggio né gli altri', function () use ($tables) {
    Query::reset($tables() + ['contact_relations' => []]);
    ContactEvents::reset();
    $called = false;
    ContactEvents::listen(ContactEvents::LINKED, function () { throw new RuntimeException('crm giù'); });
    ContactEvents::listen(ContactEvents::LINKED, function () use (&$called) { $called = true; });
    $log = ini_set('error_log', '/dev/null');
    ContactRelations::sync(ContactRelations::COMPANY, 1, [['person_id' => '2']]);
    ini_set('error_log', (string) $log);
    ContactEvents::reset();

    return $called && array_map('intval', array_column(ContactRelations::peopleOf(1), 'person_id')) === [2];
});

check('CompanyRecord::from: campi, referenti con accesso, id esterni', function () use ($tables) {
    $seed = $tables();
    $seed['companies'][1] += ['email' => 'info@rossi.test', 'pi' => '01234567890', 'is_supplier' => 'true'];
    Query::reset($seed + [
        'contact_relations' => [
            1 => ['id' => 1, 'company_id' => 1, 'person_id' => 2, 'job_title' => 'Acquisti', 'is_primary' => 'true', 'receives_documents' => 'true', 'access' => 'admin', 'position' => 1, 'deleted' => 'false'],
            2 => ['id' => 2, 'company_id' => 1, 'person_id' => 3, 'is_primary' => 'false', 'receives_documents' => 'false', 'position' => 2, 'deleted' => 'true'],
        ],
        'contact_relation_departments' => [
            1 => ['id' => 1, 'relation_id' => 1, 'department' => 'administration', 'deleted' => 'false'],
            2 => ['id' => 2, 'relation_id' => 1, 'department' => 'purchasing', 'deleted' => 'false'],
        ],
        'external_references' => [
            1 => ['id' => 1, 'entity_type' => 'company', 'entity_id' => 1, 'provider' => 'hubspot', 'environment' => 'live', 'object_type' => 'company', 'external_id' => 'hs-1', 'synced_at' => '', 'deleted' => 'false'],
            2 => ['id' => 2, 'entity_type' => 'contact', 'entity_id' => 1, 'provider' => 'hubspot', 'environment' => 'live', 'object_type' => 'contact', 'external_id' => 'hs-9', 'synced_at' => '', 'deleted' => 'false'],
        ],
    ]);
    $record = CompanyRecord::from(1);

    return $record['schema'] === CompanyRecord::SCHEMA && $record['entity'] === 'company'
        && $record['business_name'] === 'Rossi srl' && $record['pi'] === '01234567890' && $record['cap'] === ''
        && $record['is_supplier'] === true && !array_key_exists('name', $record)
        && $record['relations'] === [['person_id' => 2, 'job_title' => 'Acquisti', 'department' => 'administration', 'departments' => ['administration', 'purchasing'], 'is_primary' => true, 'receives_documents' => true, 'access' => 'admin', 'work_email' => '', 'work_phone_prefix' => '', 'work_phone' => '']]
        && array_column($record['external_ids'], 'external_id') === ['hs-1']
        && json_encode($record) !== false && CompanyRecord::from(99) === null;
});

check('ContactRecord::from: persona con aziende e consensi', function () use ($tables) {
    $seed = $tables();
    $seed['contacts'][2] += ['email' => 'anna@rossi.test', 'user_id' => 8, 'birth_date' => '1990-01-02'];
    Query::reset($seed + [
        'contact_relations' => [
            1 => ['id' => 1, 'company_id' => 1, 'person_id' => 2, 'is_primary' => 'true', 'receives_documents' => 'false', 'access' => 'buyer', 'position' => 1, 'deleted' => 'false'],
        ],
        'external_references' => [],
    ]);
    ContactRecord::useConsents(fn (int $userId): array => $userId === 8 ? ['newsletter' => 'granted'] : []);
    $record = ContactRecord::from(2);
    ContactRecord::useConsents(null);

    return $record['entity'] === 'contact' && $record['name'] === 'Anna' && $record['birth_date'] === '1990-01-02'
        && !array_key_exists('business_name', $record) && $record['user_id'] === 8
        && $record['relations'] === [['company_id' => 1, 'job_title' => '', 'department' => '', 'departments' => [], 'is_primary' => true, 'receives_documents' => false, 'access' => 'buyer', 'work_email' => '', 'work_phone_prefix' => '', 'work_phone' => '']]
        && $record['consents'] === ['newsletter' => 'granted'];
});

check('aree in sync: lista, riga senza la chiave le tiene, [\'\'] le svuota, vecchio `department`, sconosciute scartate', function () use ($tables) {
    Query::reset($tables() + ['contact_relations' => [], 'contact_relation_departments' => []]);
    ContactRelations::sync(ContactRelations::COMPANY, 1, [
        ['person_id' => '2', 'departments' => ['administration', 'boss', 'purchasing', 'administration']],
        ['person_id' => '3', 'department' => 'sales'],
    ]);
    $first = [ContactRelations::departmentsOf(1), ContactRelations::departmentsOf(2)];
    ContactRelations::sync(ContactRelations::COMPANY, 1, [['person_id' => '2'], ['person_id' => '3', 'departments' => ['']]]);
    $second = [ContactRelations::departmentsOf(1), ContactRelations::departmentsOf(2)];
    // Dal lato persona le aree sono le stesse righe.
    ContactRelations::sync(ContactRelations::PERSON, 3, [['company_id' => '1', 'departments' => ['technical']]]);

    return $first === [['administration', 'purchasing'], ['sales']]
        && $second === [['administration', 'purchasing'], []]
        && ContactRelations::departmentsOf(2) === ['technical'] && ContactRelations::departmentsOf(99) === []
        && count(Query::$tables['contact_relation_departments']) === 3;
});

check('aree: lista di base, aggiunta da un modulo, filtro dei referenti con «vuoto = tutte»', function () use ($tables) {
    ContactRelations::addDepartment('Marketing', 'Marketing');
    ContactRelations::addDepartment('no spazi', 'X');
    $keys = array_keys(ContactRelations::departments());
    $seed = $tables();
    $seed['contacts'][5] = ['id' => 5, 'name' => 'Tutto', 'surname' => 'Fare', 'deleted' => 'false'];
    Query::reset($seed + [
        'contact_relations' => [
            1 => ['id' => 1, 'company_id' => 1, 'person_id' => 2, 'position' => 1, 'deleted' => 'false'],
            2 => ['id' => 2, 'company_id' => 1, 'person_id' => 3, 'position' => 2, 'deleted' => 'false'],
            3 => ['id' => 3, 'company_id' => 4, 'person_id' => 3, 'position' => 1, 'deleted' => 'false'],
            4 => ['id' => 4, 'company_id' => 1, 'person_id' => 5, 'position' => 3, 'deleted' => 'false'],
        ],
        'contact_relation_departments' => [
            1 => ['id' => 1, 'relation_id' => 1, 'department' => 'administration', 'deleted' => 'false'],
            2 => ['id' => 2, 'relation_id' => 2, 'department' => 'marketing', 'deleted' => 'false'],
            3 => ['id' => 3, 'relation_id' => 3, 'department' => 'administration', 'deleted' => 'false'],
        ],
    ]);
    $admin = ContactRelations::peopleByDepartment(1, 'administration');
    $both = ContactRelations::peopleByDepartment(1, ['administration', 'marketing']);

    return array_slice($keys, 0, 6) === ['administration', 'purchasing', 'sales', 'technical', 'management', 'other']
        && in_array('marketing', $keys, true) && !in_array('no spazi', $keys, true)
        && ContactRelations::department('marketing') === 'marketing' && ContactRelations::department('boss') === ''
        && ContactRelations::department(['x']) === ''
        && ContactRelations::cleanDepartments(['sales', 'boss', 'sales', ['x'], 'other']) === ['sales', 'other']
        && ContactRelations::cleanDepartments('sales') === ['sales']
        && array_column($admin, 'person_id') === [2, 5] && array_column($both, 'person_id') === [2, 3, 5]
        && $admin[0]['department'] === 'administration' && $admin[1]['departments'] === [] && $admin[1]['department'] === '';
});

check('fromArray tiene i campi noti, validate dà gli errori del tipo', function () {
    $person = ContactRecord::fromArray(['type' => 'business', 'name' => ' Anna ', 'business_name' => 'X', 'email' => 'ANNA@ROSSI.TEST', 'password' => 'no']);
    $company = CompanyRecord::fromArray(['business_name' => ' Rossi ', 'pec' => 'PEC@ROSSI.TEST', 'name' => 'no']);
    $badPerson = ContactRecord::validate(ContactRecord::fromArray(['email' => 'non-email', 'relations' => [['company_id' => 0]]]));
    $badCompany = CompanyRecord::validate(CompanyRecord::fromArray(['pec' => 'x', 'relations' => [['person_id' => 2], ['company_id' => 3]]]));

    return $person === ['name' => 'Anna', 'email' => 'anna@rossi.test']
        && $company === ['business_name' => 'Rossi', 'pec' => 'pec@rossi.test']
        && ContactRecord::validate($person) === [] && CompanyRecord::validate($company) === []
        && $badPerson === ['name' => 'required', 'email' => 'invalid', 'relations.0' => 'invalid']
        && $badCompany === ['business_name' => 'required', 'pec' => 'invalid', 'relations.1' => 'invalid'];
});

// Referenti per brand: l'azienda 1 ha i brand 1 e 2, il brand 3 è solo dell'azienda 4.
$brandTables = static fn (): array => $tables() + [
    'company_brands' => [
        1 => ['id' => 1, 'name' => 'RClass', 'archived' => 'false', 'deleted' => 'false'],
        2 => ['id' => 2, 'name' => 'La Piana', 'archived' => 'false', 'deleted' => 'false'],
        3 => ['id' => 3, 'name' => 'Altro', 'archived' => 'false', 'deleted' => 'false'],
    ],
    'company_brand_links' => [
        1 => ['id' => 1, 'company_id' => 1, 'brand_id' => 1, 'position' => 0, 'ended_at' => null, 'deleted' => 'false'],
        2 => ['id' => 2, 'company_id' => 1, 'brand_id' => 2, 'position' => 1, 'ended_at' => null, 'deleted' => 'false'],
        3 => ['id' => 3, 'company_id' => 4, 'brand_id' => 3, 'position' => 0, 'ended_at' => null, 'deleted' => 'false'],
    ],
    'contact_relations' => [
        1 => ['id' => 1, 'company_id' => 1, 'person_id' => 2, 'position' => 1, 'deleted' => 'false'],
        2 => ['id' => 2, 'company_id' => 1, 'person_id' => 3, 'position' => 2, 'deleted' => 'false'],
    ],
    'contact_relation_brands' => [],
];

check('brand dei referenti: solo brand dell\'azienda del legame, lista vuota = tutti', function () use ($brandTables) {
    Query::reset($brandTables());
    $saved = ContactRelations::setBrands(1, [2, '3', 99, 2]);
    $read = ContactRelations::brandsOf(1);
    $none = ContactRelations::setBrands(99, [1]);
    $cleared = ContactRelations::setBrands(1, []);

    return $saved === [2] && $read === [2] && $none === [] && $cleared === []
        && ContactRelations::brandsOf(1) === [] && Query::$tables['contact_relation_brands'] === [];
});

check('peopleOf con un brand: chi lo segue e chi segue tutti', function () use ($brandTables) {
    Query::reset($brandTables());
    ContactRelations::setBrands(1, [2]);

    return array_column(ContactRelations::peopleOf(1, 1), 'person_id') === [3]
        && array_column(ContactRelations::peopleOf(1, 2), 'person_id') === [2, 3]
        && array_column(ContactRelations::peopleOf(1), 'person_id') === [2, 3];
});

check('repeater dei referenti: colonna «Brand» multipla, valori nel form, riga senza brand_ids li tiene', function () use ($brandTables) {
    Query::reset($brandTables());
    ContactRelations::setBrands(2, [1]);
    $columns = static fn (string $resource, string $input): array => array_map(
        static fn ($column) => $column->name,
        (array) $resource::formFieldsByKey()[$input]->get('context')['columns']
    );
    $brandColumn = array_values(array_filter((array) CompanyResource::formFieldsByKey()[CompanyResource::RELATION_PEOPLE]->get('context')['columns'], static fn ($c) => $c->name === 'brand_ids'))[0];

    CompanyResource::syncRepeaterRelations(1, [
        CompanyResource::RELATION_MARKER => '1',
        CompanyResource::RELATION_PEOPLE => [
            'row_1' => ['id' => '1', 'person_id' => '2', 'brand_ids' => ['', '1', '3']],
            'row_2' => ['id' => '2', 'person_id' => '3'],
        ],
    ]);
    $form = CompanyResource::hydrateRepeaterFormValues([], 1, [], []);

    return in_array('brand_ids', $columns(CompanyResource::class, CompanyResource::RELATION_PEOPLE), true)
        && !in_array('brand_ids', $columns(ContactResource::class, ContactResource::RELATION_COMPANIES), true)
        && $brandColumn->get('multiple') === true
        && ContactRelations::brandsOf(1) === [1] && ContactRelations::brandsOf(2) === [1]
        && array_column($form[CompanyResource::RELATION_PEOPLE], 'brand_ids', 'person_id') === [2 => [1], 3 => [1]];
});

check('brand seguiti orfani: contano solo quelli ancora legati, se non resta niente segue tutti', function () use ($brandTables) {
    $tables = $brandTables();
    $tables['company_brand_links'][2]['ended_at'] = '2026-01-01 00:00:00';
    $tables['contact_relation_brands'] = [1 => ['id' => 1, 'relation_id' => 1, 'brand_id' => 2, 'deleted' => 'false']];
    Query::reset($tables);

    return ContactRelations::brandsOf(1) === [] && ContactRelations::brandsByRelation([1, 2]) === []
        && array_column(ContactRelations::peopleOf(1, 1), 'person_id') === [2, 3]
        && ContactRelations::withBrandIds([['id' => 1, 'person_id' => 2]])[0]['brand_ids'] === [];
});

check('staccare un brand dall\'azienda toglie le righe dei referenti che lo seguivano', function () use ($brandTables) {
    Query::reset($brandTables());
    ContactRelations::setBrands(1, [2]);
    ContactRelations::setBrands(2, [1, 2]);
    CompanyBrands::sync(1, [1]);

    return array_column(Query::$tables['contact_relation_brands'], 'brand_id', 'relation_id') === [2 => 1]
        && array_column(ContactRelations::peopleOf(1, 1), 'person_id') === [2, 3];
});

check('repeater dei referenti: il select multiplo svuotato posta [\'\'] e svuota il filtro', function () use ($brandTables) {
    Query::reset($brandTables());
    ContactRelations::setBrands(1, [1]);
    CompanyResource::syncRepeaterRelations(1, [
        CompanyResource::RELATION_MARKER => '1',
        CompanyResource::RELATION_PEOPLE => [
            'row_1' => ['id' => '1', 'person_id' => '2', 'brand_ids' => ['']],
            'row_2' => ['id' => '2', 'person_id' => '3', 'brand_ids' => ['']],
        ],
    ]);

    return ContactRelations::brandsOf(1) === [] && Query::$tables['contact_relation_brands'] === [];
});

check('peopleFor: area o tutte, brand o tutti, il principale per primo; una lettura delle aree', function () use ($brandTables) {
    $seed = $brandTables();
    $seed['contacts'][5] = ['id' => 5, 'name' => 'Capo', 'surname' => 'Rossi', 'deleted' => 'false'];
    $seed['contact_relations'][3] = ['id' => 3, 'company_id' => 1, 'person_id' => 5, 'is_primary' => 'true', 'position' => 3, 'deleted' => 'false'];
    $seed['contact_relations'][4] = ['id' => 4, 'company_id' => 4, 'person_id' => 2, 'position' => 1, 'deleted' => 'false'];
    $seed['contact_relation_departments'] = [
        1 => ['id' => 1, 'relation_id' => 1, 'department' => 'administration', 'deleted' => 'false'],
        2 => ['id' => 2, 'relation_id' => 2, 'department' => 'sales', 'deleted' => 'false'],
        3 => ['id' => 3, 'relation_id' => 2, 'department' => 'administration', 'deleted' => 'false'],
    ];
    // Anna (1) segue solo il brand 2; Luca (2) tutti; il principale (3) si occupa di tutto.
    $seed['contact_relation_brands'] = [1 => ['id' => 1, 'relation_id' => 1, 'brand_id' => 2, 'deleted' => 'false']];
    Query::reset($seed);
    $ids = static fn (array $rows): array => array_map('intval', array_column($rows, 'person_id'));

    $all = ContactRelations::peopleFor(1);
    $reads = count(array_filter(Query::$selects, static fn (array $q): bool => $q['table'] === 'contact_relation_departments'));

    return $ids($all) === [5, 2, 3] && $reads === 1
        && $ids(ContactRelations::peopleFor(1, 'administration')) === [5, 2, 3]
        && $ids(ContactRelations::peopleFor(1, 'sales')) === [5, 3]
        && $ids(ContactRelations::peopleFor(1, 'administration', 1)) === [5, 3]
        && $ids(ContactRelations::peopleFor(1, 'technical', 2)) === [5]
        && $ids(ContactRelations::peopleFor(4, 'sales')) === [2]
        && ContactRelations::peopleFor(0) === [] && ContactRelations::peopleFor(99, 'sales') === []
        && $all[1]['departments'] === ['administration'] && $all[0]['departments'] === [];
});

check('setDepartments: solo aree note e legami vivi, vuota = tutte', function () use ($brandTables) {
    $seed = $brandTables();
    $seed['contact_relations'][2]['deleted'] = 'true';
    $seed['contact_relation_departments'] = [];
    Query::reset($seed);
    $saved = ContactRelations::setDepartments(1, ['sales', 'boss', 'sales']);
    $dead = ContactRelations::setDepartments(2, ['sales']);
    $missing = ContactRelations::setDepartments(99, ['sales']);
    $read = ContactRelations::departmentsOf(1);
    $cleared = ContactRelations::setDepartments(1, []);

    return $saved === ['sales'] && $read === ['sales'] && $dead === [] && $missing === [] && $cleared === []
        && Query::$tables['contact_relation_departments'] === [];
});

check('repeater dei legami: colonna «Si occupa di» multipla su tutti e due i lati, valori nel form', function () use ($brandTables) {
    $seed = $brandTables();
    $seed['contact_relation_departments'] = [1 => ['id' => 1, 'relation_id' => 2, 'department' => 'sales', 'deleted' => 'false']];
    Query::reset($seed);
    $column = static fn (string $resource, string $input) => array_values(array_filter(
        (array) $resource::formFieldsByKey()[$input]->get('context')['columns'],
        static fn ($c) => $c->name === 'departments'
    ))[0] ?? null;
    $people = $column(CompanyResource::class, CompanyResource::RELATION_PEOPLE);
    $companies = $column(ContactResource::class, ContactResource::RELATION_COMPANIES);
    $form = CompanyResource::hydrateRepeaterFormValues([], 1, [], []);
    $person = ContactResource::hydrateRepeaterFormValues([], 3, [], []);

    CompanyResource::syncRepeaterRelations(1, [
        CompanyResource::RELATION_MARKER => '1',
        CompanyResource::RELATION_PEOPLE => [
            'row_1' => ['id' => '1', 'person_id' => '2', 'departments' => ['', 'administration', 'technical']],
            'row_2' => ['id' => '2', 'person_id' => '3'],
        ],
    ]);

    return $people !== null && $companies !== null && $people->get('multiple') === true && $companies->get('multiple') === true
        && array_column($form[CompanyResource::RELATION_PEOPLE], 'departments', 'person_id') === [2 => [], 3 => ['sales']]
        && $person[ContactResource::RELATION_COMPANIES][0]['departments'] === ['sales']
        && ContactRelations::departmentsOf(1) === ['administration', 'technical'] && ContactRelations::departmentsOf(2) === ['sales'];
});

check('sync: un errore annulla legami e aree e non emette eventi', function () use ($tables) {
    Query::reset($tables() + ['contact_relations' => [], 'contact_relation_departments' => []]);
    $before = Query::$tables;
    ContactEvents::reset();
    $seen = [];
    ContactEvents::listen(ContactEvents::LINKED, function () use (&$seen) { $seen[] = 'linked'; });
    Query::$beforeInsert = static function (string $table): void {
        if ($table === 'contact_relation_departments') {
            throw new RuntimeException('Duplicate entry');
        }
    };

    try {
        ContactRelations::sync(ContactRelations::COMPANY, 1, [['person_id' => '2', 'departments' => ['sales']]]);
        $thrown = false;
    } catch (RuntimeException) {
        $thrown = true;
    }

    Query::$beforeInsert = null;
    ContactEvents::reset();

    return $thrown && $seen === [] && Query::$tables === $before;
});

check('migrazione delle aree: copia il vecchio reparto (normalizzato), salta chi ha già aree; rilanciata non fa niente', function () {
    Query::reset([
        'contact_relations' => [
            1 => ['id' => 1, 'company_id' => 1, 'person_id' => 2, 'department' => 'administration', 'deleted' => 'false'],
            2 => ['id' => 2, 'company_id' => 1, 'person_id' => 3, 'department' => '', 'deleted' => 'false'],
            3 => ['id' => 3, 'company_id' => 4, 'person_id' => 3, 'department' => 'sales', 'deleted' => 'true'],
            4 => ['id' => 4, 'company_id' => 4, 'person_id' => 2, 'department' => 'sales', 'deleted' => 'false'],
            5 => ['id' => 5, 'company_id' => 4, 'person_id' => 5, 'department' => 'x y', 'deleted' => 'false'],
        ],
        'contact_relation_departments' => [
            1 => ['id' => 1, 'relation_id' => 4, 'department' => 'technical', 'deleted' => 'false'],
        ],
    ]);
    $first = RelationDepartmentsMigration::backfill();
    $after = Query::$tables['contact_relation_departments'];
    $second = RelationDepartmentsMigration::backfill();

    return $first === 3 && $second === 0 && Query::$tables['contact_relation_departments'] === $after
        && array_column($after, 'department', 'relation_id') === [4 => 'technical', 1 => 'administration', 3 => 'sales', 5 => 'x_y'];
});

check('migrazione delle aree: reparti fuori formato normalizzati o `other`, mai saltati', function () {
    Query::reset([
        'contact_relations' => [
            1 => ['id' => 1, 'company_id' => 1, 'person_id' => 2, 'department' => ' Customer Care ', 'deleted' => 'false'],
            2 => ['id' => 2, 'company_id' => 1, 'person_id' => 3, 'department' => 'post-vendita', 'deleted' => 'false'],
            3 => ['id' => 3, 'company_id' => 1, 'person_id' => 4, 'department' => 'Uffício €', 'deleted' => 'false'],
            4 => ['id' => 4, 'company_id' => 1, 'person_id' => 5, 'department' => null, 'deleted' => 'false'],
        ],
        'contact_relation_departments' => [],
    ]);
    $copied = RelationDepartmentsMigration::backfill();

    return $copied === 3 && array_column(Query::$tables['contact_relation_departments'], 'department', 'relation_id')
        === [1 => 'customer_care', 2 => 'post_vendita', 3 => 'other'];
});

check('migrazione delle aree: una lettura o una scrittura fallita ferma l\'aggiornamento', function () {
    $seed = [
        'contact_relations' => [1 => ['id' => 1, 'company_id' => 1, 'person_id' => 2, 'department' => 'sales', 'deleted' => 'false']],
        'contact_relation_departments' => [],
    ];
    $thrown = static function (): bool {
        try {
            RelationDepartmentsMigration::backfill();
        } catch (RuntimeException) {
            return true;
        }

        return false;
    };
    Query::reset($seed);
    Query::$failReads = true;
    $read = $thrown();
    Query::reset($seed);
    Query::$failWrites = true;
    $write = $thrown();
    Query::reset();

    return $read && $write;
});

check('niente più `department` nello schema del legame: lo toglie runTables() dopo la migrazione', function () {
    $schema = ContactRelation::rawTableSchema();
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__).'/module.json'), true);
    $migrations = $manifest['database']['migrations'];

    return !array_key_exists('department', $schema) && !array_key_exists('ind_department', $schema)
        && end($migrations) === RelationDepartmentsMigration::class;
});

check('principale dal lato persona: ogni azienda tiene il suo, gli altri referenti di quell\'azienda lo perdono', function () use ($tables) {
    Query::reset($tables() + ['contact_relations' => [
        1 => ['id' => 1, 'company_id' => 1, 'person_id' => 3, 'is_primary' => 'true', 'position' => 1, 'deleted' => 'false'],
        2 => ['id' => 2, 'company_id' => 4, 'person_id' => 3, 'is_primary' => 'true', 'position' => 1, 'deleted' => 'false'],
    ], 'contact_relation_departments' => []]);
    $person = ContactRelations::normalize([['company_id' => 1, 'is_primary' => 'true'], ['company_id' => 4, 'is_primary' => 'true']], [], 'person_id', 2, [1, 4]);
    $company = ContactRelations::normalize([['person_id' => 2, 'is_primary' => 'true'], ['person_id' => 3, 'is_primary' => 'true']], [], 'company_id', 1, [2, 3]);
    ContactRelations::sync(ContactRelations::PERSON, 2, [['company_id' => '1', 'is_primary' => 'true'], ['company_id' => '4', 'is_primary' => 'true']]);
    $primaries = static fn (int $company): array => array_map('intval', array_column(array_filter(
        ContactRelations::peopleOf($company),
        static fn (array $row): bool => $row['is_primary'] === 'true'
    ), 'person_id'));

    return array_column($person, 'is_primary') === ['true', 'true'] && array_column($company, 'is_primary') === ['true', 'false']
        && $primaries(1) === [2] && $primaries(4) === [2];
});

check('attach: crea o riapre un solo legame, accesso none, aree e brand; gli altri legami restano', function () use ($tables) {
    Query::reset($tables() + ['contact_relations' => [
        1 => ['id' => 1, 'company_id' => 1, 'person_id' => 3, 'job_title' => 'Capo', 'is_primary' => 'true', 'access' => 'admin', 'position' => 4, 'deleted' => 'false'],
        2 => ['id' => 2, 'company_id' => 4, 'person_id' => 2, 'job_title' => 'Vecchio', 'access' => 'admin', 'position' => 1, 'deleted' => 'true'],
    ], 'contact_relation_departments' => [], 'contact_relation_brands' => []]);
    ContactEvents::reset();
    $seen = [];
    ContactEvents::listen(ContactEvents::LINKED, function (string $e, array $p) use (&$seen) { $seen[] = $p['company_id'].'-'.$p['person_id']; });
    ContactEvents::listen(ContactEvents::UNLINKED, function (string $e, array $p) use (&$seen) { $seen[] = 'unlinked'; });
    $before = Query::$tables['contact_relations'][1];
    $new = ContactRelations::attach(1, 2, ['job_title' => 'Acquisti', 'departments' => ['sales', 'boss']]);
    $reopened = ContactRelations::attach(4, 2);
    $again = ContactRelations::attach(1, 2, ['job_title' => 'Cambiato']);
    $missing = ContactRelations::attach(99, 2) || ContactRelations::attach(1, 99);
    ContactEvents::reset();
    $created = array_values(array_filter(Query::$tables['contact_relations'], static fn ($r) => (int) $r['company_id'] === 1 && (int) $r['person_id'] === 2))[0];
    $old = Query::$tables['contact_relations'][2];

    return $new && $reopened && $again && !$missing
        && Query::$tables['contact_relations'][1] === $before
        && $created['job_title'] === 'Acquisti' && $created['access'] === 'none' && (int) $created['position'] === 5
        && ContactRelations::departmentsOf((int) $created['id']) === ['sales']
        && $old['deleted'] === 'false' && $old['access'] === 'none' && $old['job_title'] === ''
        && $seen === ['1-2', '4-2'] && count(Query::$tables['contact_relations']) === 3;
});

check('filtro delle controparti: si legano solo le ammesse, le già legate restano; il select le mostra', function () use ($tables) {
    Query::reset($tables() + ['contact_relations' => [
        1 => ['id' => 1, 'company_id' => 1, 'person_id' => 3, 'position' => 1, 'deleted' => 'false'],
    ], 'contact_relation_departments' => []]);
    $calls = [];
    ContactRelations::useCounterpartFilter(function (string $model, array $ids) use (&$calls): array {
        $calls[] = $model;

        return array_values(array_intersect($ids, [2]));
    });
    $valid = ContactRelations::existingCounterparts(ContactRelations::COMPANY, [2, 3, '99', ['x']], 1);
    $fresh = ContactRelations::existingCounterparts(ContactRelations::COMPANY, [3], 0);
    // Il form di Rossi srl: Luca (3) è già legato e resta tra le scelte, anche se il filtro non lo ammette.
    CompanyResource::hydrateRepeaterFormValues([], 1, [], []);
    $options = (array) array_values(array_filter((array) CompanyResource::formFieldsByKey()[CompanyResource::RELATION_PEOPLE]->get('context')['columns'], static fn ($c) => $c->name === 'person_id'))[0]->get('options');
    CompanyResource::hydrateRepeaterFormValues([], 0, [], []);
    $newForm = (array) array_values(array_filter((array) CompanyResource::formFieldsByKey()[CompanyResource::RELATION_PEOPLE]->get('context')['columns'], static fn ($c) => $c->name === 'person_id'))[0]->get('options');
    ContactRelations::sync(ContactRelations::PERSON, 3, [['company_id' => '1'], ['company_id' => '4']]);
    ContactRelations::useCounterpartFilter(null);
    $all = ContactRelations::existingCounterparts(ContactRelations::COMPANY, [2, 3]);

    return $valid === [2, 3] && $fresh === [] && in_array(\Wonder\Plugin\CustomerAccount\Models\Contact::class, $calls, true)
        && array_keys(array_diff_key($options, ['' => true])) === [2, 3] && array_keys(array_diff_key($newForm, ['' => true])) === [2]
        && array_map('intval', array_column(ContactRelations::companiesOf(3), 'company_id')) === [1]
        && $all === [2, 3];
});

check('guardia dell\'accesso: senza permesso non sale oltre quello salvato, scendere si può; senza guardia invariato', function () use ($tables) {
    $existing = [
        ['id' => 1, 'person_id' => 2, 'access' => 'buyer', 'deleted' => 'false'],
        ['id' => 2, 'person_id' => 3, 'access' => 'admin', 'deleted' => 'true'],
    ];
    $asked = [];
    ContactRelations::useAccessGuard(function (int $company, int $person, string $access) use (&$asked): bool {
        $asked[] = "{$company}-{$person}-{$access}";

        return $access === 'buyer';
    });
    $rows = ContactRelations::normalize([
        ['person_id' => 2, 'access' => 'admin'],
        ['person_id' => 3],
        ['person_id' => 4, 'access' => 'buyer'],
    ], $existing, 'company_id', 1, [2, 3, 4]);
    $down = ContactRelations::normalize([['person_id' => 2, 'access' => 'none']], $existing, 'company_id', 1, [2]);
    ContactRelations::useAccessGuard(null);
    $free = ContactRelations::normalize([['person_id' => 2, 'access' => 'admin'], ['person_id' => 3]], $existing, 'company_id', 1, [2, 3]);

    return array_column($rows, 'access') === ['buyer', 'none', 'buyer'] && $asked === ['1-2-admin', '1-3-admin', '1-4-buyer']
        && $down[0]['access'] === 'none' && array_column($free, 'access') === ['admin', 'admin'];
});

check('valori non scalari dalla richiesta: valgono vuoto, senza errori', function () use ($tables) {
    Query::reset($tables() + ['contact_relations' => [], 'contact_relation_departments' => []]);
    $rows = ContactRelations::normalize([['person_id' => ['2'], 'job_title' => ['x']], ['person_id' => '3', 'job_title' => ['x'], 'access' => ['admin']]], [], 'company_id', 1, [2, 3]);
    ContactRelations::sync(ContactRelations::COMPANY, 1, [['person_id' => '2', 'departments' => [['sales']], 'brand_ids' => [['1']]], 'non una riga', ['person_id' => '', 'new_name' => ['x']]]);

    return count($rows) === 1 && $rows[0]['job_title'] === '' && $rows[0]['access'] === 'none'
        && array_map('intval', array_column(ContactRelations::peopleOf(1), 'person_id')) === [2] && ContactRelations::departmentsOf(1) === []
        && count(Query::$tables['contacts']) === 2;
});

summary();
