<?php
/** php tests/RelationWorkContactsTest.php — contatti di lavoro del referente sul legame: pulizia, righe senza chiave, letture, form, record e collegamento dell'account (database finto). */
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

use Wonder\Http\Csrf;
use Wonder\Plugin\CustomerAccount\Account\ContactAccount;
use Wonder\Plugin\CustomerAccount\Resources\CompanyResource;
use Wonder\Plugin\CustomerAccount\Resources\ContactResource;
use Wonder\Plugin\CustomerAccount\Support\CompanyRecord;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;

$tables = static fn (): array => [
    'contacts' => [
        2 => ['id' => 2, 'name' => 'Anna', 'surname' => 'Bianchi', 'email' => 'anna@casa.test', 'user_id' => 0, 'active' => 'true', 'deleted' => 'false'],
        3 => ['id' => 3, 'name' => 'Luca', 'surname' => 'Verdi', 'email' => '', 'user_id' => 0, 'active' => 'true', 'deleted' => 'false'],
    ],
    'companies' => [
        1 => ['id' => 1, 'business_name' => 'Rossi srl', 'email' => 'info@rossi.test', 'deleted' => 'false'],
        4 => ['id' => 4, 'business_name' => 'Neri spa', 'deleted' => 'false'],
    ],
    'contact_relations' => [],
    'contact_relation_departments' => [],
    'contact_relation_brands' => [],
    'company_brand_links' => [],
    'contact_points' => [],
    'company_points' => [],
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

check('workContacts: email in minuscolo senza DNS, telefono a cifre, prefisso +NN; non validi o non scalari vuoti; senza chiave resta il salvato', function () {
    $stored = ['email' => 'vecchia@rossi.test', 'phone_prefix' => '+39', 'phone' => '021234567'];
    $GLOBALS['__dns_ok'] = false;
    $clean = ContactRelations::workContacts(['email' => ' Anna@Rossi.TEST ', 'phone_prefix' => '0039', 'phone' => '333 111-2222']);
    unset($GLOBALS['__dns_ok']);

    return $clean === ['email' => 'anna@rossi.test', 'phone_prefix' => '+39', 'phone' => '3331112222']
        && ContactRelations::workContacts(['email' => 'non-email', 'phone' => '33x', 'phone_prefix' => '+12345']) === ['email' => '', 'phone_prefix' => '', 'phone' => '']
        && ContactRelations::workContacts(['email' => ['a@b.test'], 'phone' => ['333'], 'phone_prefix' => ['+39']], $stored) === ['email' => '', 'phone_prefix' => '', 'phone' => '']
        && ContactRelations::workContacts(['email' => '"<x>"@rossi.test']) === ['email' => '', 'phone_prefix' => '', 'phone' => '']
        && ContactRelations::workContacts([], $stored) === $stored
        && ContactRelations::workContacts(['phone_prefix' => '+41', 'phone' => ''], $stored) === ['email' => 'vecchia@rossi.test', 'phone_prefix' => '', 'phone' => ''];
});

check('sync: scrive i contatti di lavoro; una riga senza le chiavi tiene quelli di prima; dal lato persona come dal lato azienda', function () use ($tables) {
    Query::reset($tables());
    ContactRelations::sync(ContactRelations::COMPANY, 1, [
        ['person_id' => '2', 'email' => 'Anna@Rossi.test', 'phone_prefix' => '+39', 'phone' => '02 123 4567'],
        ['person_id' => '3'],
    ]);
    ContactRelations::sync(ContactRelations::COMPANY, 1, [['person_id' => '2', 'job_title' => 'Acquisti'], ['person_id' => '3', 'email' => 'luca@rossi.test']]);
    ContactRelations::sync(ContactRelations::PERSON, 3, [['company_id' => '1'], ['company_id' => '4', 'email' => 'luca@neri.test']]);
    $byPerson = array_column(array_filter(Query::$tables['contact_relations'], static fn (array $row): bool => (int) $row['company_id'] === 1), null, 'person_id');
    $neri = array_values(array_filter(Query::$tables['contact_relations'], static fn (array $row): bool => (int) $row['company_id'] === 4))[0];

    return $byPerson[2]['email'] === 'anna@rossi.test' && $byPerson[2]['phone'] === '021234567' && $byPerson[2]['phone_prefix'] === '+39'
        && $byPerson[2]['job_title'] === 'Acquisti'
        && $byPerson[3]['email'] === 'luca@rossi.test' && $neri['email'] === 'luca@neri.test';
});

check('attach: i contatti di lavoro passano da normalize()', function () use ($tables) {
    Query::reset($tables());
    ContactRelations::attach(4, 2, ['email' => 'ANNA@neri.test', 'phone' => 'abc']);
    $row = array_values(Query::$tables['contact_relations'])[0];

    return $row['email'] === 'anna@neri.test' && $row['phone'] === '';
});

check('peopleOf/peopleFor e companiesOf: work_* e contact_email (di lavoro se c\'è, altrimenti la sua)', function () use ($tables) {
    Query::reset($tables());
    ContactRelations::sync(ContactRelations::COMPANY, 1, [
        ['person_id' => '2', 'email' => 'anna@rossi.test', 'phone_prefix' => '+39', 'phone' => '021234567', 'departments' => ['administration']],
        ['person_id' => '3'],
    ]);
    ContactRelations::sync(ContactRelations::PERSON, 2, [['company_id' => '1'], ['company_id' => '4']]);
    $people = ContactRelations::peopleFor(1, 'administration');
    $all = array_column(ContactRelations::peopleOf(1), null, 'person_id');
    $companies = array_column(ContactRelations::companiesOf(2), null, 'company_id');

    return count($people) === 2 && $people[0]['contact_email'] === 'anna@rossi.test' && $people[0]['work_phone'] === '021234567'
        && $people[0]['work_phone_prefix'] === '+39' && $people[0]['contact']['email'] === 'anna@casa.test'
        && $all[3]['work_email'] === '' && $all[3]['contact_email'] === ''
        && $companies[1]['contact_email'] === 'anna@rossi.test' && $companies[4]['contact_email'] === 'anna@casa.test' && $companies[4]['work_email'] === '';
});

check('form: email di lavoro col DNS e telefono controllati dal POST intero; senza marker niente; colonne nei due repeater', function () use ($tables, $token, $reject) {
    Query::reset($tables());
    $base = ['business_name' => 'Rossi srl', 'active' => 'true', 'is_customer' => 'true', 'is_supplier' => 'false', '_csrf' => $token];
    $post = static fn (array $row): array => ['contact_relations_form' => '1', 'contact_people' => ['r1' => ['person_id' => '2'] + $row]];
    $GLOBALS['__dns_ok'] = false;
    $_POST = $post(['email' => 'anna@rossi.test']);
    $dns = $reject(static fn () => CompanyResource::mutateRequestValues($base, 'update', 'backend', ['id' => 1]));
    unset($GLOBALS['__dns_ok']);
    $_POST = $post(['phone' => '33x']);
    $phone = $reject(static fn () => CompanyResource::mutateRequestValues($base, 'update', 'backend', ['id' => 1]));
    $_POST = $post(['phone' => '333', 'phone_prefix' => 'abc']);
    $prefix = $reject(static fn () => CompanyResource::mutateRequestValues($base, 'update', 'backend', ['id' => 1]));
    $_POST = $post(['email' => 'anna@rossi.test', 'phone_prefix' => '+39', 'phone' => '3331112222']);
    $ok = CompanyResource::mutateRequestValues($base, 'update', 'backend', ['id' => 1]);
    $_POST = ['contact_people' => ['r1' => ['person_id' => '2', 'email' => 'rotta']]];
    $noMarker = CompanyResource::mutateRequestValues($base, 'update', 'backend', ['id' => 1]);
    $_POST = [];
    $columns = static function (string $resource, string $input): array {
        foreach ($resource::formSchema() as $field) {
            if ($field->name === $input) {
                return array_map(static fn ($column) => $column->name, (array) (($field->get('context') ?? [])['columns'] ?? []));
            }
        }

        return [];
    };

    return $dns === 'contacts.work_email_invalid' && $phone === 'contacts.work_phone_invalid' && $prefix === 'contacts.work_phone_invalid'
        && $ok['business_name'] === 'Rossi srl' && !array_key_exists('email', $ok) && $noMarker['business_name'] === 'Rossi srl'
        && array_values(array_intersect(['email', 'phone_prefix', 'phone'], $columns(CompanyResource::class, 'contact_people'))) === ['email', 'phone_prefix', 'phone']
        && array_values(array_intersect(['email', 'phone_prefix', 'phone'], $columns(ContactResource::class, 'contact_companies'))) === ['email', 'phone_prefix', 'phone'];
});

check('record: i legami portano i contatti di lavoro; validate rifiuta un\'email di lavoro storta', function () use ($tables) {
    Query::reset($tables());
    ContactRelations::sync(ContactRelations::COMPANY, 1, [['person_id' => '2', 'email' => 'anna@rossi.test', 'phone' => '021234567', 'phone_prefix' => '+39']]);
    $record = CompanyRecord::from(1);

    return $record['schema'] === 5
        && $record['relations'][0]['work_email'] === 'anna@rossi.test' && $record['relations'][0]['work_phone'] === '021234567' && $record['relations'][0]['work_phone_prefix'] === '+39'
        && CompanyRecord::validate(['business_name' => 'X', 'relations' => [['person_id' => 2, 'work_email' => 'rotta'], ['person_id' => 3, 'work_email' => 'ok@rossi.test']]]) === ['relations.0' => 'invalid'];
});

check('visibilità ristretta: il legame con una controparte non visibile resta com\'era (contatti di lavoro, mansione, flag, accesso, aree)', function () use ($tables) {
    $seed = $tables();
    $seed['contact_relations'] = [
        1 => ['id' => 1, 'company_id' => 1, 'person_id' => 2, 'job_title' => 'Acquisti', 'is_primary' => 'false', 'receives_documents' => 'false', 'access' => 'none', 'email' => 'anna@rossi.test', 'phone_prefix' => '', 'phone' => '', 'position' => 1, 'deleted' => 'false'],
        2 => ['id' => 2, 'company_id' => 4, 'person_id' => 2, 'job_title' => 'Amministrazione', 'is_primary' => 'true', 'receives_documents' => 'true', 'access' => 'buyer', 'email' => 'anna@neri.test', 'phone_prefix' => '+39', 'phone' => '021234567', 'position' => 2, 'deleted' => 'false'],
    ];
    $seed['contact_relation_departments'] = [1 => ['id' => 1, 'relation_id' => 2, 'department' => 'administration', 'deleted' => 'false']];
    Query::reset($seed);
    // Chi scrive vede l'azienda 1, non la 4.
    ContactRelations::useCounterpartFilter(static fn (string $model, array $ids): array => array_values(array_diff($ids, [4])));
    try {
        ContactRelations::sync(ContactRelations::PERSON, 2, [
            ['company_id' => '1', 'job_title' => 'Ordini', 'email' => 'anna.ordini@rossi.test'],
            ['company_id' => '4', 'job_title' => 'X', 'is_primary' => 'false', 'receives_documents' => 'false', 'access' => 'admin',
                'email' => 'dirottata@evil.test', 'phone' => '0000000', 'departments' => ['sales'], 'brand_ids' => ['9']],
        ]);
    } finally {
        ContactRelations::useCounterpartFilter(null);
    }
    $hidden = Query::$tables['contact_relations'][2];
    $seen = Query::$tables['contact_relations'][1];

    return $hidden['email'] === 'anna@neri.test' && $hidden['phone'] === '021234567' && $hidden['job_title'] === 'Amministrazione'
        && $hidden['is_primary'] === 'true' && $hidden['receives_documents'] === 'true' && $hidden['access'] === 'buyer' && $hidden['deleted'] === 'false'
        && ContactRelations::departmentsOf(2) === ['administration'] && ContactRelations::brandsOf(2) === []
        && $seen['email'] === 'anna.ordini@rossi.test' && $seen['job_title'] === 'Ordini';
});

check('visibilità ristretta dal lato azienda: il principale non visibile tiene il titolo; senza filtro tutto come prima', function () use ($tables) {
    $seed = $tables();
    $seed['contact_relations'] = [
        1 => ['id' => 1, 'company_id' => 1, 'person_id' => 3, 'job_title' => '', 'is_primary' => 'true', 'receives_documents' => 'false', 'access' => 'none', 'email' => 'luca@rossi.test', 'phone_prefix' => '', 'phone' => '', 'position' => 1, 'deleted' => 'false'],
    ];
    Query::reset($seed);
    ContactRelations::useCounterpartFilter(static fn (string $model, array $ids): array => array_values(array_diff($ids, [3])));
    try {
        ContactRelations::sync(ContactRelations::COMPANY, 1, [
            ['person_id' => '2', 'is_primary' => 'true'],
            ['person_id' => '3', 'is_primary' => 'false', 'email' => 'altro@evil.test'],
        ]);
    } finally {
        ContactRelations::useCounterpartFilter(null);
    }
    $byPerson = array_column(Query::$tables['contact_relations'], null, 'person_id');
    $filtered = $byPerson[3]['is_primary'] === 'true' && $byPerson[3]['email'] === 'luca@rossi.test' && $byPerson[2]['is_primary'] === 'false';
    ContactRelations::sync(ContactRelations::COMPANY, 1, [['person_id' => '2', 'is_primary' => 'true'], ['person_id' => '3', 'email' => 'luca.nuova@rossi.test']]);
    $byPerson = array_column(Query::$tables['contact_relations'], null, 'person_id');

    return $filtered && $byPerson[3]['email'] === 'luca.nuova@rossi.test' && $byPerson[3]['is_primary'] === 'false' && $byPerson[2]['is_primary'] === 'true';
});

// Vincolo di sicurezza: l'email di lavoro non è mai un'email di accesso.
$GLOBALS['__fake_users'] = [['id' => 7, 'email' => 'luca@rossi.test', 'name' => 'Luca', 'surname' => 'Verdi']];

check('collegamento account: un\'email di lavoro uguale a quella dell\'utente non aggancia la persona del legame', function () use ($tables) {
    foreach (['link', 'linkKeepingIdentity'] as $method) {
        Query::reset($tables());
        ContactRelations::sync(ContactRelations::COMPANY, 1, [['person_id' => '3', 'email' => 'luca@rossi.test']]);
        $selects = count(Query::$selects);
        $link = ContactAccount::$method(7);
        $luca = Query::$tables['contacts'][3];
        $read = array_unique(array_column(array_slice(Query::$selects, $selects), 'table'));

        if (!$link->success || $link->contact_id === 3 || (int) ($luca['user_id'] ?? 0) !== 0 || $luca['email'] !== ''
            || in_array('contact_relations', $read, true)) {
            return false;
        }
    }

    return true;
});

summary();
