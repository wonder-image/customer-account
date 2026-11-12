<?php
/** php tests/ContactAccountTest.php — collegamento utente-scheda: identità, conflitti e creazione implicita (database finto). */
declare(strict_types=1);

require __DIR__.'/support/FakeDb.php';
require __DIR__.'/bootstrap.php';
require_once WI_APP_ROOT.'/app/function/other/geo.php';

use Wonder\Plugin\CustomerAccount\Account\AccountBilling;
use Wonder\Plugin\CustomerAccount\Account\ContactAccount;
use Wonder\Plugin\CustomerAccount\Account\CustomerAccountExtension;
use Wonder\Plugin\CustomerAccount\Account\CustomerContact;
use Wonder\Plugin\CustomerAccount\Support\ContactPoints;
use Wonder\Plugin\CustomerAccount\Support\Crm;
use Wonder\Sql\Query;

$GLOBALS['__fake_users'] = [
    ['id' => 7, 'email' => 'ada@example.com', 'name' => 'Ada', 'surname' => 'Lovelace'],
    ['id' => 8, 'email' => 'bob@example.com', 'name' => 'Bob', 'surname' => 'Rossi'],
];
// Scheda compilata da un ordine da ospite, con la stessa email dell'utente 7.
$guest = ['id' => 3, 'deleted' => 'false', 'email' => 'ada@example.com', 'name' => 'Ada M.', 'surname' => 'King', 'phone_prefix' => '+39', 'phone' => '3330000000', 'user_id' => 0];
$row = static fn (int $id): array => Query::$tables['contacts'][$id] ?? [];

check('utente che non esiste: nessuna scrittura', function () {
    Query::reset();
    $link = ContactAccount::link(99);
    return $link->success === false && $link->reason === 'user_not_found' && Query::$writes === [];
});

check('link senza scheda: la crea con i dati dell\'utente e il telefono pulito', function () use ($row) {
    Query::reset();
    $link = ContactAccount::link(7, ['phone_prefix' => '+39 ', 'phone' => '333 123-4567']);
    $contact = $row($link->contact_id);
    return $link->success && $link->reason === '' && $contact['name'] === 'Ada' && $contact['email'] === 'ada@example.com'
        && (int) $contact['user_id'] === 7 && $contact['phone'] === '3331234567' && $contact['phone_prefix'] === '+39'
        && $contact['is_customer'] === 'true' && $contact['active'] === 'true' && $contact['phone_kind'] === 'mobile';
});

check('telefono che nasce dal sito su una scheda senza telefono: tipo cellulare', function () use ($guest, $row) {
    Query::reset(['contacts' => [3 => ['phone' => '', 'phone_kind' => 'phone'] + $guest]]);
    $link = ContactAccount::link(7, ['phone_prefix' => '+39', 'phone' => '3331234567']);
    return $link->success && $row(3)['phone'] === '3331234567' && $row(3)['phone_kind'] === 'mobile';
});

check('link riusa la scheda con la stessa email: identità riscritta, telefono vuoto non la svuota', function () use ($guest, $row) {
    Query::reset(['contacts' => [3 => $guest]]);
    $link = ContactAccount::link(7, ['phone' => '']);
    $contact = $row(3);
    return $link->success && $link->contact_id === 3 && count(Query::$tables['contacts']) === 1
        && $contact['name'] === 'Ada' && $contact['surname'] === 'Lovelace' && $contact['phone'] === '3330000000' && (int) $contact['user_id'] === 7;
});

check('scheda con la stessa email ma di un altro utente: conflitto, nessuna scrittura', function () use ($guest) {
    foreach (['link', 'linkKeepingIdentity'] as $method) {
        Query::reset(['contacts' => [3 => ['user_id' => 8] + $guest]]);
        $link = ContactAccount::$method(7);
        if ($link->success || $link->reason !== 'contact_link_conflict' || Query::$writes !== []) {
            return false;
        }
    }
    return true;
});

check('linkKeepingIdentity su una scheda che esiste: nome, cognome, email e telefono restano', function () use ($guest, $row) {
    Query::reset(['contacts' => [3 => $guest]]);
    $link = ContactAccount::linkKeepingIdentity(7, ['phone_prefix' => '+39', 'phone' => '3999999999']);
    $contact = $row(3);
    return $link->success && $link->contact_id === 3
        && $contact['name'] === 'Ada M.' && $contact['surname'] === 'King' && $contact['phone'] === '3330000000'
        && (int) $contact['user_id'] === 7 && $contact['is_customer'] === 'true' && $contact['active'] === 'true';
});

check('linkKeepingIdentity senza scheda: la crea con i dati dell\'utente', function () use ($row) {
    Query::reset();
    $link = ContactAccount::linkKeepingIdentity(8);
    $contact = $row($link->contact_id);
    return $link->success && $contact['name'] === 'Bob' && $contact['email'] === 'bob@example.com' && (int) $contact['user_id'] === 8;
});

// Scheda con account, recapiti in più, uffici e social scritti dal backend.
$crmTables = static fn (): array => [
    'contacts' => [3 => [
        'id' => 3, 'deleted' => 'false', 'email' => 'ada@example.com', 'name' => 'Ada', 'surname' => 'Lovelace',
        'phone_prefix' => '+39', 'phone' => '021111', 'phone_kind' => 'phone', 'user_id' => 7, 'socials' => '{"instagram":"ada"}',
    ]],
    'contact_points' => [
        1 => ['id' => 1, 'contact_id' => 3, 'kind' => 'mobile', 'value' => '3339998888', 'phone_prefix' => '+39', 'label' => 'personal', 'position' => 0, 'deleted' => 'false'],
        2 => ['id' => 2, 'contact_id' => 3, 'kind' => 'email', 'value' => 'ada@lavoro.test', 'phone_prefix' => '', 'label' => 'work', 'position' => 1, 'deleted' => 'false'],
    ],
    'contact_point_departments' => [
        1 => ['id' => 1, 'contact_id' => 3, 'kind' => 'phone', 'value' => '021111', 'department' => 'sales', 'deleted' => 'false'],
        2 => ['id' => 2, 'contact_id' => 3, 'kind' => 'mobile', 'value' => '3339998888', 'department' => 'sales', 'deleted' => 'false'],
    ],
];
$live = static fn (string $table): array => array_values(array_filter(Query::$tables[$table] ?? [], static fn (array $r): bool => ($r['deleted'] ?? 'false') === 'false'));

check('telefono dal pannello: recapiti in più, social e tipo del telefono restano (CRM acceso e spento)', function () use ($crmTables, $live, $row) {
    foreach ([false, true] as $crm) {
        Crm::enable($crm);
        Query::reset($crmTables());
        $link = ContactAccount::link(7, ['phone_prefix' => '+39', 'phone' => '02 2222']);
        $contact = $row(3);
        $ok = $link->success && $contact['phone'] === '022222' && $contact['phone_kind'] === 'phone' && $contact['email'] === 'ada@example.com'
            && $contact['socials'] === '{"instagram":"ada"}'
            && array_column($live('contact_points'), 'value') === ['3339998888', 'ada@lavoro.test'];
        Crm::enable(false);
        if (!$ok) {
            return false;
        }
    }
    return true;
});

check('telefono dal pannello: gli uffici del numero tolto se ne vanno, quelli degli altri recapiti restano', function () use ($crmTables, $live) {
    Query::reset($crmTables());
    ContactAccount::link(7, ['phone_prefix' => '+39', 'phone' => '022222']);
    return array_column($live('contact_point_departments'), 'value') === ['3339998888'];
});

check('telefono dal pannello uguale a un recapito in più: non resta due volte e tiene tipo e uffici', function () use ($crmTables, $live, $row) {
    Query::reset($crmTables());
    $link = ContactAccount::link(7, ['phone_prefix' => '+39', 'phone' => '333 999 8888']);
    $contact = $row(3);
    $phones = array_values(array_filter(ContactPoints::all(ContactPoints::CONTACT, 3), static fn (array $r): bool => ContactPoints::isPhone($r['kind'])));
    return $link->success && $contact['phone'] === '3339998888' && $contact['phone_kind'] === 'mobile'
        && array_column($live('contact_points'), 'value') === ['ada@lavoro.test']
        && count($phones) === 1 && $phones[0]['is_primary'] === true && $phones[0]['kind'] === 'mobile'
        && array_map(static fn (array $r): string => $r['kind'].'|'.$r['value'], $live('contact_point_departments')) === ['mobile|3339998888'];
});

check('telefono vuoto o uguale dal pannello: nessuna scrittura sui recapiti', function () use ($crmTables, $live) {
    foreach (['', '021111'] as $phone) {
        Query::reset($crmTables());
        ContactAccount::link(7, ['phone_prefix' => '+39', 'phone' => $phone]);
        if (count($live('contact_points')) !== 2 || count($live('contact_point_departments')) !== 2) {
            return false;
        }
    }
    return true;
});

check('ContactPoints::primaryWritten: l\'email di accesso uguale a un\'email in più non resta due volte', function () use ($crmTables, $live, $row) {
    $tables = $crmTables();
    $tables['contacts'][3]['email'] = 'Ada@Lavoro.test';
    Query::reset($tables);
    ContactPoints::primaryWritten(ContactPoints::CONTACT, 3);
    return array_column($live('contact_points'), 'value') === ['3339998888'] && $row(3)['phone_kind'] === 'phone';
});

check('fatturazione dal pannello: il telefono uguale a un recapito in più non resta due volte, gli altri restano', function () use ($crmTables, $live, $row) {
    $tables = $crmTables();
    $tables['contacts'][3] += ['country' => 'IT', 'province' => 'MI', 'city' => 'Milano', 'cap' => '20100', 'street' => 'Via Roma', 'number' => '1', 'cf' => 'LVLDAA80A41F205X'];
    Query::reset($tables);
    $input = ['phone_prefix' => '+39', 'phone' => '3339998888'] + array_intersect_key($tables['contacts'][3], array_flip(['name', 'surname', 'country', 'province', 'city', 'cap', 'street', 'number', 'cf']));
    $saved = AccountBilling::save(3, $input);
    return $saved->success && $row(3)['phone'] === '3339998888' && $row(3)['phone_kind'] === 'mobile' && $row(3)['email'] === 'ada@example.com'
        && array_column($live('contact_points'), 'value') === ['ada@lavoro.test']
        && array_column($live('contact_point_departments'), 'value') === ['3339998888'];
});

check('email di accesso confermata dal pannello: un\'email in più uguale non resta due volte', function () use ($crmTables, $live, $row) {
    Query::reset($crmTables());
    (new CustomerAccountExtension())->afterEmailConfirmed(7, 'ada@lavoro.test');
    return $row(3)['email'] === 'ada@lavoro.test' && array_column($live('contact_points'), 'value') === ['3339998888']
        && $row(3)['phone'] === '021111' && $row(3)['socials'] === '{"instagram":"ada"}';
});

check('scrittura che fallisce: contact_write_failed', function () {
    Query::reset();
    Query::$failWrites = true;
    $link = ContactAccount::link(7);
    return !$link->success && $link->reason === 'contact_write_failed';
});

check('CustomerContact::forUser crea la scheda che manca (non è una lettura pura)', function () {
    Query::reset();
    CustomerContact::forget();
    $contact = CustomerContact::forUser(8);
    return (int) ($contact['user_id'] ?? 0) === 8 && count(Query::$tables['contacts'] ?? []) === 1;
});

summary();
