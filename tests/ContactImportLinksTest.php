<?php
/** php tests/ContactImportLinksTest.php — tracciati di importazione di indirizzi e referenti (database finto). */
declare(strict_types=1);

require __DIR__.'/support/FakeDb.php';
require __DIR__.'/bootstrap.php';
require_once WI_APP_ROOT.'/app/function/other/geo.php';

if (!function_exists('sqlSelect')) {
    function sqlSelect($table, $condition = null, $limit = null, $order = null, $orderDirection = null, $attributes = '*') { return (new \Wonder\Sql\Query)->Select($table, $condition, $limit, $order, $orderDirection, $attributes); }
    function sqlInsert($table, $values) { return (new \Wonder\Sql\Query)->Insert($table, $values); }
    function sqlModify($table, $values, $column, $value) { return (new \Wonder\Sql\Query)->Update($table, $values, $column, $value); }
    function sqlDelete($table, $condition = null) { return (new \Wonder\Sql\Query)->Delete($table, $condition); }
}

use Wonder\App\Dataset\Column;
use Wonder\App\Import\Context;
use Wonder\Plugin\CustomerAccount\Support\Import\AddressLayout;
use Wonder\Plugin\CustomerAccount\Support\Import\ImportExtension;
use Wonder\Plugin\CustomerAccount\Support\Import\RelationLayout;
use Wonder\Sql\Query;

$seed = static fn (): array => [
    'contacts' => [
        2 => ['id' => 2, 'code' => 'con_anna', 'name' => 'Anna', 'surname' => 'Bianchi', 'cf' => 'BNCNNA80A41F205X', 'email' => 'anna@rossi.test', 'user_id' => 0, 'active' => 'true', 'deleted' => 'false'],
        3 => ['id' => 3, 'code' => 'con_luca', 'name' => 'Luca', 'surname' => 'Verdi', 'email' => 'luca@verdi.test', 'user_id' => 0, 'active' => 'true', 'deleted' => 'false'],
    ],
    'companies' => [
        5 => ['id' => 5, 'code' => 'com_rossi', 'business_name' => 'Rossi srl', 'pi' => '01234567890', 'country' => 'IT', 'active' => 'true', 'deleted' => 'false'],
    ],
    'contact_addresses' => [
        10 => ['id' => 10, 'contact_id' => 2, 'label' => 'Casa', 'name' => 'Anna', 'surname' => 'Bianchi', 'street' => 'Via Roma', 'number' => '1', 'cap' => '20100', 'city' => 'Milano', 'province' => 'MI', 'country' => 'IT', 'is_default' => 'true', 'position' => 1, 'place_id' => 'abc', 'lat' => '45.1', 'lng' => '9.1', 'deleted' => 'false'],
    ],
    'company_addresses' => [],
    'contact_points' => [],
    'company_points' => [],
    'contact_relations' => [],
    'contact_relation_departments' => [],
    'contact_relation_brands' => [],
    'company_brand_links' => [],
    'external_references' => [],
];

$rows = static function (array $data): array {
    $out = [];
    foreach ($data as $i => $row) {
        $out[] = ['line' => $i + 2, 'data' => array_map('trim', $row)];
    }
    return $out;
};
$run = static function (object $layout, array $data, ?Context $context = null) use ($rows): array {
    $context ??= new Context(1, 1);
    $results = $layout->validate($rows($data), $context);
    $applied = [];
    foreach ($results as $i => $result) {
        if ($result['errors'] === [] && $result['action'] !== 'unchanged') {
            $applied[$i] = $layout->apply($result['data'], $context);
        }
    }
    return [$results, $applied, $context];
};
$undo = static function (object $layout, array $applied, Context $context): void {
    foreach (array_reverse($applied) as $result) {
        foreach (array_reverse($result['changes']) as $change) {
            $layout->undo($change, $context);
        }
    }
};
$address = ['street' => 'Via Verdi', 'number' => '7', 'cap' => '20121', 'city' => 'Milano', 'province' => 'Milano', 'name' => 'Anna', 'surname' => 'Bianchi'];

/* ------------------------------------------------------------------ indirizzi */

check('indirizzi: le colonne partono dalla scheda e finiscono con il predefinito', function () {
    $keys = array_map(static fn (Column $c): string => $c->key, (new AddressLayout([]))->columns());
    return array_slice($keys, 0, 7) === ['card_type', 'card_id', 'card_code', 'pi', 'cf', 'card_email', 'address_id']
        && end($keys) === 'is_default' && in_array('street', $keys, true);
});

check('indirizzi: nuovo predefinito per una persona; l\'altro perde il predefinito; l\'annullo rimette tutto', function () use ($seed, $run, $undo, $address) {
    Query::reset($seed());
    $layout = new AddressLayout([]);
    [$results, $applied, $context] = $run($layout, [['card_email' => 'ANNA@rossi.test', 'is_default' => 'sì'] + $address]);
    $new = end(Query::$tables['contact_addresses']);
    $ok = $results[0]['action'] === 'create' && $results[0]['errors'] === []
        && (int) $new['contact_id'] === 2 && $new['province'] === 'MI' && $new['country'] === 'IT' && $new['is_default'] === 'true' && (int) $new['position'] === 2
        && Query::$tables['contact_addresses'][10]['is_default'] === 'false'
        && count($applied[0]['changes']) === 2;
    $undo($layout, $applied, $context);
    return $ok && Query::$tables['contact_addresses'][$new['id']]['deleted'] === 'true'
        && Query::$tables['contact_addresses'][10]['is_default'] === 'true';
});

check('indirizzi: senza ID si aggiorna quello uguale; spostarlo toglie il punto sulla mappa', function () use ($seed, $run) {
    Query::reset($seed());
    [$results] = $run(new AddressLayout([]), [['card_id' => '2', 'card_type' => 'Persona', 'street' => 'Via Roma', 'number' => '1', 'cap' => '20100', 'city' => 'Milano', 'label' => 'Casa mia']]);
    $kept = Query::$tables['contact_addresses'][10]['place_id'] === 'abc';
    [$moved] = $run(new AddressLayout([]), [['card_id' => '2', 'card_type' => 'persona', 'address_id' => '10', 'cap' => '20121']]);
    $row = Query::$tables['contact_addresses'][10];
    return $results[0]['action'] === 'update' && $results[0]['errors'] === [] && $kept
        && $moved[0]['action'] === 'update' && count(Query::$tables['contact_addresses']) === 1
        && $row['label'] === 'Casa mia' && $row['cap'] === '20121' && $row['place_id'] === null && $row['lat'] === null;
});

check('indirizzi: lo stesso indirizzo due volte nel file è un errore', function () use ($seed, $run, $address) {
    Query::reset($seed());
    [$results] = $run(new AddressLayout([]), [['card_id' => '2', 'card_type' => 'persona'] + $address, ['cf' => 'bncnna80a41f205x'] + $address]);
    return $results[0]['errors'] === [] && str_contains($results[1]['errors'][0] ?? '', 'duplicate_address');
});

check('indirizzi: scheda mancante, tipo sbagliato, ID di un\'altra scheda, campi obbligatori', function () use ($seed, $run, $address) {
    Query::reset($seed());
    [$results] = $run(new AddressLayout([]), [
        $address,
        ['card_type' => 'Fornitore', 'card_id' => '2'] + $address,
        ['card_type' => 'azienda', 'card_id' => '5', 'address_id' => '10'] + $address,
        ['card_id' => '99', 'card_type' => 'persona'] + $address,
        ['card_id' => '2', 'card_type' => 'persona', 'street' => 'Via Nuova'],
    ]);
    return str_contains($results[0]['errors'][0] ?? '', 'card_missing')
        && str_contains($results[1]['errors'][0] ?? '', 'card_type')
        && str_contains($results[2]['errors'][0] ?? '', 'address_not_found')
        && str_contains($results[3]['errors'][0] ?? '', 'not_found')
        && $results[4]['errors'] !== []
        && count(Query::$tables['contact_addresses']) === 1;
});

check('indirizzi: un\'azienda si riconosce dalla P.IVA', function () use ($seed, $run, $address) {
    Query::reset($seed());
    [$results] = $run(new AddressLayout([]), [['pi' => '012 345 678 90', 'business_name' => 'Magazzino'] + $address]);
    $new = end(Query::$tables['company_addresses']);
    return $results[0]['action'] === 'create' && $results[0]['errors'] === [] && (int) $new['company_id'] === 5 && $new['business_name'] === 'Magazzino';
});

final class HideLucaExtension extends ImportExtension
{
    public function visible(string $layout, array $row): bool { return (int) $row['id'] !== 3; }
}

check('indirizzi e referenti: una scheda nascosta da un\'estensione non si trova', function () use ($seed, $run, $address) {
    Query::reset($seed());
    [$addresses] = $run(new AddressLayout([new HideLucaExtension()]), [['card_email' => 'luca@verdi.test'] + $address]);
    [$relations] = $run(new RelationLayout([new HideLucaExtension()]), [['company_id' => '5', 'person_email' => 'luca@verdi.test']]);
    return $addresses[0]['errors'] !== [] && $relations[0]['errors'] !== []
        && Query::$tables['company_addresses'] === [] && Query::$tables['contact_relations'] === [];
});

/* ------------------------------------------------------------------ referenti */

check('referenti: legame nuovo senza accesso, non principale, con i documenti', function () use ($seed, $run) {
    Query::reset($seed());
    [$results, $applied] = $run(new RelationLayout([]), [['company_pi' => '01234567890', 'person_email' => 'anna@rossi.test', 'job_title' => 'Acquisti', 'email' => 'Anna@Rossi.TEST']]);
    $row = end(Query::$tables['contact_relations']);
    return $results[0]['action'] === 'create' && $results[0]['errors'] === []
        && (int) $row['company_id'] === 5 && (int) $row['person_id'] === 2 && $row['job_title'] === 'Acquisti'
        && $row['access'] === 'none' && $row['is_primary'] === 'false' && $row['receives_documents'] === 'true'
        && $row['email'] === 'anna@rossi.test'
        && $applied[0]['changes'][0]->action === 'created';
});

check('referenti: la cella vuota non svuota la qualifica; accesso dal codice', function () use ($seed, $run) {
    $tables = $seed();
    $tables['contact_relations'] = [1 => ['id' => 1, 'company_id' => 5, 'person_id' => 2, 'job_title' => 'Acquisti', 'is_primary' => 'true', 'receives_documents' => 'true', 'is_legal_representative' => 'false', 'access' => 'none', 'email' => '', 'phone_prefix' => '', 'phone' => '', 'position' => 1, 'deleted' => 'false']];
    Query::reset($tables);
    [$results] = $run(new RelationLayout([]), [['company_id' => '5', 'person_id' => '2', 'job_title' => '', 'access' => 'Buyer']]);
    $row = Query::$tables['contact_relations'][1];
    return $results[0]['action'] === 'update' && $row['job_title'] === 'Acquisti' && $row['access'] === 'buyer';
});

check('referenti: un legame cancellato si riapre e l\'annullo lo richiude', function () use ($seed, $run, $undo) {
    $tables = $seed();
    $tables['contact_relations'] = [1 => ['id' => 1, 'company_id' => 5, 'person_id' => 2, 'job_title' => 'Vecchio', 'is_primary' => 'false', 'receives_documents' => 'true', 'is_legal_representative' => 'false', 'access' => 'none', 'email' => '', 'phone_prefix' => '', 'phone' => '', 'position' => 1, 'deleted' => 'true']];
    Query::reset($tables);
    $layout = new RelationLayout([]);
    [$results, $applied, $context] = $run($layout, [['company_code' => 'com_rossi', 'person_code' => 'con_anna', 'job_title' => 'Nuovo']]);
    $revived = Query::$tables['contact_relations'][1];
    $ok = $results[0]['action'] === 'update' && count(Query::$tables['contact_relations']) === 1
        && $revived['deleted'] === 'false' && $revived['job_title'] === 'Nuovo';
    $undo($layout, $applied, $context);
    $back = Query::$tables['contact_relations'][1];
    return $ok && $back['deleted'] === 'true' && $back['job_title'] === 'Vecchio';
});

check('referenti: nuovo principale, il vecchio decade e l\'annullo lo rimette', function () use ($seed, $run, $undo) {
    $tables = $seed();
    $tables['contact_relations'] = [1 => ['id' => 1, 'company_id' => 5, 'person_id' => 3, 'job_title' => '', 'is_primary' => 'true', 'receives_documents' => 'true', 'is_legal_representative' => 'false', 'access' => 'none', 'email' => '', 'phone_prefix' => '', 'phone' => '', 'position' => 1, 'deleted' => 'false']];
    Query::reset($tables);
    $layout = new RelationLayout([]);
    [, $applied, $context] = $run($layout, [['company_id' => '5', 'person_id' => '2', 'is_primary' => 'sì']]);
    $ok = Query::$tables['contact_relations'][1]['is_primary'] === 'false' && count($applied[0]['changes']) === 2;
    $undo($layout, $applied, $context);
    return $ok && Query::$tables['contact_relations'][1]['is_primary'] === 'true';
});

check('referenti: schede mancanti, coppia doppia, valori non validi', function () use ($seed, $run) {
    Query::reset($seed());
    [$results] = $run(new RelationLayout([]), [
        ['person_id' => '2'],
        ['company_id' => '5', 'person_id' => '99'],
        ['company_id' => '5', 'person_id' => '2', 'access' => 'tutto', 'is_primary' => 'forse', 'email' => 'non-email'],
        ['company_pi' => '01234567890', 'person_cf' => 'BNCNNA80A41F205X'],
    ]);
    $joined = implode('|', $results[2]['errors']);
    return str_contains($results[0]['errors'][0] ?? '', 'company_missing')
        && $results[1]['errors'] !== []
        && str_contains($joined, 'invalid_value') && str_contains($joined, 'invalid_flag') && str_contains($joined, 'invalid_email')
        && str_contains($results[3]['errors'][0] ?? '', 'duplicate_relation')
        && Query::$tables['contact_relations'] === [];
});

summary();
