<?php
/** php tests/CrmContactsTest.php — CRM dei recapiti: tipi di telefono, «Generale», ufficio singolo, recapiti del referente, flag non postati, CRM spento, social della persona (database finto). */
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

use Wonder\Plugin\CustomerAccount\Support\BrandData;
use Wonder\Plugin\CustomerAccount\Support\ContactPoints;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;
use Wonder\Plugin\CustomerAccount\Support\Crm;
use Wonder\Plugin\CustomerAccount\Support\Socials;

$tables = static fn (): array => [
    'contacts' => [
        2 => ['id' => 2, 'name' => 'Anna', 'surname' => 'Bianchi', 'email' => 'anna@casa.test', 'phone_prefix' => '+39', 'phone' => '3331112222', 'phone_kind' => 'mobile', 'user_id' => 0, 'active' => 'true', 'deleted' => 'false'],
        3 => ['id' => 3, 'name' => 'Luca', 'surname' => 'Verdi', 'email' => 'luca@casa.test', 'phone_prefix' => '', 'phone' => '', 'user_id' => 0, 'active' => 'true', 'deleted' => 'false'],
        5 => ['id' => 5, 'name' => 'Ada', 'surname' => 'Neri', 'email' => 'ada@example.com', 'phone_prefix' => '', 'phone' => '', 'user_id' => 7, 'active' => 'true', 'deleted' => 'false'],
    ],
    'companies' => [
        1 => ['id' => 1, 'business_name' => 'Rossi srl', 'email' => 'info@rossi.test', 'phone_prefix' => '', 'phone' => '', 'deleted' => 'false'],
    ],
    'contact_relations' => [],
    'contact_relation_departments' => [],
    'contact_relation_brands' => [],
    'contact_relation_points' => [],
    'company_brand_links' => [],
    'contact_points' => [],
    'company_points' => [],
    'company_point_departments' => [],
    'contact_point_departments' => [],
    'contact_office_types' => [],
    'external_references' => [],
];
$relation = static function (int $personId): array {
    foreach (Query::$tables['contact_relations'] as $row) {
        if ((int) $row['person_id'] === $personId && ($row['deleted'] ?? 'false') === 'false') {
            return $row;
        }
    }

    return [];
};
$lang = static fn (string $locale): array => json_decode((string) file_get_contents(dirname(__DIR__).'/lang/'.$locale.'/contacts.json'), true);
$ids = static fn (array $rows): array => array_map(static fn (array $row): int => (int) $row['person_id'], $rows);

check('tipi di recapito: email, fisso, cellulare e WhatsApp, con le loro etichette', function () use ($lang) {
    $options = ContactPoints::kindOptions();

    return ContactPoints::kinds() === ['email', 'phone', 'mobile', 'whatsapp']
        && array_keys($options) === ContactPoints::kinds()
        && $options['phone'] === 'contacts.point_kind_landline' && $options['mobile'] === 'contacts.point_kind_mobile' && $options['whatsapp'] === 'contacts.point_kind_whatsapp'
        && $lang('it')['point_kind_landline'] === 'Telefono fisso' && $lang('it')['point_kind_mobile'] === 'Cellulare' && $lang('it')['point_kind_whatsapp'] === 'WhatsApp'
        && $lang('en')['point_kind_landline'] === 'Landline' && $lang('en')['point_kind_mobile'] === 'Mobile' && $lang('en')['point_kind_whatsapp'] === 'WhatsApp'
        && ContactPoints::kindLabel('fax') === ''
        && ContactPoints::isPhone('mobile') && ContactPoints::isPhone('whatsapp') && ContactPoints::isPhone('phone') && !ContactPoints::isPhone('email')
        && ContactPoints::family('whatsapp') === 'phone' && ContactPoints::family('email') === 'email';
});

check('cleanBox: cellulare e WhatsApp si puliscono come un telefono (prefisso e sole cifre)', function () {
    [$rows] = ContactPoints::cleanBox([
        ['kind' => 'mobile', 'phone_prefix' => '+39', 'phone' => '333 111 2222'],
        ['kind' => 'whatsapp', 'phone_prefix' => '+39', 'phone' => '333-444-5555'],
        ['kind' => 'phone', 'phone_prefix' => '+39', 'phone' => '02 123456'],
        ['kind' => 'fax', 'phone' => '021111'],
    ]);

    // Un tipo sconosciuto con un numero vale telefono fisso, come prima.
    return array_column($rows, 'kind') === ['mobile', 'whatsapp', 'phone', 'phone']
        && array_column($rows, 'value') === ['3331112222', '3334445555', '02123456', '021111']
        && $rows[0]['phone_prefix'] === '+39';
});

check('split: il telefono principale è il primo della famiglia, di qualunque tipo, e il tipo va in phone_kind', function () {
    $split = ContactPoints::split(ContactPoints::CONTACT, [
        ['kind' => 'email', 'email' => 'a@rossi.test'],
        ['kind' => 'whatsapp', 'phone_prefix' => '+39', 'phone' => '3330001111'],
        ['kind' => 'phone', 'phone_prefix' => '+39', 'phone' => '02123456'],
    ], [], false, [], ['first_primary' => true]);
    $primary = array_values(array_filter($split['rows'], static fn (array $row): bool => $row['is_primary']));

    return $split['problems'] === []
        && $split['columns']['email'] === 'a@rossi.test'
        && $split['columns']['phone'] === '3330001111' && $split['columns']['phone_prefix'] === '+39'
        && ($split['columns']['phone_kind'] ?? '') === 'whatsapp'
        && array_column($primary, 'kind') === ['email', 'whatsapp'];
});

check('syncAll: un cellulare principale e un fisso in più; all() li rilegge con il loro tipo', function () use ($tables) {
    Query::reset($tables());
    $rows = ContactPoints::syncAll(ContactPoints::CONTACT, 3, [
        ['kind' => 'email', 'email' => 'luca@casa.test'],
        ['kind' => 'mobile', 'phone_prefix' => '+39', 'phone' => '3470001111'],
        ['kind' => 'phone', 'phone_prefix' => '+39', 'phone' => '02123456'],
    ], ['first_primary' => true]);
    $card = Query::$tables['contacts'][3];
    $extra = array_values(Query::$tables['contact_points']);

    return $card['phone'] === '3470001111' && $card['phone_kind'] === 'mobile'
        && count($extra) === 1 && $extra[0]['kind'] === 'phone' && $extra[0]['value'] === '02123456'
        && array_column($rows, 'kind') === ['email', 'mobile', 'phone'];
});

check('«Generale» è il primo ufficio e risponde per tutti, come nessun ufficio', function () use ($lang) {
    return array_key_first(ContactRelations::departments()) === ContactRelations::GENERAL
        && ContactRelations::departmentLabel('general') === 'contacts.department_general'
        && $lang('it')['department_general'] === 'Generale' && $lang('en')['department_general'] === 'General'
        && $lang('it')['office_type'] === 'Ufficio' && $lang('it')['office_types'] === 'Uffici' && $lang('en')['office_type'] === 'Office' && $lang('en')['office_types'] === 'Offices'
        && ContactRelations::coversDepartment(['general'], 'sales')
        && ContactRelations::coversDepartment([], 'sales')
        && ContactRelations::coversDepartment(['sales', 'technical'], 'sales')
        && !ContactRelations::coversDepartment(['technical'], 'sales')
        && ContactRelations::coversDepartment(['technical'], '')
        && ContactRelations::coversDepartment(['technical'], ['sales', 'technical']);
});

check('peopleFor e peopleByDepartment: chi ha «Generale» esce per ogni area', function () use ($tables, $ids) {
    Query::reset($tables());
    ContactRelations::sync(ContactRelations::COMPANY, 1, [
        ['person_id' => '2', 'department' => 'technical'],
        ['person_id' => '3', 'department' => 'general'],
        ['person_id' => '5', 'department' => 'sales'],
    ]);

    return $ids(ContactRelations::peopleFor(1, 'sales')) === [3, 5]
        && $ids(ContactRelations::peopleFor(1, 'administration')) === [3]
        && $ids(ContactRelations::peopleFor(1)) === [2, 3, 5]
        && $ids(ContactRelations::peopleByDepartment(1, ['technical'])) === [2, 3]
        && ContactRelations::peopleOf(1)[1]['department'] === 'general';
});

check('companyEmailsFor: una casella «Generale» riceve per ogni area', function () use ($tables) {
    Query::reset($tables());
    ContactPoints::syncAll(ContactPoints::COMPANY, 1, [
        ['kind' => 'email', 'email' => 'info@rossi.test', 'department' => 'technical'],
        ['kind' => 'email', 'email' => 'tutti@rossi.test', 'department' => 'general'],
        ['kind' => 'email', 'email' => 'vendite@rossi.test', 'department' => 'sales'],
    ], ['first_primary' => true]);

    return ContactPoints::companyEmailsFor(1, 'sales') === ['tutti@rossi.test', 'vendite@rossi.test']
        && ContactPoints::companyEmailsFor(1, 'administration') === ['tutti@rossi.test']
        && ContactPoints::companyEmailsFor(1, 'technical') === ['info@rossi.test', 'tutti@rossi.test'];
});

check('ufficio singolo dei recapiti: ripostare quello mostrato tiene gli altri salvati', function () use ($tables) {
    Query::reset($tables());
    ContactPoints::syncAll(ContactPoints::COMPANY, 1, [
        ['kind' => 'email', 'email' => 'info@rossi.test', 'departments' => ['sales', 'technical']],
        ['kind' => 'email', 'email' => 'ordini@rossi.test', 'departments' => ['purchasing']],
    ], ['first_primary' => true]);
    $first = ContactPoints::all(ContactPoints::COMPANY, 1)[0]['department'];
    $rows = ContactPoints::syncAll(ContactPoints::COMPANY, 1, [
        ['kind' => 'email', 'email' => 'info@rossi.test', 'department' => 'sales'],
        ['kind' => 'email', 'email' => 'ordini@rossi.test', 'department' => 'administration'],
    ], ['first_primary' => true]);

    return $first === 'sales' && array_column($rows, 'departments') === [['sales', 'technical'], ['administration']];
});

check('ufficio singolo: ripostare quello mostrato tiene gli altri salvati, sceglierne un altro li sostituisce', function () use ($tables, $relation) {
    $single = ContactRelations::singleDepartment('sales', ['sales', 'technical']) === ['sales', 'technical']
        && ContactRelations::singleDepartment('technical', ['sales', 'technical']) === ['technical']
        && ContactRelations::singleDepartment('', ['sales', 'technical']) === []
        && ContactRelations::singleDepartment('', []) === []
        && ContactRelations::singleDepartment('boh', ['sales']) === []
        && ContactRelations::firstDepartment(['boh', 'technical', 'sales']) === 'technical';

    Query::reset($tables());
    ContactRelations::sync(ContactRelations::COMPANY, 1, [['person_id' => '2', 'departments' => ['sales', 'technical']]]);
    $id = (int) $relation(2)['id'];
    // Il form a scelta singola mostra «sales» e lo riposta senza toccarlo.
    ContactRelations::sync(ContactRelations::COMPANY, 1, [['person_id' => '2', 'department' => 'sales']]);
    $kept = ContactRelations::departmentsOf($id);
    ContactRelations::sync(ContactRelations::COMPANY, 1, [['person_id' => '2', 'department' => 'purchasing']]);
    $changed = ContactRelations::departmentsOf($id);
    // Una riga senza la chiave non tocca gli uffici.
    ContactRelations::sync(ContactRelations::COMPANY, 1, [['person_id' => '2']]);

    return $single && $kept === ['sales', 'technical'] && $changed === ['purchasing'] && ContactRelations::departmentsOf($id) === ['purchasing'];
});

check('recapiti del referente: più righe per azienda, la prima email e il primo telefono a specchio sul legame', function () use ($tables, $relation) {
    Query::reset($tables());
    ContactRelations::sync(ContactRelations::COMPANY, 1, [[
        'person_id' => '2',
        'points' => [
            ['kind' => 'whatsapp', 'phone_prefix' => '+39', 'phone' => '333 000 1111'],
            ['kind' => 'email', 'email' => 'Anna@Rossi.test'],
            ['kind' => 'email', 'email' => 'acquisti@rossi.test', 'label' => 'work'],
            ['kind' => 'phone', 'phone_prefix' => '+39', 'phone' => '02123456'],
            ['kind' => 'email', 'email' => 'non valida'],
        ],
    ]]);
    $row = $relation(2);
    $points = ContactRelations::pointsOf((int) $row['id']);
    $person = ContactRelations::peopleOf(1)[0];

    return $row['email'] === 'anna@rossi.test' && $row['phone'] === '3330001111' && $row['phone_prefix'] === '+39'
        && array_column($points, 'kind') === ['whatsapp', 'email', 'email', 'phone']
        && array_column($points, 'value') === ['3330001111', 'anna@rossi.test', 'acquisti@rossi.test', '02123456']
        && $person['points'] === $points
        && $person['contact_email'] === 'anna@rossi.test'
        && ContactRelations::companiesOf(2)[0]['points'] === $points
        && ContactRelations::mirror($points) === ['email' => 'anna@rossi.test', 'phone_prefix' => '+39', 'phone' => '3330001111'];
});

check('recapiti del referente: un legame di prima si legge dalle sue colonne; i campi singoli non tolgono i recapiti in più', function () use ($tables, $relation) {
    Query::reset($tables());
    // Il form di prima: email e telefono singoli, nessun `points`.
    ContactRelations::sync(ContactRelations::COMPANY, 1, [['person_id' => '3', 'email' => 'luca@rossi.test', 'phone_prefix' => '+39', 'phone' => '021111']]);
    $id = (int) $relation(3)['id'];
    $fallback = ContactRelations::pointsOf($id);
    $noRows = Query::$tables['contact_relation_points'] === [];

    ContactRelations::sync(ContactRelations::COMPANY, 1, [['person_id' => '3', 'points' => [
        ['kind' => 'email', 'email' => 'luca@rossi.test'],
        ['kind' => 'mobile', 'phone_prefix' => '+39', 'phone' => '3475556666'],
        ['kind' => 'email', 'email' => 'tecnico@rossi.test'],
    ]]]);
    // Di nuovo il form con i campi singoli: cambia la prima email, il resto resta.
    ContactRelations::sync(ContactRelations::COMPANY, 1, [['person_id' => '3', 'email' => 'l.verdi@rossi.test', 'phone_prefix' => '+39', 'phone' => '3475556666']]);
    $points = ContactRelations::pointsOf($id);
    $row = $relation(3);

    return $noRows
        && $fallback === [
            ['kind' => 'email', 'value' => 'luca@rossi.test', 'phone_prefix' => '', 'label' => ''],
            ['kind' => 'phone', 'value' => '021111', 'phone_prefix' => '+39', 'label' => ''],
        ]
        && array_column($points, 'value') === ['l.verdi@rossi.test', '3475556666', 'tecnico@rossi.test']
        && array_column($points, 'kind') === ['email', 'mobile', 'email']
        && $row['email'] === 'l.verdi@rossi.test' && $row['phone'] === '3475556666';
});

check('recapiti del referente: una lista vuota li toglie tutti, senza la chiave restano', function () use ($tables, $relation) {
    Query::reset($tables());
    $points = [['kind' => 'email', 'email' => 'anna@rossi.test'], ['kind' => 'mobile', 'phone_prefix' => '+39', 'phone' => '3330001111']];
    ContactRelations::sync(ContactRelations::COMPANY, 1, [['person_id' => '2', 'points' => $points]]);
    $id = (int) $relation(2)['id'];
    ContactRelations::sync(ContactRelations::COMPANY, 1, [['person_id' => '2', 'job_title' => 'Acquisti']]);
    $kept = count(ContactRelations::pointsOf($id));
    ContactRelations::sync(ContactRelations::COMPANY, 1, [['person_id' => '2', 'points' => []]]);
    $row = $relation(2);

    return $kept === 2 && ContactRelations::pointsOf($id) === []
        && $row['email'] === '' && $row['phone'] === '' && $row['phone_prefix'] === '';
});

check('withPoints: i recapiti del legame nella forma del ripetitore', function () use ($tables, $relation) {
    Query::reset($tables());
    ContactRelations::sync(ContactRelations::COMPANY, 1, [['person_id' => '2', 'points' => [
        ['kind' => 'email', 'email' => 'anna@rossi.test'],
        ['kind' => 'mobile', 'phone_prefix' => '+39', 'phone' => '3330001111'],
    ]]]);
    $rows = ContactRelations::withPoints([$relation(2)]);
    $points = $rows[0]['points'];

    return count($points) === 2
        && $points[0]['kind'] === 'email' && $points[0]['email'] === 'anna@rossi.test'
        && $points[1]['kind'] === 'mobile' && $points[1]['phone'] === '3330001111' && $points[1]['phone_prefix'] === '+39';
});

check('flag non postati: un legame nuovo nasce principale, con i documenti e accesso buyer; nessuno perde il titolo', function () use ($tables, $relation) {
    Query::reset($tables());
    ContactRelations::sync(ContactRelations::COMPANY, 1, [['person_id' => '2'], ['person_id' => '3']]);
    $anna = $relation(2);
    $luca = $relation(3);

    return $anna['is_primary'] === 'true' && $anna['receives_documents'] === 'true' && $anna['access'] === 'buyer'
        && $luca['is_primary'] === 'true' && $luca['receives_documents'] === 'true' && $luca['access'] === 'buyer';
});

check('flag non postati: un legame salvato tiene i suoi; la guardia dell\'accesso vale anche per il default', function () use ($tables, $relation) {
    Query::reset($tables());
    ContactRelations::sync(ContactRelations::COMPANY, 1, [
        ['person_id' => '2', 'is_primary' => 'false', 'receives_documents' => 'false', 'access' => 'admin'],
    ]);
    // Il form senza i flag: Anna resta com'è, Luca nasce con i default.
    ContactRelations::sync(ContactRelations::COMPANY, 1, [['person_id' => '2', 'job_title' => 'Acquisti'], ['person_id' => '3']]);
    $anna = $relation(2);
    $luca = $relation(3);

    ContactRelations::useAccessGuard(static fn (): bool => false);
    ContactRelations::sync(ContactRelations::COMPANY, 1, [['person_id' => '2'], ['person_id' => '3'], ['person_id' => '5']]);
    $guarded = $relation(5);
    ContactRelations::useAccessGuard(null);

    return $anna['is_primary'] === 'false' && $anna['receives_documents'] === 'false' && $anna['access'] === 'admin' && $anna['job_title'] === 'Acquisti'
        && $luca['is_primary'] === 'true' && $luca['access'] === 'buyer'
        && $guarded['access'] === 'none' && $guarded['is_primary'] === 'true';
});

check('CRM spento: l\'interruttore parte spento e si riaccende', function () {
    $start = Crm::enabled();
    Crm::enable();
    $on = Crm::enabled();
    Crm::enable(false);

    return $start === false && $on === true && Crm::enabled() === false;
});

check('CRM spento: il form con un\'email e un telefono soli non cancella i recapiti in più', function () use ($tables) {
    Query::reset($tables());
    ContactPoints::syncAll(ContactPoints::CONTACT, 2, [
        ['kind' => 'email', 'email' => 'anna@casa.test'],
        ['kind' => 'email', 'email' => 'anna@lavoro.test'],
        ['kind' => 'mobile', 'phone_prefix' => '+39', 'phone' => '3331112222'],
        ['kind' => 'phone', 'phone_prefix' => '+39', 'phone' => '02123456'],
    ], ['first_primary' => true]);
    $before = array_column(Query::$tables['contact_points'], 'value');

    // CRM spento: arrivano solo la prima email (cambiata) e il primo telefono.
    $rows = ContactPoints::syncAll(ContactPoints::CONTACT, 2, [
        ['kind' => 'email', 'email' => 'anna@nuova.test'],
        ['kind' => 'mobile', 'phone_prefix' => '+39', 'phone' => '3331112222'],
    ], ['primary_only' => true]);
    $card = Query::$tables['contacts'][2];
    $after = array_values(array_map(static fn (array $row): string => $row['value'], array_filter(Query::$tables['contact_points'], static fn (array $row): bool => ($row['deleted'] ?? 'false') === 'false')));
    sort($before);
    sort($after);

    return $before === ['02123456', 'anna@lavoro.test'] && $after === $before
        && $card['email'] === 'anna@nuova.test' && $card['phone'] === '3331112222' && $card['phone_kind'] === 'mobile'
        && count($rows) === 4
        && ContactPoints::sync(ContactPoints::CONTACT, 2, [], true) === ContactPoints::of(ContactPoints::CONTACT, 2)
        && count(ContactPoints::of(ContactPoints::CONTACT, 2)) === 2;
});

check('CRM spento: il brand tiene domini, recapiti e social in più', function () {
    $stored = [
        'email' => 'info@rossi.test', 'phone_prefix' => '+39', 'phone' => '3330001111',
        'contacts' => json_encode(BrandData::contacts([
            ['kind' => 'email', 'email' => 'info@rossi.test'],
            ['kind' => 'email', 'email' => 'press@rossi.test'],
            ['kind' => 'mobile', 'phone_prefix' => '+39', 'phone' => '3330001111'],
            ['kind' => 'phone', 'phone_prefix' => '+39', 'phone' => '02123456'],
        ])),
        'socials' => json_encode(['instagram' => 'https://instagram.com/rossi']),
    ];
    // Il form mostra la prima email e il primo telefono, e nessun social.
    [$clean, $errors] = BrandData::clean([
        'name' => 'Rossi',
        'contacts' => [['kind' => 'email', 'email' => 'ciao@rossi.test'], ['kind' => 'mobile', 'phone_prefix' => '+39', 'phone' => '3330001111']],
        'socials' => [],
    ], true, $stored, true);
    $contacts = BrandData::contactsOf($clean + $stored);

    return $errors === []
        && $clean['email'] === 'ciao@rossi.test' && $clean['phone'] === '3330001111'
        && array_column($contacts, 'kind') === ['email', 'mobile', 'email', 'phone']
        && ($contacts[2]['email'] ?? '') === 'press@rossi.test' && ($contacts[3]['phone'] ?? '') === '02123456'
        && !array_key_exists('socials', $clean);
});

check('brand con il CRM e senza kit: passano recapiti e social, il resto del kit no', function () {
    $posted = [
        'contacts' => [['kind' => 'whatsapp', 'phone_prefix' => '+39', 'phone' => '3330001111'], ['kind' => 'email', 'email' => 'info@rossi.it']],
        'socials' => [['network' => 'instagram', 'url' => 'https://instagram.com/rossi']],
        'tone' => 'Diretto',
    ];
    [$crm, $errors] = BrandData::clean($posted, false, [], false, true);
    [$off] = BrandData::clean($posted, false);

    return $errors === [] && $crm['email'] === 'info@rossi.it' && $crm['phone'] === '3330001111'
        && str_contains($crm['socials'], 'instagram') && !array_key_exists('tone', $crm)
        && !array_key_exists('contacts', $off) && !array_key_exists('socials', $off);
});

check('social della persona: stesse reti e stessa pulizia dei brand, colonna in JSON, righe del ripetitore', function () {
    $column = Socials::column([
        ['network' => 'LinkedIn', 'url' => 'https://linkedin.com/in/anna'],
        ['network' => 'instagram', 'url' => ''],
        ['network' => 'x', 'url' => 'x.com/anna'],
    ]);
    Socials::add('threads', 'Threads');

    return Socials::of(['socials' => $column]) === ['linkedin' => 'https://linkedin.com/in/anna', 'x' => 'https://x.com/anna']
        && Socials::formRows($column) === [['network' => 'linkedin', 'url' => 'https://linkedin.com/in/anna'], ['network' => 'x', 'url' => 'https://x.com/anna']]
        && Socials::column([]) === '' && Socials::of(null) === [] && Socials::of('rotto') === []
        && Socials::column(['myspace' => 'https://myspace.com/anna']) === null
        && Socials::column(['linkedin' => 'javascript:alert(1)']) === null
        && Socials::clean(['threads' => 'https://threads.net/@anna']) === ['threads' => 'https://threads.net/@anna']
        && array_key_exists('linkedin', Socials::networks()) && Socials::networks() === BrandData::socials();
});

check('email di accesso a parte: le righe del form senza l\'email dell\'account; il riquadro non la scrive', function () use ($tables) {
    Query::reset($tables());
    ContactPoints::syncAll(ContactPoints::CONTACT, 5, [
        ['kind' => 'email', 'email' => 'ada@lavoro.test'],
        ['kind' => 'mobile', 'phone_prefix' => '+39', 'phone' => '3339998888'],
    ], ['first_primary' => true, 'separate_email' => true]);
    $card = Query::$tables['contacts'][5];
    $rows = ContactPoints::formRowsWithoutAccess(ContactPoints::CONTACT, 5);
    $all = ContactPoints::all(ContactPoints::CONTACT, 5);

    // Senza account: l'email della scheda è comunque quella del campo a parte.
    ContactPoints::syncAll(ContactPoints::CONTACT, 3, [['kind' => 'email', 'email' => 'luca@lavoro.test']], ['first_primary' => true, 'separate_email' => 'luca@casa.test']);
    $luca = ContactPoints::formRowsWithoutAccess(ContactPoints::CONTACT, 3);

    return $card['email'] === 'ada@example.com' && $card['phone'] === '3339998888'
        && count($all) === 3 && count(ContactPoints::withoutAccess($all)) === 2
        && array_column($rows, 'email') === ['', 'ada@lavoro.test']
        && array_column($rows, 'kind') === ['mobile', 'email']
        && Query::$tables['contacts'][3]['email'] === 'luca@casa.test'
        && array_column($luca, 'email') === ['luca@lavoro.test'];
});

summary();
