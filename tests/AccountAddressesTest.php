<?php
/** php tests/AccountAddressesTest.php — indirizzi della scheda: proprietà, posizione e predefinito (database finto). */
declare(strict_types=1);

require __DIR__.'/support/FakeDb.php';
require __DIR__.'/bootstrap.php';
require_once WI_APP_ROOT.'/app/function/other/geo.php';

use Wonder\Plugin\CustomerAccount\Account\AccountAddresses;
use Wonder\Sql\Query;

$address = ['name' => 'Ada', 'surname' => 'Lovelace', 'country' => 'IT', 'street' => 'Via Roma', 'number' => '1', 'city' => 'Milano', 'cap' => '20100', 'province' => 'MI'];
$row = static fn (int $id): array => Query::$tables['contact_addresses'][$id] ?? [];
$seed = static fn (int $id, int $contactId, int $position, string $default, string $deleted = 'false'): array
    => ['id' => $id, 'contact_id' => $contactId, 'position' => $position, 'is_default' => $default, 'deleted' => $deleted, 'street' => 'Via '.$id];

check('primo indirizzo: predefinito in posizione 1; il secondo no, in posizione 2', function () use ($address, $row) {
    Query::reset();
    $first = AccountAddresses::save(5, $address);
    $second = AccountAddresses::save(5, ['street' => 'Via Po'] + $address);
    return $first->success && $second->success
        && $row($first->id)['is_default'] === 'true' && (int) $row($first->id)['position'] === 1
        && $row($second->id)['is_default'] === 'false' && (int) $row($second->id)['position'] === 2;
});

check('la posizione viene da un MAX, senza caricare gli indirizzi', function () use ($address, $seed, $row) {
    Query::reset(['contact_addresses' => [1 => $seed(1, 5, 4, 'true'), 2 => $seed(2, 5, 9, 'false')]]);
    $saved = AccountAddresses::save(5, $address);
    $unbounded = array_filter(Query::$selects, static fn (array $s): bool => $s['table'] === 'contact_addresses' && $s['limit'] === null);
    $max = array_filter(Query::$selects, static fn (array $s): bool => str_starts_with($s['columns'], 'MAX('));
    return $saved->success && (int) $row($saved->id)['position'] === 10 && $unbounded === [] && count($max) === 1
        && AccountAddresses::nextPosition(6) === 1;
});

check('un indirizzo eliminato non conta: il nuovo diventa predefinito', function () use ($address, $seed, $row) {
    Query::reset(['contact_addresses' => [1 => $seed(1, 5, 1, 'true', 'true')]]);
    $saved = AccountAddresses::save(5, $address);
    return $saved->success && $row($saved->id)['is_default'] === 'true' && (int) $row($saved->id)['position'] === 1;
});

check('scheda con indirizzi ma senza predefinito: il nuovo lo diventa', function () use ($address, $seed, $row) {
    Query::reset(['contact_addresses' => [1 => $seed(1, 5, 1, 'false'), 2 => $seed(2, 5, 2, 'false'), 3 => $seed(3, 6, 1, 'true')]]);
    $saved = AccountAddresses::save(5, $address);
    return $saved->success && $row($saved->id)['is_default'] === 'true' && (int) $row($saved->id)['position'] === 3
        && $row(1)['is_default'] === 'false' && $row(2)['is_default'] === 'false';
});

check('modifica: il predefinito non cambia', function () use ($address, $seed, $row) {
    Query::reset(['contact_addresses' => [1 => $seed(1, 5, 1, 'true'), 2 => $seed(2, 5, 2, 'false')]]);
    $saved = AccountAddresses::save(5, $address, 2);
    return $saved->success && $row(2)['is_default'] === 'false' && $row(1)['is_default'] === 'true' && $row(2)['street'] === 'Via Roma';
});

check('indirizzo di un\'altra scheda: save e delete falliscono senza scrivere', function () use ($address, $seed, $row) {
    Query::reset(['contact_addresses' => [1 => $seed(1, 5, 1, 'true')]]);
    $saved = AccountAddresses::save(6, $address, 1);
    $deleted = AccountAddresses::delete(6, 1);
    return !$saved->success && !$deleted && Query::$writes === [] && $row(1)['street'] === 'Via 1';
});

check('indirizzo incompleto: rifiutato dalla validazione, nessuna scrittura', function () use ($address) {
    Query::reset();
    $saved = AccountAddresses::save(5, ['number' => ''] + $address);
    return !$saved->success && $saved->messages !== [] && Query::$writes === [];
});

check('eliminato il predefinito: lo diventa il rimasto con la posizione più bassa', function () use ($seed, $row) {
    Query::reset(['contact_addresses' => [
        1 => $seed(1, 5, 1, 'true'), 2 => $seed(2, 5, 7, 'false'), 3 => $seed(3, 5, 3, 'false'),
        4 => $seed(4, 5, 2, 'false', 'true'), 5 => $seed(5, 6, 0, 'false'),
    ]]);
    return AccountAddresses::delete(5, 1) && $row(1) === []
        && $row(3)['is_default'] === 'true' && $row(2)['is_default'] === 'false'
        && $row(4)['is_default'] === 'false' && $row(5)['is_default'] === 'false';
});

check('eliminato un indirizzo non predefinito: il predefinito resta', function () use ($seed, $row) {
    Query::reset(['contact_addresses' => [1 => $seed(1, 5, 1, 'true'), 2 => $seed(2, 5, 2, 'false'), 3 => $seed(3, 5, 3, 'false')]]);
    return AccountAddresses::delete(5, 2) && $row(1)['is_default'] === 'true' && $row(3)['is_default'] === 'false';
});

summary();
