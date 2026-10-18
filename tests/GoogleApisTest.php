<?php
/** php tests/GoogleApisTest.php — le API Google che l'area cliente dichiara al registro del core. */
declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use Wonder\App\Module\Contracts\ModuleGoogleApis;
use Wonder\App\Support\GoogleCloud\GoogleApi;
use Wonder\Plugin\CustomerAccount\CustomerAccount;

/** @return array<string, GoogleApi> */
function apiGoogleAreaCliente(): array
{
    $apis = [];
    foreach (CustomerAccount::googleApis() as $api) {
        $apis[$api->key()] = $api;
    }

    return $apis;
}

check('l\'entrypoint implementa ModuleGoogleApis', fn () => is_subclass_of(CustomerAccount::class, ModuleGoogleApis::class));

check('Maps JavaScript e Places per l\'autocompletamento degli indirizzi', function () {
    return array_keys(apiGoogleAreaCliente()) === ['maps_javascript', 'places'];
});

check('obbligatorie e usate da customer-account', function () {
    foreach (apiGoogleAreaCliente() as $api) {
        if (!$api instanceof GoogleApi || !$api->isRequired() || $api->getUsedBy() !== [CustomerAccount::SLUG]) {
            return false;
        }
    }

    return true;
});

// Le pagine di accesso le registra chi chiama AuthRoutes (ecommerce o il sito) con il suo profilo:
// Accedi con Google lo dichiara lui.
check('niente Accedi con Google: customer-account non registra pagine di accesso', function () {
    return !isset(apiGoogleAreaCliente()['google_oauth']);
});

check('le form degli indirizzi attivano davvero l\'autocompletamento', function () {
    $street = \Wonder\Plugin\CustomerAccount\Models\ContactAddress::address()->formSchema()['street'] ?? null;

    return $street !== null && str_contains(print_r($street, true), 'data-wi-place');
});

summary();
