<?php
/** php tests/ContactAccountTest.php — collegamento utente-scheda: identità, conflitti e creazione implicita (database finto). */
declare(strict_types=1);

require __DIR__.'/support/FakeDb.php';
require __DIR__.'/bootstrap.php';

use Wonder\Plugin\CustomerAccount\Account\ContactAccount;
use Wonder\Plugin\CustomerAccount\Account\CustomerContact;
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
        && $contact['is_customer'] === 'true' && $contact['active'] === 'true';
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
