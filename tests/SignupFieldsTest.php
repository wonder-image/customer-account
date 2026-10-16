<?php
/** php tests/SignupFieldsTest.php — campi della registrazione decisi dal sito: stati, campi e validazione (nessun database). */
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require_once WI_APP_ROOT.'/app/function/other/geo.php';

use Wonder\Plugin\CustomerAccount\Auth\CustomerAuthProfile;
use Wonder\Plugin\CustomerAccount\Auth\SignupFields;
use Wonder\Plugin\CustomerAccount\Support\BirthDate;

final class ProfiloConfigurato extends CustomerAuthProfile
{
    public function __construct(private array $states) {}
    public function signupFields(): array { return SignupFields::resolve($this->states); }
}

$base = ['name' => 'Ada', 'surname' => 'Lovelace', 'email' => 'ada@example.com', 'password' => 'Password-sicura-1', 'password_confirmation' => 'Password-sicura-1', 'privacy' => 'true', 'csrf_token' => 'x'];
$shipping = ['shipping_name' => 'Ada', 'shipping_surname' => 'Lovelace', 'shipping_country' => 'IT', 'shipping_street' => 'Via Roma', 'shipping_number' => '1', 'shipping_city' => 'Milano', 'shipping_cap' => '20100', 'shipping_province' => 'MI'];

check('la config del sito vince sui default, poi off; valori sconosciuti diventano off', function () {
    $states = SignupFields::resolve(['phone' => 'optional', 'billing' => 'boh'], ['phone' => 'required', 'birth_date' => 'required']);
    return $states === ['phone' => 'optional', 'birth_date' => 'required', 'billing' => 'off', 'shipping_address' => 'off'];
});

check('una sezione facoltativa conta solo con un campo scritto dal cliente', function () {
    return !SignupFields::filled(['shipping_country' => 'IT', 'shipping_name' => 'Ada', 'shipping_phone_prefix' => '+39'], 'shipping')
        && SignupFields::filled(['shipping_street' => 'Via Roma'], 'shipping')
        && SignupFields::unprefix(['shipping_city' => 'Milano', 'email' => 'x'], 'shipping') === ['city' => 'Milano'];
});

check('data di nascita: vuota valida, formato e futuro rifiutati', function () {
    return BirthDate::valid('') && BirthDate::valid('1990-04-02') && !BirthDate::valid('02/04/1990')
        && !BirthDate::valid('1990-02-31') && !BirthDate::valid(date('Y-m-d', strtotime('+1 day')));
});

check('tutto off: i campi del secondo passo restano quelli del core', function () use ($base) {
    $core = array_keys((new \Wonder\Auth\Frontend\AuthProfile())->fields('signup-completion', $base));
    return array_keys((new ProfiloConfigurato([]))->fields('signup-completion', $base)) === $core;
});

check('campi accesi prima della password, telefono facoltativo senza obbligo', function () use ($base) {
    $profile = new ProfiloConfigurato(['phone' => 'optional', 'birth_date' => 'required', 'billing' => 'optional', 'shipping_address' => 'required']);
    $keys = array_keys($profile->fields('signup-completion', $base));
    $at = array_search('password', $keys, true);
    $own = ['birth_date', 'billing_title', 'billing', 'shipping_address_title', 'shipping_address'];
    foreach ($own as $key) {
        $i = array_search($key, $keys, true);
        if ($i === false || ($at !== false && $i > $at)) {
            return false;
        }
    }
    return in_array('phone', $keys, true) && $profile->phoneEnabled() && !$profile->phoneRequired()
        && array_keys((new ProfiloConfigurato(['birth_date' => 'required']))->fields('signup', $base)) === array_keys((new \Wonder\Auth\Frontend\AuthProfile())->fields('signup', $base));
});

check('data richiesta e spedizione richiesta: errori e messaggi della sezione', function () use ($base) {
    $profile = new ProfiloConfigurato(['birth_date' => 'required', 'shipping_address' => 'required']);
    $errors = $profile->validate('signup-completion', $base);
    $texts = $profile->validationTexts($errors);
    return ($errors['birth_date'] ?? null) === 'required' && ($errors['shipping_address'] ?? null) === 'invalid'
        && in_array('account.signup.errors.birth_date_required', $texts, true)
        && array_filter($texts, static fn ($t) => str_starts_with($t, 'account.signup.shipping_address: ')) !== [];
});

check('sezioni facoltative vuote passano, compilate a metà no', function () use ($base, $shipping) {
    $profile = new ProfiloConfigurato(['birth_date' => 'optional', 'billing' => 'optional', 'shipping_address' => 'optional']);
    $empty = $profile->validate('signup-completion', $base + ['shipping_country' => 'IT', 'birth_date' => '']);
    $half = $profile->validate('signup-completion', $base + ['shipping_street' => 'Via Roma', 'birth_date' => '31/02/1990']);
    return !isset($empty['birth_date'], $empty['billing'], $empty['shipping_address'])
        && ($half['shipping_address'] ?? null) === 'invalid' && ($half['birth_date'] ?? null) === 'invalid' && !isset($half['billing']);
});

check('spedizione completa valida', function () use ($base, $shipping) {
    $errors = (new ProfiloConfigurato(['shipping_address' => 'required']))->validate('signup-completion', $base + $shipping);
    return !isset($errors['shipping_address']);
});

check('i campi accesi si rendono nel tema del frontend', function () use ($base) {
    \Wonder\App\Theme::set('wonder');
    $profile = new ProfiloConfigurato(['birth_date' => 'required', 'billing' => 'optional', 'shipping_address' => 'required']);
    $html = '';
    foreach ($profile->fields('signup-completion', $base) as $field) {
        $html .= (string) $field;
    }
    return str_contains($html, 'name="birth_date"') && str_contains($html, 'name="street"')
        && str_contains($html, 'name="shipping_street"') && str_contains($html, '<h3 class="text fw-600">account.signup.billing</h3>');
});

summary();
