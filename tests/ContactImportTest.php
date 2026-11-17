<?php
/** php tests/ContactImportTest.php — tracciati di importazione di persone e aziende (database finto). */
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
use Wonder\App\Import\Mapping;
use Wonder\Plugin\CustomerAccount\Support\Import\CompanyLayout;
use Wonder\Plugin\CustomerAccount\Support\Import\ImportExtension;
use Wonder\Plugin\CustomerAccount\Support\Import\ImportExtensions;
use Wonder\Plugin\CustomerAccount\Support\Import\PeopleLayout;
use Wonder\Sql\Query;

$seed = static fn (): array => [
    'contacts' => [
        2 => ['id' => 2, 'code' => 'con_anna', 'name' => 'Anna', 'surname' => 'Bianchi', 'cf' => 'BNCNNA80A41F205X', 'email' => 'anna@rossi.test', 'phone_prefix' => '+39', 'phone' => '3331112222', 'country' => 'IT', 'active' => 'true', 'user_id' => 0, 'deleted' => 'false'],
        3 => ['id' => 3, 'code' => 'con_luca', 'name' => 'Luca', 'surname' => 'Verdi', 'email' => 'luca@verdi.test', 'user_id' => 9, 'active' => 'true', 'deleted' => 'false'],
        4 => ['id' => 4, 'code' => 'con_old', 'name' => 'Ex', 'surname' => 'Cliente', 'email' => 'ex@test.it', 'deleted' => 'true'],
    ],
    'companies' => [
        5 => ['id' => 5, 'code' => 'com_rossi', 'business_name' => 'Rossi &amp; figli srl', 'pi' => '01234567890', 'country' => 'IT', 'is_customer' => 'true', 'is_supplier' => 'false', 'active' => 'true', 'deleted' => 'false'],
    ],
    'contact_points' => [],
    'company_points' => [],
    'contact_relations' => [],
    'external_references' => [],
];

/** Righe `{line, data}` dal formato del file. */
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

check('le colonne ripercorrono l\'export: ID, codice, campi, prefisso dopo il telefono, stato', function () {
    $keys = array_map(static fn (Column $c): string => $c->key, (new PeopleLayout([]))->columns());
    return array_slice($keys, 0, 4) === ['id', 'code', 'name', 'surname']
        && array_search('phone_prefix', $keys, true) === array_search('phone', $keys, true) + 1
        && end($keys) === 'active';
});

check('aziende: cliente e fornitore come flag', function () {
    $keys = array_map(static fn (Column $c): string => $c->key, (new CompanyLayout([]))->columns());
    return in_array('is_customer', $keys, true) && in_array('is_supplier', $keys, true) && !in_array('parent_id', $keys, true);
});

check('le intestazioni di un file italiano si abbinano da sole', function () {
    $mapping = Mapping::guess(['Codice fiscale', 'Nome', 'Cognome', 'E-mail', 'Cellulare', 'Prov'], (new PeopleLayout([]))->columns());
    return ($mapping['Codice fiscale'] ?? $mapping[0] ?? null) !== null;
});

check('crea una persona nuova con telefono e provincia normalizzati', function () use ($seed, $run) {
    Query::reset($seed());
    [$results, $applied] = $run(new PeopleLayout([]), [
        ['name' => 'Marco', 'surname' => 'Neri', 'email' => 'Marco@Neri.TEST', 'phone' => '+39 347 123 4567', 'province' => 'Milano', 'country' => 'Italia', 'active' => 'sì'],
    ]);
    $new = end(Query::$tables['contacts']);
    return $results[0]['action'] === 'create' && $results[0]['errors'] === []
        && $new['email'] === 'marco@neri.test' && $new['phone'] === '3471234567' && $new['phone_prefix'] === '+39'
        && $new['province'] === 'MI' && $new['country'] === 'IT' && $new['active'] === 'true'
        && str_starts_with((string) $new['code'], 'con_')
        && $applied[0]['changes'][0]->action === 'created';
});

check('riconosce per CF e cambia solo le celle piene', function () use ($seed, $run) {
    Query::reset($seed());
    [$results] = $run(new PeopleLayout([]), [['cf' => 'bncnna80a41f205x', 'surname' => 'Bianchi Rossi', 'email' => '']]);
    $row = Query::$tables['contacts'][2];
    return $results[0]['action'] === 'update' && $row['surname'] === 'Bianchi Rossi' && $row['email'] === 'anna@rossi.test' && $row['name'] === 'Anna';
});

check('riga uguale alla scheda: nessuna modifica', function () use ($seed, $run) {
    Query::reset($seed());
    [$results] = $run(new PeopleLayout([]), [['id' => '2', 'name' => 'Anna', 'phone' => '333 111 2222']]);
    return $results[0]['action'] === 'unchanged' && $results[0]['errors'] === [];
});

check('un dato di un\'altra scheda ferma la riga', function () use ($seed, $run) {
    Query::reset($seed());
    [$results] = $run(new PeopleLayout([]), [['id' => '2', 'email' => 'luca@verdi.test']]);
    return $results[0]['errors'] !== [] && str_contains($results[0]['errors'][0], 'used_by');
});

check('ID sconosciuto o di una scheda cancellata: errore', function () use ($seed, $run) {
    Query::reset($seed());
    [$results] = $run(new PeopleLayout([]), [['id' => '99', 'name' => 'X'], ['id' => '4', 'name' => 'Y']]);
    return str_contains($results[0]['errors'][0], 'not_found') && str_contains($results[1]['errors'][0], 'not_found');
});

check('la stessa scheda due volte nel file: la seconda è un errore', function () use ($seed, $run) {
    Query::reset($seed());
    [$results] = $run(new PeopleLayout([]), [
        ['email' => 'nuovo@test.it', 'name' => 'A', 'surname' => 'B'],
        ['email' => 'NUOVO@test.it', 'name' => 'C', 'surname' => 'D'],
    ]);
    return $results[0]['errors'] === [] && str_contains($results[1]['errors'][0], 'duplicate');
});

check('scheda nuova senza nome: obbligatorio', function () use ($seed, $run) {
    Query::reset($seed());
    [$results] = $run(new PeopleLayout([]), [['email' => 'senza@nome.it']]);
    return count(array_filter($results[0]['errors'], static fn ($e) => str_contains($e, 'required'))) >= 1 && count(Query::$tables['contacts']) === 3;
});

check('flag, email e telefono non validi', function () use ($seed, $run) {
    Query::reset($seed());
    [$results] = $run(new PeopleLayout([]), [['id' => '2', 'active' => 'forse', 'email' => 'no-email', 'phone' => 'abc']]);
    $joined = implode('|', $results[0]['errors']);
    return str_contains($joined, 'invalid_flag') && str_contains($joined, 'invalid_email') && str_contains($joined, 'invalid_value');
});

check('l\'email di chi ha un account non cambia: avviso', function () use ($seed, $run) {
    Query::reset($seed());
    [$results] = $run(new PeopleLayout([]), [['id' => '3', 'email' => 'altro@verdi.test', 'name' => 'Luchino']]);
    $row = Query::$tables['contacts'][3];
    return $results[0]['warnings'] !== [] && $row['email'] === 'luca@verdi.test' && $row['name'] === 'Luchino';
});

check('annullo: la scheda creata va nel cestino, quella aggiornata torna com\'era', function () use ($seed, $run) {
    Query::reset($seed());
    $layout = new PeopleLayout([]);
    [, $applied, $context] = $run($layout, [
        ['name' => 'Nuova', 'surname' => 'Persona'],
        ['id' => '2', 'name' => 'Annalisa', 'phone' => '+39 333 0000000'],
    ]);
    foreach ($applied as $result) {
        foreach ($result['changes'] as $change) {
            $layout->undo($change, $context);
        }
    }
    $created = $applied[0]['changes'][0]->recordId;
    $anna = Query::$tables['contacts'][2];
    return Query::$tables['contacts'][$created]['deleted'] === 'true' && $anna['name'] === 'Anna' && $anna['phone'] === '3331112222';
});

check('aziende: riconosce per P.IVA, confronta i testi decodificati, paese IT di default', function () use ($seed, $run) {
    Query::reset($seed());
    [$results] = $run(new CompanyLayout([]), [
        ['pi' => '012 345 678 90', 'business_name' => 'Rossi & figli srl', 'is_supplier' => 'x'],
        ['business_name' => 'Bianchi spa', 'is_customer' => 'no'],
    ]);
    $new = end(Query::$tables['companies']);
    return $results[0]['action'] === 'update' && Query::$tables['companies'][5]['is_supplier'] === 'true'
        && $results[1]['action'] === 'create' && $new['country'] === 'IT' && $new['is_customer'] === 'false';
});

check('una scrittura fallita diventa un errore della riga', function () use ($seed, $rows) {
    Query::reset($seed());
    $layout = new PeopleLayout([]);
    $context = new Context(1, 1);
    $result = $layout->validate($rows([['name' => 'A', 'surname' => 'B']]), $context)[0];
    Query::$failWrites = true;
    try {
        $layout->apply($result['data'], $context);
        return false;
    } catch (RuntimeException $e) {
        return $layout->message($e) !== null || $e->getMessage() !== '';
    } finally {
        Query::$failWrites = false;
    }
});

final class TestRegionExtension extends ImportExtension
{
    public function permits(string $layout, object $user): ?bool { return ($user->blocked ?? false) ? false : null; }
    public function columns(string $layout): array { return [Column::make('region', 'Zona')]; }
    public function visible(string $layout, array $row): bool { return (int) $row['id'] !== 3; }
    public function values(string $layout, array $data, ?array $current, Context $context): array
    {
        $zone = trim($data['region'] ?? '');
        return $zone === 'Marte' ? ['errors' => ['Zona sconosciuta']] : ($zone !== '' ? ['values' => ['note' => 'Zona '.$zone]] : []);
    }
}

check('estensioni: colonne, valori, errori, schede nascoste e permessi', function () use ($seed, $run) {
    Query::reset($seed());
    $layout = new PeopleLayout([new TestRegionExtension()]);
    $keys = array_map(static fn (Column $c): string => $c->key, $layout->columns());
    [$results] = $run($layout, [
        ['id' => '2', 'region' => 'Nord'],
        ['id' => '2', 'region' => 'Marte'],
        ['email' => 'luca@verdi.test', 'name' => 'L', 'surname' => 'V'],
    ]);
    return end($keys) === 'region'
        && Query::$tables['contacts'][2]['note'] === 'Zona Nord'
        && in_array('Zona sconosciuta', $results[1]['errors'], true)
        && str_contains($results[2]['errors'][0] ?? '', 'used_hidden')
        && ImportExtensions::permits([new TestRegionExtension()], PeopleLayout::KEY, (object) ['blocked' => true], true) === false;
});

check('estensioni dalla config: classi sbagliate e doppioni saltati', function () {
    $all = ImportExtensions::all([
        'a' => ['contacts_import' => ['extensions' => [TestRegionExtension::class, stdClass::class]]],
        'b' => ['contacts_import' => ['extensions' => [TestRegionExtension::class]]],
    ]);
    return count($all) === 1 && $all[0] instanceof TestRegionExtension;
});

summary();
