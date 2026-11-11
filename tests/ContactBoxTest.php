<?php
/** php tests/ContactBoxTest.php — riquadro «Contatti»: principale per tipo nelle colonne della scheda, scambio, campi singoli, email di accesso bloccata, aree delle aziende (database finto). */
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
use Wonder\Plugin\CustomerAccount\Resources\CompanyResource;
use Wonder\Plugin\CustomerAccount\Resources\ContactResource;
use Wonder\Plugin\CustomerAccount\Support\ContactFormScript;
use Wonder\Plugin\CustomerAccount\Support\ContactPoints;
use Wonder\Sql\Query;

$seed = static fn (): array => [
    'contacts' => [
        2 => ['id' => 2, 'name' => 'Anna', 'surname' => 'Bianchi', 'email' => 'anna@rossi.test', 'phone_prefix' => '+39', 'phone' => '3331112222', 'user_id' => 0, 'deleted' => 'false'],
        // Con un account: la sua email è quella su cui ContactAccount la riconosce.
        3 => ['id' => 3, 'name' => 'Ada', 'surname' => 'Lovelace', 'email' => 'ada@example.com', 'phone_prefix' => '', 'phone' => '', 'user_id' => 7, 'deleted' => 'false'],
    ],
    'companies' => [
        2 => ['id' => 2, 'business_name' => 'Rossi srl', 'email' => 'info@rossi.test', 'phone_prefix' => '', 'phone' => '', 'deleted' => 'false'],
    ],
    'contact_points' => [],
    'company_points' => [],
    'company_point_departments' => [],
    'contact_point_departments' => [],
    'contact_office_types' => [],
    'contact_relations' => [],
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
$card = static fn (string $table, int $id): array => Query::$tables[$table][$id];
$values = static fn (array $rows, string $key = 'value'): array => array_column($rows, $key);

check('split: un principale per tipo (il primo segnato), la prima riga se nessuno lo è, colonne vuote senza righe', function () {
    $split = ContactPoints::split(ContactPoints::CONTACT, [
        ['kind' => 'email', 'email' => 'a@rossi.test', 'is_primary' => 'false'],
        ['kind' => 'email', 'email' => 'B@Rossi.test', 'is_primary' => 'true'],
        ['kind' => 'email', 'email' => 'c@rossi.test', 'is_primary' => 'true'],
        ['kind' => 'phone', 'phone_prefix' => '0039', 'phone' => '02 123 4567', 'is_primary' => 'false'],
        ['kind' => 'phone', 'phone' => '333 000 1111'],
    ]);
    $none = ContactPoints::split(ContactPoints::COMPANY, [['kind' => 'phone', 'phone' => '021234567']]);

    return $split['problems'] === []
        && $split['columns'] === ['email' => 'b@rossi.test', 'phone_prefix' => '+39', 'phone' => '021234567']
        && array_column($split['rows'], 'is_primary') === [false, true, false, true, false]
        && $none['columns'] === ['email' => '', 'phone_prefix' => '', 'phone' => '021234567'];
});

check('split: il valore viene dalla colonna del tipo (l\'altra, nascosta, non conta), `value` vale ancora; rifiuti e doppioni', function () use ($values) {
    $split = ContactPoints::split(ContactPoints::CONTACT, [
        ['kind' => 'email', 'email' => 'a@rossi.test', 'phone' => '3330000000'],
        ['kind' => 'phone', 'email' => 'nascosta@rossi.test', 'phone_prefix' => '+41', 'phone' => '3331112222'],
        ['value' => 'vecchia@rossi.test'],
        ['kind' => 'email', 'email' => 'A@rossi.test'],
        ['kind' => 'email', 'email' => 'non-email'],
        ['kind' => 'phone', 'phone' => '33x'],
        ['kind' => 'email', 'email' => '   '],
        ['kind' => 'email', 'email' => ['x@rossi.test']],
        'non una riga',
    ]);

    return $values($split['rows']) === ['a@rossi.test', '3331112222', 'vecchia@rossi.test']
        && $split['rows'][1]['phone_prefix'] === '+41'
        && $split['problems'] === ['contacts.point_duplicate', 'contacts.point_email_invalid', 'contacts.point_phone_invalid'];
});

check('syncAll: scambio del principale, la vecchia principale resta come riga normale; aziende e persone separate', function () use ($seed, $card, $values) {
    $tables = $seed();
    $tables['contact_points'][1] = ['id' => 1, 'contact_id' => 2, 'kind' => 'email', 'value' => 'lavoro@rossi.test', 'phone_prefix' => '', 'label' => 'work', 'position' => 0, 'deleted' => 'false'];
    Query::reset($tables);
    $rows = ContactPoints::syncAll(ContactPoints::CONTACT, 2, [
        ['kind' => 'email', 'email' => 'anna@rossi.test', 'is_primary' => 'false', 'label' => 'personal'],
        ['kind' => 'email', 'email' => 'lavoro@rossi.test', 'is_primary' => 'true', 'label' => 'work'],
        ['kind' => 'phone', 'phone_prefix' => '+39', 'phone' => '3331112222', 'is_primary' => 'false'],
        ['kind' => 'phone', 'phone_prefix' => '+39', 'phone' => '021234567', 'is_primary' => 'true'],
    ]);
    $anna = $card('contacts', 2);
    $back = ContactPoints::syncAll(ContactPoints::CONTACT, 2, [
        ['kind' => 'email', 'email' => 'anna@rossi.test', 'is_primary' => 'true'],
        ['kind' => 'email', 'email' => 'lavoro@rossi.test'],
    ]);

    return $anna['email'] === 'lavoro@rossi.test' && $anna['phone'] === '021234567' && $anna['phone_prefix'] === '+39'
        && $values(ContactPoints::of(ContactPoints::CONTACT, 2)) === ['lavoro@rossi.test']
        && $values($rows) === ['lavoro@rossi.test', '021234567', 'anna@rossi.test', '3331112222']
        && array_column($rows, 'is_primary') === [true, true, false, false] && $rows[2]['label'] === 'personal'
        && $card('contacts', 2)['email'] === 'anna@rossi.test' && $card('contacts', 2)['phone'] === ''
        && $values($back) === ['anna@rossi.test', 'lavoro@rossi.test']
        && Query::$tables['company_points'] === [] && $card('companies', 2)['email'] === 'info@rossi.test'
        && ContactPoints::syncAll(ContactPoints::CONTACT, 99, [['kind' => 'email', 'email' => 'x@y.test']]) === [];
});

check('syncAll: le colonne si scrivono solo se cambiano; un errore annulla tutto', function () use ($seed, $card) {
    Query::reset($seed());
    ContactPoints::syncAll(ContactPoints::CONTACT, 2, [
        ['kind' => 'email', 'email' => 'anna@rossi.test', 'is_primary' => 'true'],
        ['kind' => 'phone', 'phone_prefix' => '+39', 'phone' => '3331112222', 'is_primary' => 'true'],
    ]);
    $updates = array_filter(Query::$writes, static fn (array $write): bool => $write === ['update', 'contacts']);
    Query::reset($seed());
    Query::$beforeInsert = static function (string $table): void {
        if ($table === 'contact_points') {
            throw new RuntimeException('indice violato');
        }
    };
    try {
        ContactPoints::syncAll(ContactPoints::CONTACT, 2, [['kind' => 'email', 'email' => 'nuova@rossi.test', 'is_primary' => 'true'], ['kind' => 'email', 'email' => 'anna@rossi.test']]);
    } catch (RuntimeException) {
    }

    return $updates === [] && $card('contacts', 2)['email'] === 'anna@rossi.test' && Query::$tables['contact_points'] === [];
});

check('form: il riquadro rimette il principale nelle colonne e vince sui campi singoli; i problemi fermano il salvataggio', function () use ($seed, $token, $reject) {
    Query::reset($seed());
    $base = ['name' => 'Anna', 'surname' => 'Bianchi', 'active' => 'true', '_csrf' => $token, 'contact_box_form' => '1'];
    $saved = ContactResource::mutateRequestValues($base + [
        'email' => 'singolo@rossi.test', 'phone' => '0000',
        'contact_points' => [
            'r1' => ['kind' => 'email', 'email' => 'anna@rossi.test'],
            'r2' => ['kind' => 'email', 'email' => 'nuova@rossi.test', 'is_primary' => 'true'],
            'r3' => ['kind' => 'phone', 'phone_prefix' => '+39', 'phone' => '02 1234567'],
        ],
    ], 'update', 'backend', Query::$tables['contacts'][2]);
    // Una scheda che aveva contatti non resta senza: va cambiato l'ultimo, non tolto.
    $empty = $reject(static fn () => ContactResource::mutateRequestValues($base + ['contact_points' => []], 'update', 'backend', Query::$tables['contacts'][2]));
    $fresh = ContactResource::mutateRequestValues($base + ['contact_points' => []], 'store', 'backend', null);
    $duplicate = $reject(static fn () => ContactResource::mutateRequestValues($base + ['contact_points' => [
        ['kind' => 'email', 'email' => 'a@rossi.test'], ['kind' => 'email', 'email' => 'A@rossi.test'],
    ]], 'update', 'backend', Query::$tables['contacts'][2]));
    $GLOBALS['__dns_ok'] = false;
    $dns = $reject(static fn () => ContactResource::mutateRequestValues($base + ['contact_points' => [['kind' => 'email', 'email' => 'c@rossi.test']]], 'update', 'backend', Query::$tables['contacts'][2]));
    // La scrittura non interroga il DNS: un DNS giù non cancella niente.
    ContactPoints::syncAll(ContactPoints::CONTACT, 2, [['kind' => 'email', 'email' => 'anna@rossi.test'], ['kind' => 'email', 'email' => 'c@rossi.test']]);
    unset($GLOBALS['__dns_ok']);

    return $saved['email'] === 'nuova@rossi.test' && $saved['phone'] === '021234567' && $saved['phone_prefix'] === '+39'
        && !array_key_exists('contact_points', $saved) && !array_key_exists('contact_box_form', $saved)
        && $empty === 'contacts.point_required' && $fresh['email'] === ''
        && $duplicate === 'contacts.point_duplicate' && $dns === 'contacts.point_email_invalid'
        && array_column(ContactPoints::of(ContactPoints::CONTACT, 2), 'value') === ['c@rossi.test'];
});

check('retrocompatibilità: senza marker i campi singoli si salvano come prima e i contatti non si toccano', function () use ($seed, $token) {
    $tables = $seed();
    $tables['contact_points'][1] = ['id' => 1, 'contact_id' => 2, 'kind' => 'email', 'value' => 'altra@rossi.test', 'phone_prefix' => '', 'label' => '', 'position' => 0, 'deleted' => 'false'];
    Query::reset($tables);
    $values = ContactResource::mutateRequestValues(
        ['name' => 'Anna', 'surname' => 'Bianchi', 'active' => 'true', '_csrf' => $token, 'email' => 'singolo@rossi.test', 'phone_prefix' => '+41', 'phone' => '0791234567'],
        'update', 'backend', Query::$tables['contacts'][2]
    );
    ContactResource::syncRepeaterRelations(2, ['contact_points' => [['kind' => 'email', 'email' => 'x@rossi.test']]]);

    return $values['email'] === 'singolo@rossi.test' && $values['phone'] === '0791234567' && $values['phone_prefix'] === '+41'
        && array_column(ContactPoints::of(ContactPoints::CONTACT, 2), 'value') === ['altra@rossi.test']
        && Query::$tables['contacts'][2]['email'] === 'anna@rossi.test';
});

check('form: righe del riquadro dalle colonne e dalla tabella; la scrittura passa da syncRepeaterRelations col marker', function () use ($seed) {
    $tables = $seed();
    $tables['contact_points'][1] = ['id' => 1, 'contact_id' => 2, 'kind' => 'phone', 'value' => '021234567', 'phone_prefix' => '+39', 'label' => 'work', 'position' => 0, 'deleted' => 'false'];
    Query::reset($tables);
    $form = ContactResource::hydrateRepeaterFormValues([], 2, [], []);
    ContactResource::syncRepeaterRelations(2, ['contact_box_form' => '1', 'contact_points' => [
        ['kind' => 'phone', 'phone_prefix' => '+39', 'phone' => '021234567', 'is_primary' => 'true'],
    ]]);

    return $form['contact_points'] === [
        ['kind' => 'email', 'email' => 'anna@rossi.test', 'phone_prefix' => '', 'phone' => '', 'label' => '', 'is_primary' => 'true', 'locked' => '', 'access' => '1', 'departments' => [], 'department' => ''],
        ['kind' => 'phone', 'email' => '', 'phone_prefix' => '+39', 'phone' => '3331112222', 'label' => '', 'is_primary' => 'true', 'locked' => '', 'access' => '', 'departments' => [], 'department' => ''],
        ['kind' => 'phone', 'email' => '', 'phone_prefix' => '+39', 'phone' => '021234567', 'label' => 'work', 'is_primary' => 'false', 'locked' => '', 'access' => '', 'departments' => [], 'department' => ''],
    ]
        && Query::$tables['contacts'][2]['email'] === '' && Query::$tables['contacts'][2]['phone'] === '021234567'
        && ContactPoints::of(ContactPoints::CONTACT, 2) === [];
});

// Email di accesso: con un account la scheda tiene la sua email, qualunque cosa arrivi dal riquadro.
$GLOBALS['__fake_users'] = [
    ['id' => 7, 'email' => 'ada@example.com', 'name' => 'Ada', 'surname' => 'Lovelace'],
    ['id' => 8, 'email' => 'bob@example.com', 'name' => 'Bob', 'surname' => 'Rossi'],
];

check('email di accesso: riga bloccata con etichetta access; un\'altra email non diventa principale e l\'accesso non cambia', function () use ($seed, $token, $card) {
    Query::reset($seed());
    $rows = ContactPoints::all(ContactPoints::CONTACT, 3);
    $posted = [
        // La riga bloccata cambiata a mano (lo script la disabilita, il server non si fida).
        ['kind' => 'email', 'email' => 'bob@example.com', 'is_primary' => 'true', 'label' => 'access', 'locked' => ''],
        ['kind' => 'email', 'email' => 'ada.lavoro@example.com', 'is_primary' => 'true', 'label' => 'access'],
    ];
    $values = ContactResource::mutateRequestValues(
        ['name' => 'Ada', 'surname' => 'Lovelace', 'active' => 'true', '_csrf' => $token, 'contact_box_form' => '1', 'email' => 'bob@example.com', 'user_id' => '8', 'contact_points' => $posted],
        'update', 'backend', Query::$tables['contacts'][3]
    );
    $after = ContactPoints::syncAll(ContactPoints::CONTACT, 3, $posted);
    $ada = $card('contacts', 3);
    // Bob si registra: la sua email è una riga in più della scheda di Ada, che non si aggancia.
    $bob = ContactAccount::link(8);

    return $rows === [['kind' => 'email', 'value' => 'ada@example.com', 'phone_prefix' => '', 'label' => 'access', 'is_primary' => true, 'locked' => true, 'access' => true, 'departments' => [], 'department' => '']]
        && !array_key_exists('email', $values) && !array_key_exists('user_id', $values)
        && $ada['email'] === 'ada@example.com' && (int) $ada['user_id'] === 7
        && array_column($after, 'value') === ['ada@example.com', 'bob@example.com', 'ada.lavoro@example.com']
        && array_column($after, 'is_primary') === [true, false, false] && array_column($after, 'label') === ['access', '', '']
        && $bob->success && $bob->contact_id !== 3 && (int) $card('contacts', 3)['user_id'] === 7 && $card('contacts', 3)['email'] === 'ada@example.com';
});

check('email di accesso: togliere la riga bloccata non svuota l\'email; il telefono resta libero', function () use ($seed, $card) {
    Query::reset($seed());
    ContactPoints::syncAll(ContactPoints::CONTACT, 3, [['kind' => 'phone', 'phone' => '3330001111']]);

    return $card('contacts', 3)['email'] === 'ada@example.com' && $card('contacts', 3)['phone'] === '3330001111'
        && ContactPoints::lockedEmail(ContactPoints::CONTACT, ['user_id' => 7, 'email' => ' Ada@Example.com ']) === 'ada@example.com'
        && ContactPoints::lockedEmail(ContactPoints::CONTACT, ['user_id' => 0, 'email' => 'x@y.test']) === null
        && ContactPoints::lockedEmail(ContactPoints::COMPANY, ['user_id' => 7, 'email' => 'x@y.test']) === null;
});

check('collegamento: un\'email non principale del riquadro non aggancia mai una scheda altrui', function () use ($seed, $card) {
    Query::reset($seed());
    // Anna (senza account) ha bob@ come email in più: Bob che si registra non prende la scheda di Anna.
    ContactPoints::syncAll(ContactPoints::CONTACT, 2, [
        ['kind' => 'email', 'email' => 'anna@rossi.test', 'is_primary' => 'true'],
        ['kind' => 'email', 'email' => 'bob@example.com'],
    ]);
    $link = ContactAccount::link(8);

    return $link->success && $link->contact_id !== 2 && (int) ($card('contacts', 2)['user_id'] ?? 0) === 0
        && $card('contacts', 2)['email'] === 'anna@rossi.test';
});

check('etichetta access: non si sceglie né si aggiunge', function () {
    ContactPoints::addLabel('access', 'X');
    [$rows] = ContactPoints::clean([['kind' => 'email', 'value' => 'a@rossi.test', 'label' => 'access']]);

    return ContactPoints::label('access') === '' && !array_key_exists('access', ContactPoints::labels()) && $rows[0]['label'] === '';
});

check('aziende: «Tipo ufficio» per riga, segue l\'email nello scambio; senza la chiave resta, [\'\'] la toglie', function () use ($seed) {
    Query::reset($seed());
    ContactPoints::syncAll(ContactPoints::COMPANY, 2, [
        ['kind' => 'email', 'email' => 'info@rossi.test', 'is_primary' => 'true', 'departments' => ['']],
        ['kind' => 'email', 'email' => 'amministrazione@rossi.test', 'departments' => ['administration', 'boss', 'administration']],
        ['kind' => 'email', 'email' => 'ordini@rossi.test', 'departments' => ['purchasing', 'sales']],
    ]);
    $first = [ContactPoints::companyEmailsFor(2, 'administration'), ContactPoints::companyEmailsFor(2, 'sales'), ContactPoints::companyEmailsFor(2)];
    // Amministrazione diventa la principale: l'area la segue; ordini@ senza chiave tiene le sue.
    $rows = ContactPoints::syncAll(ContactPoints::COMPANY, 2, [
        ['kind' => 'email', 'email' => 'amministrazione@rossi.test', 'is_primary' => 'true'],
        ['kind' => 'email', 'email' => 'info@rossi.test'],
        ['kind' => 'email', 'email' => 'ordini@rossi.test'],
    ]);
    $second = ContactPoints::companyEmailsFor(2, 'administration');
    ContactPoints::syncAll(ContactPoints::COMPANY, 2, [['kind' => 'email', 'email' => 'amministrazione@rossi.test', 'departments' => ['']]]);

    return $first === [
        ['info@rossi.test', 'amministrazione@rossi.test'],
        ['info@rossi.test', 'ordini@rossi.test'],
        ['info@rossi.test', 'amministrazione@rossi.test', 'ordini@rossi.test'],
    ]
        && array_column($rows, 'departments') === [['administration'], [], ['purchasing', 'sales']]
        && $second === ['amministrazione@rossi.test', 'info@rossi.test']
        && Query::$tables['company_point_departments'] === []
        && ContactPoints::companyEmailsFor(99, 'administration') === [];
});

check('tipi ufficio: quelli dei contatti tolti se ne vanno, anche con «Altri contatti»; una persona ha i suoi, in una tabella a parte', function () use ($seed) {
    Query::reset($seed());
    ContactPoints::syncAll(ContactPoints::COMPANY, 2, [
        ['kind' => 'email', 'email' => 'info@rossi.test', 'departments' => ['sales']],
        ['kind' => 'email', 'email' => 'ordini@rossi.test', 'departments' => ['purchasing']],
    ]);
    ContactPoints::sync(ContactPoints::COMPANY, 2, []);
    ContactPoints::syncAll(ContactPoints::CONTACT, 2, [
        ['kind' => 'email', 'email' => 'anna@rossi.test', 'departments' => ['sales']],
        ['kind' => 'email', 'email' => 'casa@rossi.test', 'departments' => ['purchasing']],
    ]);
    $person = array_column(ContactPoints::formRows(ContactPoints::all(ContactPoints::CONTACT, 2)), 'departments', 'email');
    ContactPoints::syncAll(ContactPoints::CONTACT, 2, [['kind' => 'email', 'email' => 'anna@rossi.test']]);

    return array_column(Query::$tables['company_point_departments'], 'value') === ['info@rossi.test']
        && $person === ['anna@rossi.test' => ['sales'], 'casa@rossi.test' => ['purchasing']]
        && array_column(Query::$tables['contact_point_departments'], 'value') === ['anna@rossi.test'];
});

check('andata e ritorno: salvare il riquadro com\'è non cambia né la scheda né i contatti in più', function () use ($seed, $token, $card) {
    $tables = $seed();
    // Senza email principale, con una sola email etichettata; prefisso salvato senza telefono.
    $tables['companies'][2] = ['email' => '', 'phone_prefix' => '+39', 'phone' => ''] + $tables['companies'][2];
    $tables['company_points'][1] = ['id' => 1, 'company_id' => 2, 'kind' => 'email', 'value' => 'fatture@rossi.test', 'phone_prefix' => '', 'label' => 'billing', 'position' => 0, 'deleted' => 'false'];
    Query::reset($tables);
    $before = [$card('companies', 2), ContactPoints::of(ContactPoints::COMPANY, 2)];
    $rows = ContactPoints::formRows(ContactPoints::all(ContactPoints::COMPANY, 2), true);
    $saved = CompanyResource::mutateRequestValues(
        ['business_name' => 'Rossi srl', 'active' => 'true', '_csrf' => $token, 'contact_box_form' => '1', 'contact_points' => $rows],
        'update', 'backend', Query::$tables['companies'][2]
    );
    ContactPoints::syncAll(ContactPoints::COMPANY, 2, $rows);
    $same = [$card('companies', 2), ContactPoints::of(ContactPoints::COMPANY, 2)] === $before;

    // Una riga nuova non segnata diventa principale e lo resta alla scrittura, anche dopo quella già salvata.
    $more = [...$rows, ['kind' => 'email', 'email' => 'info@rossi.test', 'is_primary' => 'false']];
    $split = ContactPoints::split(ContactPoints::COMPANY, $more, Query::$tables['companies'][2], false, ContactPoints::of(ContactPoints::COMPANY, 2));
    Query::$tables['companies'][2]['email'] = $split['columns']['email'];
    ContactPoints::syncAll(ContactPoints::COMPANY, 2, $more);
    $promoted = $card('companies', 2)['email'];
    $points = array_column(ContactPoints::of(ContactPoints::COMPANY, 2), 'label', 'value');

    // Tolto il principale, il primo contatto rimasto prende il suo posto.
    ContactPoints::syncAll(ContactPoints::COMPANY, 2, [['kind' => 'email', 'email' => 'fatture@rossi.test', 'label' => 'billing']]);
    $replaced = $card('companies', 2)['email'];

    // Una persona: principali e un contatto in più tornano identici.
    $tables = $seed();
    $tables['contact_points'][1] = ['id' => 1, 'contact_id' => 2, 'kind' => 'phone', 'value' => '021234567', 'phone_prefix' => '+39', 'label' => 'work', 'position' => 0, 'deleted' => 'false'];
    Query::reset($tables);
    $person = [$card('contacts', 2), ContactPoints::of(ContactPoints::CONTACT, 2)];
    ContactPoints::syncAll(ContactPoints::CONTACT, 2, ContactPoints::formRows(ContactPoints::all(ContactPoints::CONTACT, 2)));

    return $same && ($saved['email'] ?? '') === '' && !array_key_exists('phone_prefix', $saved)
        && $split['columns']['email'] === 'info@rossi.test' && $promoted === 'info@rossi.test' && $points === ['fatture@rossi.test' => 'billing'] && $replaced === 'fatture@rossi.test'
        && [$card('contacts', 2), ContactPoints::of(ContactPoints::CONTACT, 2)] === $person;
});

check('Resource: riquadro con l\'«Ufficio» (uno solo) su persone e aziende, senza «Principale» né etichetta; script del modulo nel layout', function () {
    $columns = static function (string $resource): array {
        foreach ($resource::formSchema() as $field) {
            if ($field->name === 'contact_points') {
                return array_map(static fn ($column) => $column->name, (array) (($field->get('context') ?? [])['columns'] ?? []));
            }
        }

        return [];
    };
    $script = ContactFormScript::html();

    // L'ufficio è uno solo per riga: si posta `department`.
    return $columns(ContactResource::class) === ['kind', 'email', 'phone_prefix', 'phone', 'department', 'locked']
        && $columns(CompanyResource::class) === ['kind', 'email', 'phone_prefix', 'phone', 'department', 'locked']
        && str_starts_with($script, '<script>') && substr_count($script, '</script>') === 1 && str_contains($script, 'caContactForm')
        && str_contains($script, 'data-ca-point-kind') && ContactResource::usesContactBox() && CompanyResource::usesContactBox();
});

// Il riquadro diviso per tipo: stessa Resource, un elenco per le email e uno per i telefoni.
final class ByKindContactResource extends ContactResource
{
    public static function formSchema(): array
    {
        $fields = array_filter(parent::formSchema(), static fn ($field): bool => !in_array($field->name, ['contact_points', 'contact_box_form'], true));

        return [...array_values($fields), ...static::contactBoxByKindFields()];
    }
}

check('elenchi per tipo: tornano un solo riquadro al salvataggio, il tipo è quello dell\'elenco, le chiavi non restano nei valori', function () use ($seed, $token, $reject) {
    Query::reset($seed());
    $base = ['name' => 'Anna', 'surname' => 'Bianchi', 'active' => 'true', '_csrf' => $token, 'contact_box_form' => '1'];
    $saved = ByKindContactResource::mutateRequestValues($base + [
        // Il tipo postato nella riga non conta; la riga vuota si salta.
        'contact_points_email' => [['kind' => 'phone', 'email' => 'nuova@rossi.test', 'is_primary' => 'true'], ['kind' => 'email', 'email' => '', 'is_primary' => 'false']],
        'contact_points_phone' => [['kind' => 'phone', 'phone_prefix' => '+39', 'phone' => '021234567', 'is_primary' => 'false']],
    ], 'update', 'backend', Query::$tables['contacts'][2]);
    $folded = ByKindContactResource::foldContactPointKinds(['contact_points_phone' => [['phone' => '1']], 'x' => 1]);
    $none = ByKindContactResource::foldContactPointKinds(['x' => 1]);
    $empty = $reject(static fn () => ByKindContactResource::mutateRequestValues($base, 'update', 'backend', Query::$tables['contacts'][2]));

    return $saved['email'] === 'nuova@rossi.test' && $saved['phone'] === '021234567'
        && array_intersect_key($saved, ['contact_points' => 1, 'contact_points_email' => 1, 'contact_points_phone' => 1, 'contact_box_form' => 1]) === []
        && $folded === ['x' => 1, 'contact_points' => [['kind' => 'phone', 'phone' => '1']]] && $none === ['x' => 1]
        && $empty === (string) __t('contacts.point_required');
});

check('elenchi per tipo: ogni elenco prende le sue righe, la scrittura passa dagli stessi punti, un salvataggio rifiutato non ripesca le righe tolte', function () use ($seed) {
    $tables = $seed();
    $tables['contact_points'][1] = ['id' => 1, 'contact_id' => 2, 'kind' => 'phone', 'value' => '021234567', 'phone_prefix' => '+39', 'label' => 'work', 'position' => 0, 'deleted' => 'false'];
    Query::reset($tables);
    $form = ByKindContactResource::hydrateRepeaterFormValues([], 2, [], []);
    $refused = ByKindContactResource::hydrateRepeaterFormValues([], 2, ['contact_box_form' => '1', 'contact_points_email' => [['email' => 'x@rossi.test']]], []);
    ByKindContactResource::syncRepeaterRelations(2, ['contact_box_form' => '1',
        'contact_points_email' => [['email' => 'anna@rossi.test', 'is_primary' => 'true'], ['email' => 'due@rossi.test', 'is_primary' => 'false']],
        'contact_points_phone' => [['phone_prefix' => '+39', 'phone' => '021234567', 'is_primary' => 'true']],
    ]);
    $columns = array_map(static fn ($column) => $column->name, (array) ((ByKindContactResource::safeFormFieldsByKey()['contact_points_phone']->get('context') ?? [])['columns'] ?? []));

    return array_column($form['contact_points_email'], 'email') === ['anna@rossi.test']
        && array_column($form['contact_points_phone'], 'phone') === ['3331112222', '021234567']
        && !array_key_exists('contact_points', $form)
        && array_column($refused['contact_points_email'], 'email') === ['x@rossi.test'] && $refused['contact_points_phone'] === []
        && Query::$tables['contacts'][2]['email'] === 'anna@rossi.test' && Query::$tables['contacts'][2]['phone'] === '021234567'
        && array_column(ContactPoints::of(ContactPoints::CONTACT, 2), 'value') === ['due@rossi.test']
        && $columns === ['kind', 'phone_prefix', 'phone', 'departments', 'locked']
        && ByKindContactResource::usesContactBox();
});

summary();
