<?php
/** php tests/ContactPointsTest.php — email e telefoni in più di persone e aziende: validazione, scrittura, form, record e collegamento dell'account (database finto). */
declare(strict_types=1);

require __DIR__.'/support/FakeDb.php';
require __DIR__.'/bootstrap.php';

// Il riquadro a righe è quello con il CRM acceso (spento: un'email e un telefono soli, vedi CrmContactsTest).
\Wonder\Plugin\CustomerAccount\Support\Crm::enable();

// Gli helper globali che Repeater::syncRelatedRows() usa, sopra il FakeDb.
if (!function_exists('sqlSelect')) {
    function sqlSelect($table, $condition = null, $limit = null, $order = null, $orderDirection = null, $attributes = '*') { return (new \Wonder\Sql\Query)->Select($table, $condition, $limit, $order, $orderDirection, $attributes); }
    function sqlInsert($table, $values) { return (new \Wonder\Sql\Query)->Insert($table, $values); }
    function sqlModify($table, $values, $column, $value) { return (new \Wonder\Sql\Query)->Update($table, $values, $column, $value); }
    function sqlDelete($table, $condition = null) { return (new \Wonder\Sql\Query)->Delete($table, $condition); }
}

use Wonder\Http\Csrf;
use Wonder\Plugin\CustomerAccount\Account\ContactAccount;
use Wonder\Plugin\CustomerAccount\Resources\CompanyAddressResource;
use Wonder\Plugin\CustomerAccount\Resources\CompanyResource;
use Wonder\Plugin\CustomerAccount\Resources\ContactResource;
use Wonder\Plugin\CustomerAccount\Support\CompanyRecord;
use Wonder\Plugin\CustomerAccount\Support\ContactPoints;
use Wonder\Plugin\CustomerAccount\Support\ContactRecord;
use Wonder\Sql\Query;

$seed = static fn (): array => [
    'contacts' => [
        2 => ['id' => 2, 'name' => 'Anna', 'surname' => 'Bianchi', 'email' => 'anna@rossi.test', 'phone_prefix' => '+39', 'phone' => '3331112222', 'deleted' => 'false'],
        3 => ['id' => 3, 'name' => 'Luca', 'surname' => 'Verdi', 'email' => 'luca@verdi.test', 'user_id' => 0, 'deleted' => 'false'],
    ],
    'companies' => [
        2 => ['id' => 2, 'business_name' => 'Rossi srl', 'email' => 'info@rossi.test', 'deleted' => 'false'],
    ],
    'contact_points' => [],
    'company_points' => [],
    'contact_relations' => [],
    'external_references' => [],
];
$_SESSION = [];
$token = Csrf::token();
$reject = static function (callable $fn): ?string {
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }

    return null;
};

check('clean: email in minuscolo, telefono a sole cifre, prefisso +NN, righe vuote saltate, etichetta sconosciuta vuota', function () {
    [$rows, $problems] = ContactPoints::clean([
        ['kind' => 'email', 'value' => ' Amministrazione@Rossi.TEST ', 'label' => 'billing'],
        ['kind' => 'phone', 'value' => '02 / 123-45.67', 'phone_prefix' => '0039', 'label' => 'boss'],
        ['kind' => 'phone', 'value' => '(333) 999 0000', 'phone_prefix' => ''],
        ['kind' => 'email', 'value' => '   '],
        'non una riga',
    ]);

    return $problems === [] && $rows === [
        ['kind' => 'email', 'value' => 'amministrazione@rossi.test', 'phone_prefix' => '', 'label' => 'billing'],
        ['kind' => 'phone', 'value' => '021234567', 'phone_prefix' => '+39', 'label' => ''],
        ['kind' => 'phone', 'value' => '3339990000', 'phone_prefix' => '', 'label' => ''],
    ];
});

check('clean: rifiuta email e telefoni non validi e i doppioni, anche dei recapiti principali', function () {
    [$rows, $problems] = ContactPoints::clean([
        ['kind' => 'email', 'value' => 'non-email'],
        ['kind' => 'email', 'value' => 'javascript:alert(1)@x'],
        ['kind' => 'phone', 'value' => '333abc'],
        ['kind' => 'phone', 'value' => '+39 333 1112222'],
        ['kind' => 'phone', 'value' => '3331112222', 'phone_prefix' => '+12345'],
        ['kind' => 'email', 'value' => 'ANNA@rossi.test'],
        ['kind' => 'phone', 'value' => '333 111 2222', 'phone_prefix' => '+41'],
        ['kind' => 'email', 'value' => 'altro@rossi.test'],
        ['kind' => 'email', 'value' => 'Altro@Rossi.test'],
    ], ['email' => 'anna@rossi.test', 'phone_prefix' => '+39', 'phone' => '333 1112222']);

    return $rows === [['kind' => 'email', 'value' => 'altro@rossi.test', 'phone_prefix' => '', 'label' => '']]
        && $problems === [
            'contacts.point_email_invalid', 'contacts.point_email_invalid', 'contacts.point_phone_invalid', 'contacts.point_phone_invalid',
            'contacts.point_phone_invalid', 'contacts.point_duplicate', 'contacts.point_duplicate', 'contacts.point_duplicate',
        ];
});

check('etichette: lista di base, aggiunta da un modulo', function () {
    ContactPoints::addLabel('Orders', 'Ordini');
    ContactPoints::addLabel('no spazi', 'X');

    return array_slice(array_keys(ContactPoints::labels()), 0, 4) === ['work', 'personal', 'billing', 'other']
        && ContactPoints::label('orders') === 'orders' && ContactPoints::label('no spazi') === '' && ContactPoints::label(['x']) === '';
});

check('sync: scrive, tiene la riga dello stesso valore, toglie le altre, ignora id falsi; persone e aziende separate', function () use ($seed) {
    $tables = $seed();
    $tables['contact_points'][9] = ['id' => 9, 'contact_id' => 3, 'kind' => 'email', 'value' => 'luca@altro.test', 'label' => '', 'position' => 0, 'deleted' => 'false'];
    Query::reset($tables);
    ContactPoints::sync(ContactPoints::CONTACT, 2, [
        ['kind' => 'email', 'value' => 'a@rossi.test', 'label' => 'work'],
        ['kind' => 'phone', 'value' => '021234567', 'phone_prefix' => '+39'],
    ]);
    $kept = array_search('a@rossi.test', array_column(Query::$tables['contact_points'], 'value', 'id'), true);
    $saved = ContactPoints::sync(ContactPoints::CONTACT, 2, [
        ['id' => 9, 'kind' => 'email', 'value' => 'luca@altro.test'],
        ['kind' => 'email', 'value' => 'A@rossi.test', 'label' => 'billing'],
        ['kind' => 'email', 'value' => 'rotta'],
    ]);
    ContactPoints::sync(ContactPoints::COMPANY, 2, [['kind' => 'email', 'value' => 'ordini@rossi.test']]);

    return $saved === [
        ['kind' => 'email', 'value' => 'luca@altro.test', 'phone_prefix' => '', 'label' => ''],
        ['kind' => 'email', 'value' => 'a@rossi.test', 'phone_prefix' => '', 'label' => 'billing'],
    ]
        && Query::$tables['contact_points'][9]['contact_id'] === 3
        && Query::$tables['contact_points'][$kept]['label'] === 'billing' && Query::$tables['contact_points'][$kept]['position'] === 1
        && array_column(ContactPoints::of(ContactPoints::CONTACT, 2), 'value') === ['luca@altro.test', 'a@rossi.test']
        && ContactPoints::of(ContactPoints::CONTACT, 2, ContactPoints::PHONE) === []
        && array_column(ContactPoints::of(ContactPoints::COMPANY, 2), 'value') === ['ordini@rossi.test']
        && ContactPoints::sync(ContactPoints::CONTACT, 99, [['kind' => 'email', 'value' => 'x@y.test']]) === []
        && count(Query::$tables['contact_points']) === 3;
});

check('form: riquadro «Contatti» su persone e aziende; «Altri contatti» (marker vecchio) ancora rifiuta, legge e scrive; scrittura solo col segnaposto', function () use ($seed, $token, $reject) {
    Query::reset($seed());
    $names = static fn (string $resource): array => array_map(static fn ($field) => $field->name, $resource::formSchema());
    $person = ['name' => 'Anna', 'surname' => 'Bianchi', 'email' => 'anna@rossi.test', 'active' => 'true', '_csrf' => $token, 'contact_points_form' => '1'];
    $bad = $reject(static fn () => ContactResource::mutateRequestValues($person + ['contact_points' => ['row_1' => ['kind' => 'email', 'value' => 'Anna@Rossi.test']]], 'update', 'backend', ['id' => 2]));
    $ok = ContactResource::mutateRequestValues($person + ['contact_points' => ['row_1' => ['kind' => 'phone', 'value' => '021234567', 'phone_prefix' => '+39']]], 'update', 'backend', ['id' => 2]);

    ContactResource::syncRepeaterRelations(2, ['contact_points' => ['row_1' => ['kind' => 'email', 'value' => 'x@rossi.test']]]);
    $untouched = Query::$tables['contact_points'];
    CompanyResource::syncRepeaterRelations(2, ['contact_points_form' => '1', 'contact_points' => ['row_1' => ['kind' => 'email', 'value' => 'ordini@rossi.test', 'label' => 'work']]]);
    $form = CompanyResource::hydrateRepeaterFormValues([], 2, [], []);
    $address = CompanyAddressResource::hydrateRepeaterFormValues([], 2, [], []);

    return in_array('contact_points', $names(ContactResource::class), true) && in_array('contact_box_form', $names(CompanyResource::class), true)
        && !in_array('contact_points_form', $names(CompanyResource::class), true)
        && !in_array('email', $names(ContactResource::class), true) && !in_array('phone', $names(ContactResource::class), true)
        && !in_array('contact_points', $names(CompanyAddressResource::class), true)
        && $bad === 'contacts.point_duplicate'
        && !array_key_exists('contact_points', $ok) && !array_key_exists('contact_points_form', $ok) && $ok['email'] === 'anna@rossi.test'
        && $untouched === []
        && Query::$tables['company_points'] !== [] && (int) array_values(Query::$tables['company_points'])[0]['company_id'] === 2
        && $form['contact_points'] === [
            ['kind' => 'email', 'email' => 'info@rossi.test', 'phone_prefix' => '', 'phone' => '', 'label' => '', 'is_primary' => 'true', 'locked' => '', 'access' => '', 'departments' => [], 'department' => ''],
            ['kind' => 'email', 'email' => 'ordini@rossi.test', 'phone_prefix' => '', 'phone' => '', 'label' => 'work', 'is_primary' => 'false', 'locked' => '', 'access' => '', 'departments' => [], 'department' => ''],
        ]
        && !array_key_exists('contact_points', $address);
});

check('record: email principale invariata, tutti i contatti in emails e phones (il principale per primo); fromArray e validate', function () use ($seed) {
    Query::reset($seed());
    ContactPoints::sync(ContactPoints::CONTACT, 2, [
        ['kind' => 'email', 'value' => 'b@rossi.test', 'label' => 'billing'],
        ['kind' => 'phone', 'value' => '021234567', 'phone_prefix' => '+39', 'label' => 'work'],
    ]);
    $person = ContactRecord::from(2);
    $company = CompanyRecord::from(2);
    $in = ContactRecord::fromArray(['name' => 'X', 'emails' => [['value' => 'no'], 'x'], 'phones' => [['value' => '02 1x']]]);

    return $person['schema'] === 5 && $person['email'] === 'anna@rossi.test'
        && $person['emails'] === [['value' => 'anna@rossi.test', 'label' => '', 'is_primary' => true], ['value' => 'b@rossi.test', 'label' => 'billing', 'is_primary' => false]]
        && $person['phones'] === [
            ['kind' => 'phone', 'phone_prefix' => '+39', 'value' => '3331112222', 'label' => '', 'is_primary' => true],
            ['kind' => 'phone', 'phone_prefix' => '+39', 'value' => '021234567', 'label' => 'work', 'is_primary' => false],
        ]
        && $company['emails'] === [['value' => 'info@rossi.test', 'label' => '', 'is_primary' => true, 'departments' => []]] && $company['phones'] === []
        && $in['emails'] === [['value' => 'no']]
        && ContactRecord::validate($in) === ['emails.0' => 'invalid', 'phones.0' => 'invalid'];
});

check('DNS che non risponde: la scrittura non cancella le email salvate, il form le rifiuta', function () use ($seed, $token, $reject) {
    $tables = $seed();
    $tables['contact_points'][1] = ['id' => 1, 'contact_id' => 2, 'kind' => 'email', 'value' => 'a@rossi.test', 'label' => '', 'position' => 0, 'deleted' => 'false'];
    Query::reset($tables);
    $GLOBALS['__dns_ok'] = false;
    $saved = ContactPoints::sync(ContactPoints::CONTACT, 2, [['kind' => 'email', 'value' => 'a@rossi.test'], ['kind' => 'email', 'value' => 'b@rossi.test']]);
    $form = $reject(static fn () => ContactResource::mutateRequestValues(
        ['name' => 'Anna', 'surname' => 'Bianchi', 'active' => 'true', '_csrf' => $token, 'contact_points_form' => '1', 'contact_points' => [['kind' => 'email', 'value' => 'c@rossi.test']]],
        'update', 'backend', ['id' => 2]
    ));
    $brand = \Wonder\Plugin\CustomerAccount\Support\BrandData::clean(['email' => 'ordini@lapiana.it'], true);
    unset($GLOBALS['__dns_ok']);

    return array_column($saved, 'value') === ['a@rossi.test', 'b@rossi.test'] && isset(Query::$tables['contact_points'][1])
        && $form === 'contacts.point_email_invalid' && $brand[0]['email'] === 'ordini@lapiana.it' && $brand[1] === [];
});

check('email: niente spazi, virgolette, apici, backtick né < > anche se FILTER_VALIDATE_EMAIL li accetta', function () {
    $evil = '"<img/src=x/onerror=alert(1)>"@rossi.test';
    [$rows, $problems] = ContactPoints::clean([['kind' => 'email', 'value' => $evil], ['kind' => 'email', 'value' => "o'brien@rossi.test"]]);
    [, $brandErrors] = \Wonder\Plugin\CustomerAccount\Support\BrandData::clean(['email' => $evil], true);

    return filter_var($evil, FILTER_VALIDATE_EMAIL) !== false
        && !ContactPoints::emailFormat($evil) && !ContactPoints::validEmail($evil)
        && !ContactPoints::emailFormat('"a b"@rossi.test') && !ContactPoints::emailFormat('a`b@rossi.test') && !ContactPoints::emailFormat('a\\b@rossi.test')
        && ContactPoints::emailFormat('nome.cognome+ordini@rossi.test')
        && $rows === [] && count($problems) === 2 && $brandErrors === ['email' => 'invalid'];
});

// Vincolo di sicurezza: un'email in più non aggancia mai una scheda né diventa l'email di accesso.
$GLOBALS['__fake_users'] = [['id' => 7, 'email' => 'ada@example.com', 'name' => 'Ada', 'surname' => 'Lovelace']];

check('collegamento account: un\'email secondaria uguale a quella dell\'utente non aggancia la scheda di un altro', function () use ($seed) {
    foreach (['link', 'linkKeepingIdentity'] as $method) {
        $tables = $seed();
        $tables['contact_points'][1] = ['id' => 1, 'contact_id' => 3, 'kind' => 'email', 'value' => 'ada@example.com', 'label' => '', 'position' => 0, 'deleted' => 'false'];
        $tables['company_points'][1] = ['id' => 1, 'company_id' => 2, 'kind' => 'email', 'value' => 'ada@example.com', 'label' => '', 'position' => 0, 'deleted' => 'false'];
        Query::reset($tables);
        $link = ContactAccount::$method(7);
        $luca = Query::$tables['contacts'][3];

        if (!$link->success || $link->contact_id === 3 || (int) ($luca['user_id'] ?? 0) !== 0 || $luca['email'] !== 'luca@verdi.test'
            || (int) Query::$tables['contacts'][$link->contact_id]['user_id'] !== 7 || Query::$tables['contacts'][$link->contact_id]['email'] !== 'ada@example.com') {
            return false;
        }
    }

    return true;
});

check('collegamento account: le schede si cercano solo per email principale, mai nelle tabelle dei recapiti', function () use ($seed) {
    Query::reset($seed());
    ContactAccount::link(7);
    $tables = array_unique(array_column(Query::$selects, 'table'));

    return !in_array('contact_points', $tables, true) && !in_array('company_points', $tables, true)
        && ContactPoints::sync(ContactPoints::CONTACT, 3, [['kind' => 'email', 'value' => 'ada@example.com']]) !== []
        && Query::$tables['contacts'][3]['email'] === 'luca@verdi.test';
});

summary();
