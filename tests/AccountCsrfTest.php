<?php
/** php tests/AccountCsrfTest.php — indirizzi del pannello con il token della pagina scaduto (database finto). */
declare(strict_types=1);

// Ogni scenario gira in un processo figlio: invalidCsrf() del core chiude con exit().
if (($argv[1] ?? '') !== '--scenario') {
    require __DIR__.'/bootstrap.php';

    $run = static function (string $scenario, array $post = []): array {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --scenario '.escapeshellarg($scenario).' '.escapeshellarg((string) json_encode($post)).' 2>&1';
        $out = (string) shell_exec($cmd);
        $pos = strrpos($out, "\n@@");
        $json = $pos === false ? null : json_decode(substr($out, $pos + 3), true);
        return is_array($json) ? $json : ['raw' => $out];
    };
    $address = ['name' => 'Ada', 'surname' => 'Lovelace', 'country' => 'IT', 'street' => 'Via Po', 'number' => '7', 'city' => 'Torino', 'cap' => '10100', 'province' => 'TO'];
    $secret = ['password' => 'segreta-1', 'password_confirmation' => 'segreta-1', 'csrf_token' => 'vecchio-1', 'logout_token' => 'vecchio-2'];
    // Nessun segreto, nessun token scaduto e nessuna parola tecnica nell'HTML reso.
    $clean = static fn (array $r): bool => preg_match('/segreta-1|vecchio-1|vecchio-2|scaduto/', (string) ($r['html'] ?? '')) !== 1
        && preg_match('/csrf|token/i', strip_tags((string) ($r['html'] ?? ''))) !== 1
        && preg_match('/csrf|token/i', (string) json_encode([$r['errors'] ?? null, $r['flash'] ?? null])) !== 1;

    $new = $run('create', $address + $secret);
    check('nuovo indirizzo con token scaduto → nessuna scrittura, uscita dopo il render, niente 419', fn () =>
        ($new['writes'] ?? null) === [] && ($new['after'] ?? null) === false && ($new['status'] ?? null) !== 419 && ($new['redirect'] ?? null) === null
    );
    check('nuovo indirizzo → modal «nuovo» riaperto con l\'errore 929, nessun toast', fn () =>
        ($new['open'] ?? null) === 'account-address-new' && ($new['errors'] ?? null) === ['notifications.929.text'] && ($new['alert'] ?? null) === null
        && str_contains((string) ($new['html'] ?? ''), 'notifications.929.text')
    );
    check('nuovo indirizzo → campi ripopolati, password e token con la chiave ma vuoti', function () use ($new, $secret) {
        $values = (array) ($new['values'] ?? []);
        foreach (array_keys($secret + ['_csrf' => '']) as $key) {
            if (!array_key_exists($key, $values) || $values[$key] !== '') {
                return false;
            }
        }
        $html = (string) ($new['html'] ?? '');
        return ($values['street'] ?? null) === 'Via Po' && str_contains($html, 'name="street" value="Via Po"') && str_contains($html, 'name="city" value="Torino"');
    });
    check('nuovo indirizzo → nessun segreto né token scaduto nell\'HTML, nessun «CSRF» né «token» negli avvisi', fn () => $clean($new));

    $edit = $run('edit', ['street' => 'Via Nuova'] + $address + $secret);
    check('modifica con token scaduto → modal di quell\'indirizzo con i valori scritti, non quelli salvati', fn () =>
        ($edit['writes'] ?? null) === [] && ($edit['after'] ?? null) === false
        && ($edit['open'] ?? null) === 'account-address-1' && ($edit['errors'] ?? null) === ['notifications.929.text']
        && str_contains((string) ($edit['html'] ?? ''), 'name="street" value="Via Nuova"') && $clean($edit)
    );
    check('modifica di un indirizzo non suo → 404 prima del token, come prima', function () use ($run, $address) {
        $r = $run('edit.other', $address);
        return ($r['status'] ?? null) === 404 && ($r['open'] ?? null) === null && ($r['writes'] ?? null) === [];
    });
    check('nuovo indirizzo senza scheda → elenco senza modal, toast 929', function () use ($run, $address) {
        $r = $run('create.nocontact', $address);
        return ($r['open'] ?? null) === '' && ($r['alert'] ?? null) === 929 && ($r['writes'] ?? null) === [] && ($r['after'] ?? null) === false;
    });

    $delete = $run('delete');
    check('eliminazione con token scaduto → indirizzo intatto, redirect all\'elenco con il flash 929', fn () =>
        ($delete['writes'] ?? null) === [] && ($delete['row'] ?? null) === true && ($delete['after'] ?? null) === false
        && ($delete['redirect'] ?? '') !== '' && ($delete['redirect'] ?? null) === ($delete['addresses_url'] ?? false)
        && ($delete['flash']['message'] ?? null) === 'notifications.929.text' && ($delete['flash']['level'] ?? null) === 'error'
        && $clean($delete)
    );

    $billing = $run('personal', ['form' => 'billing', 'name' => 'Ada'] + $secret);
    check('fatturazione (azione del core) → il core riceve open=billing, il 929 e i valori senza password, più il toast; nessun salvataggio', fn () =>
        ($billing['personal']['open'] ?? null) === 'billing' && ($billing['personal']['errors'] ?? null) === ['notifications.929.text']
        && ($billing['personal']['values']['name'] ?? null) === 'Ada' && ($billing['personal']['values']['password'] ?? null) === ''
        && ($billing['alert'] ?? null) === 929 && ($billing['writes'] ?? null) === [] && ($billing['after'] ?? null) === false
    );

    $retry = $run('create.valid', $address);
    check('secondo invio con il token della pagina ricaricata → indirizzo salvato e redirect all\'elenco', fn () =>
        ($retry['writes'] ?? []) !== [] && ($retry['redirect'] ?? '') !== '' && ($retry['redirect'] ?? null) === ($retry['addresses_url'] ?? false)
        && ($retry['open'] ?? null) === null
    );

    summary();
}

// --- Processo figlio ---------------------------------------------------------

require __DIR__.'/support/FakeDb.php';
require __DIR__.'/bootstrap.php';
require_once WI_APP_ROOT.'/app/function/other/geo.php';

use Wonder\Auth\Frontend\AccountPanel;
use Wonder\Auth\Frontend\AccountRoutes;
use Wonder\Auth\Frontend\AuthProfile;
use Wonder\Frontend\Support\FlashMessage;
use Wonder\Http\Route;
use Wonder\Plugin\CustomerAccount\Account\CustomerAccountController;
use Wonder\Plugin\CustomerAccount\Account\CustomerAccountExtension;
use Wonder\Sql\Query;

if (!function_exists('__r')) {
    function __r(string $name, array $parameters = []): string { return Route::url($name, $parameters); }
}
if (!function_exists('e')) {
    function e(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
}

/** Il controller del modulo con la scheda 5 dell'utente 7; la pagina finale rende solo i modal. */
final class ControllerDiProva extends CustomerAccountController
{
    public function __construct(private int $contactId)
    {
        parent::__construct(new AccountPanel(), new AuthProfile());
    }
    protected function user(): object { return (object) ['id' => 7, 'name' => 'Ada', 'email' => 'ada@example.com']; }
    protected function contact(int $userId): array { return $this->contactId > 0 ? ['id' => $this->contactId, 'user_id' => $userId] : []; }
    protected function contactErrors(array $contact): array { return []; }
    protected function requireSection(string $section): void {}
    protected function hasPassword(int $userId): bool { return true; }
    protected function redirect(string $url): never { $GLOBALS['OUT']['redirect'] = $url; exit; }
    protected function notFound(): never { http_response_code(404); exit; }
    protected function renderAddresses(string $open, array $errors, array $values): void
    {
        $GLOBALS['OUT']['values'] = $values;
        parent::renderAddresses($open, $errors, $values);
    }
    protected function renderPersonal(string $open, array $errors, array $values): void
    {
        $GLOBALS['OUT']['personal'] = compact('open', 'errors', 'values');
    }
    protected function page(string $view, string $active, array $data = []): void
    {
        $html = '';
        foreach ((array) ($data['modals'] ?? []) as $modal) {
            $html .= $modal->render();
            if (str_contains($modal->render(), 'wi-show')) {
                preg_match('/id="([^"]+)"/', $modal->render(), $m);
                $GLOBALS['OUT']['open'] = $m[1] ?? '?';
            }
        }
        $GLOBALS['OUT']['open'] ??= '';
        $GLOBALS['OUT']['html'] = $html;
    }
}

function addressScenario(string $scenario, array $post): void
{
    $GLOBALS['OUT'] = ['after' => false, 'redirect' => null, 'open' => null, 'personal' => null];
    $GLOBALS['ALERT'] = null;
    \Wonder\App\Theme::set('wonder');
    Route::reset();
    AccountRoutes::reset();
    AccountRoutes::register(new AccountPanel());
    AccountRoutes::extend(new CustomerAccountExtension());
    Query::reset(['contact_addresses' => [
        1 => ['id' => 1, 'contact_id' => 5, 'position' => 1, 'is_default' => 'true', 'deleted' => 'false', 'name' => 'Ada', 'surname' => 'Lovelace', 'country' => 'IT', 'street' => 'Via 1', 'number' => '1', 'city' => 'Milano', 'cap' => '20100', 'province' => 'MI'],
        2 => ['id' => 2, 'contact_id' => 6, 'position' => 1, 'is_default' => 'true', 'deleted' => 'false', 'street' => 'Via 2'],
    ]]);
    $_SESSION = ['wonder_auth_csrf' => 'token-di-sessione'];
    $_SERVER = ['REQUEST_METHOD' => 'POST'];
    $_POST = ['_csrf' => $scenario === 'create.valid' ? 'token-di-sessione' : 'scaduto'] + $post;

    register_shutdown_function(static function (): void {
        $out = $GLOBALS['OUT'];
        $out['errors'] = [];
        if (preg_match_all('/notifications\.\d+\.text/', (string) ($out['html'] ?? ''), $m)) {
            $out['errors'] = array_values(array_unique($m[0]));
        }
        echo "\n@@".json_encode($out + [
            'alert' => $GLOBALS['ALERT'] ?? null,
            'flash' => $_SESSION[FlashMessage::KEY] ?? null,
            'status' => http_response_code(),
            'writes' => Query::$writes,
            'row' => isset(Query::$tables['contact_addresses'][1]),
            'addresses_url' => Route::url('account.addresses'),
        ]);
    });

    $controller = new ControllerDiProva($scenario === 'create.nocontact' ? 0 : 5);
    match ($scenario) {
        'create', 'create.nocontact', 'create.valid' => $controller->handle('addresses.create'),
        'edit' => $controller->handle('addresses.edit', ['id' => 1]),
        'edit.other' => $controller->handle('addresses.edit', ['id' => 2]),
        'delete' => $controller->handle('addresses.delete', ['id' => 1]),
        'personal' => $controller->handle('personal'),
    };
    $GLOBALS['OUT']['after'] = true;
}

addressScenario((string) $argv[2], (array) json_decode((string) ($argv[3] ?? '{}'), true));
