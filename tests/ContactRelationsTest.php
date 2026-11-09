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

use Wonder\Plugin\CustomerAccount\Resources\CompanyResource;
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
        ['person_id' => '2', 'job_title' => ' Acquisti ', 'is_primary' => 'true', 'receives_documents' => 'on', 'access' => 'buyer'],
        ['person_id' => ''],
        ['person_id' => '2', 'job_title' => 'doppione'],
        ['person_id' => '99'],
        ['person_id' => '3', 'is_primary' => 'true', 'access' => 'boss'],
    ], [], 'company_id', 1, [2, 3]);

    return count($rows) === 2
        && $rows[0] === ['person_id' => 2, 'job_title' => 'Acquisti', 'is_primary' => 'true', 'receives_documents' => 'true', 'access' => 'buyer', 'deleted' => 'false']
        && $rows[1]['person_id'] === 3 && $rows[1]['is_primary'] === 'false' && $rows[1]['access'] === 'none';
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
        'external_references' => [
            1 => ['id' => 1, 'entity_type' => 'company', 'entity_id' => 1, 'provider' => 'hubspot', 'environment' => 'live', 'object_type' => 'company', 'external_id' => 'hs-1', 'synced_at' => '', 'deleted' => 'false'],
            2 => ['id' => 2, 'entity_type' => 'contact', 'entity_id' => 1, 'provider' => 'hubspot', 'environment' => 'live', 'object_type' => 'contact', 'external_id' => 'hs-9', 'synced_at' => '', 'deleted' => 'false'],
        ],
    ]);
    $record = CompanyRecord::from(1);

    return $record['schema'] === CompanyRecord::SCHEMA && $record['entity'] === 'company'
        && $record['business_name'] === 'Rossi srl' && $record['pi'] === '01234567890' && $record['cap'] === ''
        && $record['is_supplier'] === true && !array_key_exists('name', $record)
        && $record['relations'] === [['person_id' => 2, 'job_title' => 'Acquisti', 'is_primary' => true, 'receives_documents' => true, 'access' => 'admin']]
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
        && $record['relations'] === [['company_id' => 1, 'job_title' => '', 'is_primary' => true, 'receives_documents' => false, 'access' => 'buyer']]
        && $record['consents'] === ['newsletter' => 'granted'];
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

summary();
