<?php

namespace Wonder\Plugin\CustomerAccount\Auth\Concerns;

use Wonder\App\ResourceSchema\FormField;
use Wonder\App\Schema\Extensions\AddressExtension;
use Wonder\App\Support\GoogleMaps;
use Wonder\Auth\Frontend\AccountAddressForm;
use Wonder\Auth\Frontend\AccountAddressValidation;
use Wonder\Auth\Frontend\AccountSaveFailed;
use Wonder\Plugin\CustomerAccount\Account\AccountAddresses;
use Wonder\Plugin\CustomerAccount\Account\AccountBilling;
use Wonder\Plugin\CustomerAccount\Account\ContactAccount;
use Wonder\Plugin\CustomerAccount\Account\CustomerContact;
use Wonder\Plugin\CustomerAccount\Auth\SignupFields;
use Wonder\Plugin\CustomerAccount\CustomerAccount;
use Wonder\Plugin\CustomerAccount\Models\Contact;
use Wonder\Plugin\CustomerAccount\Models\ContactAddress;
use Wonder\Plugin\CustomerAccount\Support\BirthDate;

/**
 * Campi della registrazione decisi dal sito (`signup.fields` della config del modulo): telefono, data di
 * nascita, fatturazione e primo indirizzo di spedizione nel secondo passo, salvati nella scheda cliente.
 * Per un AuthProfile del core: chi estende un altro profilo usa il trait, gli altri `CustomerAuthProfile`.
 */
trait HasSignupFields
{
    /** Messaggi degli indirizzi, già tradotti: li aggiunge validationTexts() dopo validate(). @var list<string> */
    private array $signupMessages = [];

    /** I default del profilo, sotto la config del sito: per esempio `['phone' => 'required']`. */
    protected function signupDefaults(): array
    {
        return [];
    }

    /** @return array<string, string> sezione => `off` | `optional` | `required` */
    public function signupFields(): array
    {
        return SignupFields::resolve((array) CustomerAccount::config('signup.fields', []), $this->signupDefaults());
    }

    public function signupState(string $section): string
    {
        return $this->signupFields()[$section] ?? 'off';
    }

    public function phoneRequired(): bool
    {
        return $this->signupState('phone') === 'required';
    }

    public function phoneEnabled(): bool
    {
        return $this->signupState('phone') !== 'off';
    }

    /** Campi della scheda prima della password; il nome della fatturazione si precompila dall'utente. */
    public function fields(string $surface, array $values = [], bool $passwordRequired = true): array
    {
        $fields = parent::fields($surface, $values, $passwordRequired);
        if ($surface !== 'signup-completion') {
            return $fields;
        }
        $own = $this->signupFormFields($values);
        $at = array_search('password', array_keys($fields), true);

        return $at === false ? $fields + $own : array_slice($fields, 0, $at, true) + $own + array_slice($fields, $at, null, true);
    }

    public function validate(string $surface, array $input, bool $passwordRequired = true): array
    {
        $errors = parent::validate($surface, $input, $passwordRequired);
        if ($surface !== 'signup-completion') {
            return $errors;
        }
        $this->signupMessages = [];

        $birth = trim((string) ($input['birth_date'] ?? ''));
        if ($this->signupState('birth_date') === 'required' && $birth === '') {
            $errors['birth_date'] = 'required';
        } elseif ($this->signupState('birth_date') !== 'off' && !BirthDate::valid($birth)) {
            $errors['birth_date'] = 'invalid';
        }
        foreach ($this->signupAddresses() as $section => $address) {
            if (!$this->signupSectionWanted($section, $input)) {
                continue;
            }
            $messages = AccountAddressValidation::validate($address, $this->signupSectionValues($section, $input));
            if ($messages !== []) {
                $errors[$section] = 'invalid';
                $title = (string) __t('account.signup.'.$section);
                foreach ($messages as $message) {
                    $this->signupMessages[] = $title.': '.$message;
                }
            }
        }
        return $errors;
    }

    public function validationTexts(array $errors): array
    {
        $own = array_intersect_key($errors, array_flip(['birth_date', ...array_keys($this->signupAddresses())]));
        $texts = parent::validationTexts(array_diff_key($errors, $own));
        if (isset($own['birth_date'])) {
            $texts[] = (string) __t($own['birth_date'] === 'required' ? 'account.signup.errors.birth_date_required' : 'account.personal.errors.birth_date');
        }
        return [...$texts, ...(array_diff_key($own, ['birth_date' => true]) !== [] ? $this->signupMessages : [])];
    }

    public function afterUserSaved(int $userId, string $surface, array $input): void
    {
        parent::afterUserSaved($userId, $surface, $input);
        if ($surface === 'signup-completion') {
            $this->saveSignupFields($userId, $input);
        }
    }

    /** Con un campo richiesto della scheda che manca, anche l'accesso federato passa dal secondo passo. */
    public function requiresCompletion(array $user): bool
    {
        if (parent::requiresCompletion($user)) {
            return true;
        }
        $required = array_keys(array_filter(
            array_diff_key($this->signupFields(), ['phone' => true]),
            static fn (string $state): bool => $state === 'required',
        ));
        if ($required === []) {
            return false;
        }
        $userId = (int) ($user['id'] ?? 0);
        $contact = $userId > 0 ? Contact::find(['user_id' => $userId], 1) : null;
        $contactId = is_array($contact) ? (int) ($contact['id'] ?? 0) : 0;
        if ($contactId <= 0) {
            return true;
        }
        foreach ($required as $section) {
            $missing = match ($section) {
                'birth_date' => trim((string) ($contact['birth_date'] ?? '')) === '',
                'billing' => trim((string) ($contact['street'] ?? '')) === '',
                'shipping_address' => AccountAddresses::all($contactId) === [],
                default => false,
            };
            if ($missing) {
                return true;
            }
        }
        return false;
    }

    /**
     * Nella transazione della conferma: scheda collegata, poi data di nascita, fatturazione e primo indirizzo.
     * Un errore annulla tutta la registrazione. `$contactId` se il profilo ha già collegato la scheda.
     */
    protected function saveSignupFields(int $userId, array $input, ?int $contactId = null): void
    {
        $birth = $this->signupState('birth_date') !== 'off' ? trim((string) ($input['birth_date'] ?? '')) : '';
        $sections = array_filter(
            array_keys($this->signupAddresses()),
            fn (string $section): bool => $this->signupSectionWanted($section, $input),
        );
        if ($birth === '' && $sections === []) {
            return;
        }
        if ($contactId === null) {
            $link = ContactAccount::link($userId, $this->phoneEnabled() ? array_intersect_key($input, ['phone_prefix' => true, 'phone' => true]) : []);
            $contactId = ($link->success ?? false) ? (int) ($link->contact_id ?? 0) : 0;
        }
        if ($contactId <= 0) {
            throw new AccountSaveFailed('contact');
        }
        if ($birth !== '' && !(Contact::update(['birth_date' => $birth], $contactId)->success ?? false)) {
            throw new AccountSaveFailed('contact');
        }
        foreach ($sections as $section) {
            $values = $this->signupSectionValues($section, $input);
            $result = $section === 'billing'
                ? AccountBilling::save($contactId, $values)
                : AccountAddresses::save($contactId, SignupFields::unprefix($values, 'shipping'));
            if (!$result->success) {
                throw new AccountSaveFailed('contact');
            }
        }
        CustomerContact::forget($userId);
    }

    /** @return array<string, AddressExtension> Le sezioni indirizzo accese. */
    protected function signupAddresses(): array
    {
        $addresses = [];
        if ($this->signupState('billing') !== 'off') {
            $addresses['billing'] = Contact::billing();
        }
        if ($this->signupState('shipping_address') !== 'off') {
            $addresses['shipping_address'] = ContactAddress::address('shipping');
        }
        return $addresses;
    }

    /** La sezione richiesta sempre, quella facoltativa solo se compilata. */
    protected function signupSectionWanted(string $section, array $input): bool
    {
        $state = $this->signupState($section);
        return $state === 'required'
            || ($state === 'optional' && SignupFields::filled($this->signupSectionValues($section, $input), $section === 'shipping_address' ? 'shipping' : ''));
    }

    /** Solo i campi della sezione; nella fatturazione il telefono resta quello dell'account. */
    protected function signupSectionValues(string $section, array $input): array
    {
        $address = $this->signupAddresses()[$section] ?? null;
        if ($address === null) {
            return [];
        }
        $values = array_intersect_key($input, $address->labels());
        return $section === 'billing'
            ? array_diff_key($values, ['phone_prefix' => true, 'phone' => true, 'email' => true])
            : $values + GoogleMaps::cleanGeo($input, $address->prefix().'_');
    }

    /** Titolo di una sezione del form, HTML già sicuro: `Text` non ha un renderer nel tema Wonder. */
    protected function signupTitle(string $title): string
    {
        return '<h3 class="text fw-600">'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</h3>';
    }

    private function signupFormFields(array $values): array
    {
        // I valori postati comprendono il CSRF: senza, il form si apre la prima volta.
        $submitted = isset($values['csrf_token']);
        $fields = [];

        if (($state = $this->signupState('birth_date')) !== 'off') {
            $fields['birth_date'] = FormField::key('birth_date')->textDate()->label((string) __t('account.personal.birth_date'))
                ->required($state === 'required')->value((string) ($values['birth_date'] ?? ''));
        }
        foreach ($this->signupAddresses() as $section => $address) {
            $sectionValues = $values;
            if ($section === 'billing' && !$submitted) {
                $user = isset($values['email']) && function_exists('infoUser') ? \infoUser((string) $values['email'], 'email') : null;
                $sectionValues += ['name' => (string) ($user->name ?? ''), 'surname' => (string) ($user->surname ?? '')];
            }
            $inputs = AccountAddressForm::fields($address, $sectionValues, $submitted);
            if ($section === 'billing') {
                $inputs = array_diff_key($inputs, ['phone_prefix' => true, 'phone' => true, 'email' => true]);
            }
            if ($this->signupState($section) === 'optional') {
                foreach ($inputs as $input) {
                    $input->required(false)->context('required_when_states_available', false);
                }
            }
            $fields[$section.'_title'] = $this->signupTitle((string) __t('account.signup.'.$section));
            $fields[$section] = AccountAddressForm::layout($inputs, $address);
        }
        return $fields;
    }
}
