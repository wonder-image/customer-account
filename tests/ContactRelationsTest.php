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

check('interruttore spento: nessun campo in più nel form', function () {
    ContactRelations::useEnabled(false);
    $keys = array_map(static fn ($input) => $input->get('key') ?? $input->get('name'), ContactResource::formSchema());
    ContactRelations::useEnabled(null);

    return !in_array(ContactResource::RELATION_PEOPLE, $keys, true) && !in_array(ContactResource::RELATION_MARKER, $keys, true);
});

check('interruttore acceso: i due repeater e il segnaposto', function () use ($contacts) {
    Query::reset(['contacts' => $contacts()]);
    ContactRelations::useEnabled(true);
    $relations = array_keys(ContactResource::repeaterRelations());
    $fields = ContactResource::contactRelationFields();
    ContactRelations::useEnabled(null);

    return count($fields) === 3
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

summary();
