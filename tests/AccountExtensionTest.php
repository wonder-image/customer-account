<?php
/** php tests/AccountExtensionTest.php — route e hook del pannello, con una scheda cliente finta (nessun database). */
declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use Wonder\App\ResourceSchema\FormField;
use Wonder\Auth\Frontend\AccountPanel;
use Wonder\Auth\Frontend\AccountRoutes;
use Wonder\Http\Route;
use Wonder\Plugin\CustomerAccount\Account\CustomerAccountExtension;

if (!function_exists('__r')) {
    function __r(string $name, array $parameters = []): string { return Route::url($name, $parameters); }
}

final class SchedaFinta extends CustomerAccountExtension
{
    public function __construct(private array $scheda) {}
    protected function contact(object $user): array { return $this->scheda; }
}

final class SoloDatiPersonali extends AccountPanel
{
    public function sections(): array { return ['overview', 'personal']; }
}

$scheda = ['id' => 5, 'user_id' => 1, 'name' => 'Ada', 'surname' => 'Lovelace', 'birth_date' => '1990-04-02', 'phone' => '+393331234567', 'phone_prefix' => '+39'];
$user = (object) ['id' => 1, 'name' => 'Ada'];
$fresh = static function (AccountPanel $panel, CustomerAccountExtension $extension): void {
    Route::reset();
    AccountRoutes::reset();
    AccountRoutes::register($panel);
    AccountRoutes::extend($extension);
};
$named = static function (): array {
    $out = [];
    foreach (Route::all() as $route) {
        if (($route['name'] ?? '') !== '' && !str_ends_with($route['name'], '.')) {
            $out[$route['name']] = $route;
        }
    }
    return $out;
};

check('route degli indirizzi e della fatturazione, private, sull\'handler del modulo', function () use ($fresh, $named, $scheda) {
    $fresh(new AccountPanel(), new SchedaFinta($scheda));
    $routes = $named();
    $expected = [
        'account.addresses' => '/account/indirizzi/',
        'account.addresses.create' => '/account/indirizzi/nuovo/',
        'account.addresses.edit' => '/account/indirizzi/{id}/',
        'account.addresses.delete' => '/account/indirizzi/{id}/elimina/',
        'account.billing' => '/account/fatturazione/',
    ];
    foreach ($expected as $name => $path) {
        $route = $routes[$name] ?? [];
        if (($route['path'] ?? null) !== $path || !($route['private'] ?? false)
            || !str_ends_with((string) ($route['handler'] ?? $route['file'] ?? ''), 'http/frontend/account.php')
            || str_contains((string) ($route['handler'] ?? $route['file'] ?? ''), '/app/http/')) {
            throw new RuntimeException("{$name}: ".json_encode($route));
        }
    }
    return isset($routes['account.personal']);
});

check('sezioni spente: nessuna route del modulo', function () use ($fresh, $named, $scheda) {
    $fresh(new SoloDatiPersonali(), new SchedaFinta($scheda));
    return array_filter(array_keys($named()), static fn ($n) => str_starts_with($n, 'account.addresses') || $n === 'account.billing') === [];
});

check('menu: «Indirizzi» subito dopo «Dati personali»', function () use ($fresh, $scheda, $user) {
    $fresh(new AccountPanel(), $ext = new SchedaFinta($scheda));
    $items = $ext->navigation(['overview' => [], 'personal' => [], 'security' => []], $user);
    return array_keys($items) === ['overview', 'personal', 'addresses', 'security'] && $items['addresses']['href'] === '/account/indirizzi/';
});

check('menu senza «Dati personali»: «Indirizzi» in coda; con la sezione spenta nessuna voce', function () use ($fresh, $scheda, $user) {
    $fresh(new AccountPanel(), $ext = new SchedaFinta($scheda));
    $tail = array_keys($ext->navigation(['overview' => []], $user)) === ['overview', 'addresses'];
    $fresh(new SoloDatiPersonali(), $ext = new SchedaFinta($scheda));
    return $tail && array_keys($ext->navigation(['overview' => [], 'personal' => []], $user)) === ['overview', 'personal'];
});

check('dati personali: data di nascita seconda colonna, fatturazione dopo la password', function () use ($fresh, $scheda, $user) {
    $fresh(new AccountPanel(), $ext = new SchedaFinta($scheda));
    $rows = $ext->personalRows([
        ['key' => 'personal', 'columns' => [['label' => 'nome', 'value' => 'Ada'], ['label' => 'telefono', 'value' => '—']]],
        ['key' => 'email', 'columns' => []],
        ['key' => 'password', 'columns' => []],
    ], $user);
    $keys = array_map(static fn ($r) => $r['key'] ?? null, $rows);
    return $rows[0]['columns'][1]['label'] === 'account.personal.birth_date' && $rows[0]['columns'][1]['value'] === '02/04/1990'
        && array_slice($keys, 0, 3) === ['personal', 'email', 'password'] && count($rows) > 3;
});

check('senza scheda o con la fatturazione spenta: nessuna riga di fatturazione', function () use ($fresh, $scheda, $user) {
    $base = [['key' => 'personal', 'columns' => []], ['key' => 'password', 'columns' => []]];
    $fresh(new AccountPanel(), $ext = new SchedaFinta([]));
    $none = count($ext->personalRows($base, $user)) === 2;
    $fresh(new SoloDatiPersonali(), $ext = new SchedaFinta($scheda));
    return $none && count($ext->personalRows($base, $user)) === 2;
});

check('form dei dati personali: data di nascita dopo il cognome, telefono dalla scheda', function () use ($fresh, $scheda, $user) {
    $fresh(new AccountPanel(), $ext = new SchedaFinta($scheda));
    $fields = $ext->personalFormFields([
        'name' => FormField::key('name')->text(),
        'surname' => FormField::key('surname')->text(),
        'phone_prefix' => FormField::key('phone_prefix')->text(),
        'phone' => FormField::key('phone')->text(),
    ], $user, []);
    $order = array_keys($fields) === ['name', 'surname', 'birth_date', 'phone_prefix', 'phone'];
    $html = $fields['phone']->render('bootstrap').$fields['birth_date']->render('bootstrap');
    return $order && str_contains($html, '3331234567') && !str_contains($html, '393331234567') && str_contains($html, '1990-04-02');
});

check('form riaperto con i valori postati: il telefono resta quello postato', function () use ($fresh, $scheda, $user) {
    $fresh(new AccountPanel(), $ext = new SchedaFinta($scheda));
    $fields = $ext->personalFormFields(['surname' => FormField::key('surname')->text(), 'phone' => FormField::key('phone')->text()->value('0000')], $user, ['phone' => '0000', 'birth_date' => '2000-01-01']);
    $html = $fields['phone']->render('bootstrap').$fields['birth_date']->render('bootstrap');
    return str_contains($html, '0000') && !str_contains($html, '3331234567') && str_contains($html, '2000-01-01');
});

check('data di nascita: vuota o passata va bene, futura o malformata no', function () use ($scheda, $user) {
    $ext = new SchedaFinta($scheda);
    $tomorrow = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
    return $ext->validatePersonal([], $user) === []
        && $ext->validatePersonal(['birth_date' => '1990-04-02'], $user) === []
        && $ext->validatePersonal(['birth_date' => $tomorrow], $user) === ['account.personal.errors.birth_date']
        && $ext->validatePersonal(['birth_date' => '1990-02-30'], $user) === ['account.personal.errors.birth_date']
        && $ext->validatePersonal(['birth_date' => '02/04/1990'], $user) === ['account.personal.errors.birth_date'];
});

check('form diversi da billing, fatturazione spenta o scheda assente: null (il core risponde 404)', function () use ($fresh, $scheda, $user) {
    $fresh(new AccountPanel(), $ext = new SchedaFinta($scheda));
    $other = $ext->savePersonalForm('prova', $user, []) === null;
    $noContact = (new SchedaFinta([]))->savePersonalForm('billing', $user, []) === null;
    $fresh(new SoloDatiPersonali(), $ext = new SchedaFinta($scheda));
    return $other && $noContact && $ext->savePersonalForm('billing', $user, []) === null;
});

check('modal della fatturazione in coda; nessuno senza scheda', function () use ($fresh, $scheda, $user) {
    $fresh(new AccountPanel(), $ext = new SchedaFinta($scheda));
    $modals = $ext->personalModals(['core'], $user, '', [], []);
    $none = (new SchedaFinta([]))->personalModals(['core'], $user, '', [], []);
    return count($modals) === 2 && $modals[0] === 'core' && $none === ['core'];
});

check('errori di pagina: scheda inutilizzabile aggiunta a quelli esistenti', function () use ($scheda, $user) {
    return (new SchedaFinta([]))->pageErrors(['x'], $user, 'personal') === ['x', 'account.errors.contact']
        && (new SchedaFinta($scheda))->pageErrors(['x'], $user, 'personal') === ['x'];
});

check('overview senza scheda: righe invariate', function () use ($fresh, $user) {
    $fresh(new AccountPanel(), $ext = new SchedaFinta([]));
    $rows = [['key' => 'personal'], ['key' => 'security']];
    return $ext->overviewRows($rows, $user) === $rows;
});

check('estensione registrata una volta sola per classe', function () use ($fresh, $named, $scheda) {
    $fresh(new AccountPanel(), new SchedaFinta($scheda));
    $count = count(Route::all());
    AccountRoutes::extend(new SchedaFinta($scheda));
    return count(Route::all()) === $count;
});

summary();
