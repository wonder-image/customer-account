<?php
/** php tests/ContactRelationsTest.php — referenti delle aziende: righe pulite, persone create al volo, sync del repeater e interruttore. */
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

$contact = static fn (int $id, string $type, array $extra = []): array => ['id' => $id, 'type' => $type, 'deleted' => 'false', 'active' => 'true'] + $extra;
$contacts = static fn (): array => [
    1 => $contact(1, 'business', ['business_name' => 'Rossi srl']),
    2 => $contact(2, 'private', ['name' => 'Anna', 'surname' => 'Bianchi']),
    3 => $contact(3, 'private', ['name' => 'Luca', 'surname' => 'Verdi']),
    4 => $contact(4, 'business', ['business_name' => 'Neri spa']),
];

check('normalize: scarta vuoti, sé stessi, doppioni e tipi sbagliati; un solo principale', function () {
    $rows = ContactRelations::normalize([
        ['person_id' => '2', 'job_title' => ' Acquisti ', 'is_primary' => 'true', 'receives_documents' => 'on'],
        ['person_id' => ''],
        ['person_id' => '2', 'job_title' => 'doppione'],
        ['person_id' => '4'],
        ['person_id' => '1'],
        ['person_id' => '3', 'is_primary' => 'true'],
    ], [], 'company_id', 1, [2 => 'private', 3 => 'private', 4 => 'business', 1 => 'business']);

    return count($rows) === 2
        && $rows[0] === ['person_id' => 2, 'job_title' => 'Acquisti', 'is_primary' => 'true', 'receives_documents' => 'true', 'deleted' => 'false']
        && $rows[1]['person_id'] === 3 && $rows[1]['is_primary'] === 'false' && $rows[1]['receives_documents'] === 'false';
});

check('normalize: una coppia già esistente riprende il suo id, preferendo la riga viva', function () {
    $rows = ContactRelations::normalize(
        [['company_id' => 4]],
        [['id' => 7, 'company_id' => 4, 'deleted' => 'true'], ['id' => 9, 'company_id' => 4, 'deleted' => 'false']],
        'person_id', 2, [4 => 'business']
    );

    return count($rows) === 1 && $rows[0]['id'] === 9;
});

check('flag: solo i valori «veri» diventano true', function () {
    return ContactRelations::flag('true') === 'true' && ContactRelations::flag('1') === 'true' && ContactRelations::flag(true) === 'true'
        && ContactRelations::flag('false') === 'false' && ContactRelations::flag(null) === 'false' && ContactRelations::flag('') === 'false';
});

check('createPerson: nuova scheda privata non cliente, poi la riga punta a lei', function () use ($contacts) {
    Query::reset(['contacts' => $contacts()]);
    $row = ContactRelations::createPerson(['person_id' => '', 'new_name' => 'Marta', 'new_surname' => 'Gialli']);
    $created = Query::$tables['contacts'][$row['person_id'] ?? 0] ?? [];

    return ($row['person_id'] ?? 0) === 5 && ($created['type'] ?? '') === 'private'
        && ($created['is_customer'] ?? '') === 'false' && ($created['name'] ?? '') === 'Marta';
});

check('createPerson: con una persona scelta o senza nome non crea nulla', function () use ($contacts) {
    Query::reset(['contacts' => $contacts()]);
    ContactRelations::createPerson(['person_id' => '2', 'new_name' => 'X']);
    ContactRelations::createPerson(['person_id' => '', 'new_email' => 'solo@example.test']);

    return Query::$writes === [];
});

check('typesOf: solo schede esistenti', function () use ($contacts) {
    Query::reset(['contacts' => $contacts()]);

    return ContactRelations::typesOf([1, 2, 99, 0]) === [1 => 'business', 2 => 'private'];
});

check('spenti dai test: nessun campo in più nel form', function () {
    ContactRelations::useEnabled(false);
    $keys = array_map(static fn ($input) => $input->get('key') ?? $input->get('name'), ContactResource::formSchema());
    ContactRelations::useEnabled(null);

    return !in_array(ContactResource::RELATION_PEOPLE, $keys, true) && !in_array(ContactResource::RELATION_MARKER, $keys, true);
});

check('sempre accesi: i due repeater e il segnaposto', function () use ($contacts) {
    Query::reset(['contacts' => $contacts()]);
    $default = ContactRelations::enabled();
    $relations = array_keys(ContactResource::repeaterRelations());
    $fields = ContactResource::contactRelationFields();

    return $default && count($fields) === 3
        && in_array(ContactResource::RELATION_PEOPLE, $relations, true)
        && in_array(ContactResource::RELATION_COMPANIES, $relations, true);
});

check('sync dal lato azienda: aggiunge, crea al volo, toglie chi non è più postato', function () use ($contacts) {
    Query::reset(['contacts' => $contacts(), 'contact_relations' => [
        1 => ['id' => 1, 'company_id' => 1, 'person_id' => 3, 'is_primary' => 'true', 'receives_documents' => 'false', 'position' => 1, 'deleted' => 'false'],
    ]]);
    ContactRelations::useEnabled(true);
    ContactResource::syncRepeaterRelations(1, [
        ContactResource::RELATION_MARKER => '1',
        ContactResource::RELATION_PEOPLE => [
            ['person_id' => '2', 'job_title' => 'Acquisti', 'is_primary' => 'true', 'receives_documents' => 'true'],
            ['person_id' => '', 'new_name' => 'Marta', 'new_surname' => 'Gialli'],
        ],
    ]);
    ContactRelations::useEnabled(null);

    $people = array_column(ContactRelations::peopleOf(1), 'person_id');
    $old = Query::$tables['contact_relations'][1];

    return array_map('intval', $people) === [2, 5] && $old['deleted'] === 'true';
});

check('sync senza segnaposto: le righe restano come sono', function () use ($contacts) {
    $seed = ['contacts' => $contacts(), 'contact_relations' => [
        1 => ['id' => 1, 'company_id' => 1, 'person_id' => 3, 'is_primary' => 'true', 'receives_documents' => 'false', 'position' => 1, 'deleted' => 'false'],
    ]];
    Query::reset($seed);
    ContactRelations::useEnabled(true);
    ContactResource::syncRepeaterRelations(1, ['business_name' => 'Rossi srl']);
    ContactRelations::useEnabled(null);

    return Query::$tables['contact_relations'] === $seed['contact_relations'];
});

check('sync dal lato persona: diventare principale toglie il titolo agli altri', function () use ($contacts) {
    Query::reset(['contacts' => $contacts(), 'contact_relations' => [
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

check('eventi: sync emette linked e unlinked, createPerson emette created', function () use ($contacts) {
    Query::reset(['contacts' => $contacts(), 'contact_relations' => [
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

    return $seen === ['contact.created:5', 'contact.linked:2', 'contact.linked:5', 'contact.unlinked:3'];
});

check('eventi: un ascoltatore che lancia non ferma il salvataggio né gli altri', function () use ($contacts) {
    Query::reset(['contacts' => $contacts(), 'contact_relations' => []]);
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

check('ContactRecord::from: campi, legami, id esterni e consensi', function () use ($contacts) {
    Query::reset([
        'contacts' => [1 => $contacts()[1] + ['email' => 'info@rossi.test', 'user_id' => 8]] + $contacts(),
        'contact_relations' => [
            1 => ['id' => 1, 'company_id' => 1, 'person_id' => 2, 'job_title' => 'Acquisti', 'is_primary' => 'true', 'receives_documents' => 'true', 'position' => 1, 'deleted' => 'false'],
            2 => ['id' => 2, 'company_id' => 1, 'person_id' => 3, 'is_primary' => 'false', 'receives_documents' => 'false', 'position' => 2, 'deleted' => 'true'],
        ],
        'external_references' => [
            1 => ['id' => 1, 'entity_type' => 'contacts', 'entity_id' => 1, 'provider' => 'hubspot', 'environment' => 'live', 'object_type' => 'company', 'external_id' => 'hs-1', 'synced_at' => '', 'deleted' => 'false'],
        ],
    ]);
    ContactRecord::useConsents(fn (int $userId): array => $userId === 8 ? ['newsletter' => 'granted'] : []);
    $record = ContactRecord::from(1);
    ContactRecord::useConsents(null);

    return $record['schema'] === ContactRecord::SCHEMA && $record['business_name'] === 'Rossi srl'
        && $record['email'] === 'info@rossi.test' && $record['cap'] === ''
        && $record['relations'] === [['person_id' => 2, 'job_title' => 'Acquisti', 'is_primary' => true, 'receives_documents' => true]]
        && ($record['external_ids'][0]['external_id'] ?? '') === 'hs-1'
        && $record['consents'] === ['newsletter' => 'granted']
        && json_encode($record) !== false && ContactRecord::from(99) === null;
});

check('ContactRecord: fromArray tiene i campi noti, validate dà gli errori', function () {
    $record = ContactRecord::fromArray([' type ' => 'x', 'type' => ' Business ', 'business_name' => ' Rossi ', 'email' => 'INFO@ROSSI.TEST', 'password' => 'no']);
    $bad = ContactRecord::validate(ContactRecord::fromArray(['type' => 'private', 'email' => 'non-email', 'relations' => [['company_id' => 0]]]));

    return $record === ['type' => 'business', 'business_name' => 'Rossi', 'email' => 'info@rossi.test']
        && ContactRecord::validate($record) === []
        && $bad === ['name' => 'required', 'email' => 'invalid', 'relations.0' => 'invalid'];
});

summary();
