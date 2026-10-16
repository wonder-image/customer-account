<?php

namespace Wonder\Plugin\CustomerAccount\Account;

use Wonder\App\ResourceSchema\FormField;
use Wonder\Auth\Frontend\AccountAddressForm;
use Wonder\Auth\Frontend\AccountModal;
use Wonder\Auth\Frontend\AccountOverview;
use Wonder\Auth\Frontend\AccountRoutes;
use Wonder\Auth\Frontend\AccountSaveFailed;
use Wonder\Auth\Frontend\BaseAccountExtension;
use Wonder\Http\Route;
use Wonder\Plugin\CustomerAccount\CustomerAccount;
use Wonder\Plugin\CustomerAccount\Models\Contact;
use Wonder\Plugin\CustomerAccount\Support\BirthDate;

/**
 * La scheda cliente nel pannello del core: data di nascita e telefono in «Dati personali», la fatturazione
 * (righe e modal), la pagina degli indirizzi e la scheda allineata a nome, telefono ed email dell'utente.
 * Va registrata prima delle estensioni che si appoggiano alla scheda (ecommerce): le sue righe e voci
 * si inseriscono rispetto a quelle del core.
 */
class CustomerAccountExtension extends BaseAccountExtension
{
    public function routes(): void
    {
        $panel = AccountRoutes::panel();
        $handler = CustomerAccount::handlerPath('frontend/account.php');

        AccountRoutes::group(static function () use ($panel, $handler): void {
            if ($panel->enabled('addresses')) {
                Route::get('/indirizzi/', $handler, ['account_action' => 'addresses'])->name('addresses');
                Route::get('/indirizzi/nuovo/', $handler, ['account_action' => 'addresses.create'])->name('addresses.create');
                Route::post('/indirizzi/nuovo/', $handler, ['account_action' => 'addresses.create']);
                Route::get('/indirizzi/{id}/', $handler, ['account_action' => 'addresses.edit'])->name('addresses.edit')->where('id', '[0-9]+');
                Route::post('/indirizzi/{id}/', $handler, ['account_action' => 'addresses.edit'])->where('id', '[0-9]+');
                Route::post('/indirizzi/{id}/elimina/', $handler, ['account_action' => 'addresses.delete'])->name('addresses.delete')->where('id', '[0-9]+');
            }
            // La fatturazione è in «Dati personali»: resta il vecchio indirizzo, che porta lì.
            if ($panel->enabled('billing')) {
                Route::get('/fatturazione/', $handler, ['account_action' => 'billing'])->name('billing');
            }
        });
    }

    /** «Indirizzi» subito dopo «Dati personali» (in coda se quella voce non c'è). */
    public function navigation(array $items, object $user): array
    {
        if (!AccountRoutes::panel()->enabled('addresses')) {
            return $items;
        }
        return self::insertAfter($items, 'personal', ['addresses' => [
            'label' => (string) __t('account.navigation.addresses'), 'href' => Route::url('account.addresses'), 'icon' => 'bi bi-geo-alt',
        ]]);
    }

    /** Dopo i dati personali la fatturazione, che si modifica da «Dati personali», poi gli indirizzi. */
    public function overviewRows(array $rows, object $user): array
    {
        $panel = AccountRoutes::panel();
        $contact = $this->contact($user);
        $contactId = (int) ($contact['id'] ?? 0);
        if ($contactId <= 0) {
            return $rows;
        }
        $value = static fn (string $text): string => trim($text) !== '' ? trim($text) : '—';
        $own = [];

        if ($panel->enabled('billing') && self::rowIndex($rows, 'personal') !== null) {
            foreach (AccountBilling::rows($contact) as $row) {
                $own[] = ['action' => AccountOverview::link((string) __t('account.actions.edit'), Route::url('account.personal'), 'bi bi-pencil')] + $row;
            }
        }
        if ($panel->enabled('addresses')) {
            $addresses = AccountAddresses::all($contactId);
            $first = $addresses !== [] ? AccountAddresses::card($addresses[0]) : null;
            $own[] = ['key' => 'addresses', 'columns' => [
                ['label' => (string) __t('account.navigation.addresses'), 'value' => (string) __t($addresses === [] ? 'account.overview.addresses_none' : 'account.overview.addresses_count', ['count' => count($addresses)])],
                ['label' => (string) __t('account.overview.address_first'), 'value' => $value($first === null ? '' : implode(', ', array_filter([$first['name'], ...$first['lines']])))],
            ], 'action' => AccountOverview::link((string) __t('account.actions.manage'), Route::url('account.addresses'), 'bi bi-geo-alt')];
        }

        return self::insertRowsAfter($rows, 'personal', $own);
    }

    /** La data di nascita nella riga dei dati personali, la fatturazione dopo la password. */
    public function personalRows(array $rows, object $user): array
    {
        $contact = $this->contact($user);
        $at = self::rowIndex($rows, 'personal');
        if ($at !== null) {
            $birth = (string) ($contact['birth_date'] ?? '');
            $columns = (array) ($rows[$at]['columns'] ?? []);
            array_splice($columns, 1, 0, [['label' => (string) __t('account.personal.birth_date'), 'value' => $birth !== '' ? date('d/m/Y', strtotime($birth)) : '—']]);
            $rows[$at]['columns'] = $columns;
        }
        if ($this->billing($contact)) {
            $rows = self::insertRowsAfter($rows, 'password', AccountBilling::rows($contact));
        }
        return $rows;
    }

    /** Data di nascita dopo il cognome; telefono e prefisso dalla scheda, se il modal non si riapre con i valori postati. */
    public function personalFormFields(array $fields, object $user, array $values): array
    {
        $contact = $this->contact($user);
        $fields = self::insertAfter($fields, 'surname', [
            'birth_date' => FormField::key('birth_date')->textDate()->label((string) __t('account.personal.birth_date'))
                ->value((string) ($values['birth_date'] ?? $contact['birth_date'] ?? '')),
        ]);

        if (!isset($values['phone']) && trim((string) ($contact['phone'] ?? '')) !== '' && isset($fields['phone'])) {
            $fields['phone']->value(self::localPhone((string) $contact['phone'], (string) ($contact['phone_prefix'] ?? '+39')));
        }
        if (!isset($values['phone_prefix']) && trim((string) ($contact['phone_prefix'] ?? '')) !== '' && isset($fields['phone_prefix'])) {
            $fields['phone_prefix']->value((string) $contact['phone_prefix']);
        }
        return $fields;
    }

    public function validatePersonal(array $input, object $user): array
    {
        return BirthDate::valid((string) ($input['birth_date'] ?? '')) ? [] : [(string) __t('account.personal.errors.birth_date')];
    }

    /** Nella transazione dei dati personali: la scheda si collega (o si aggiorna) e prende la data di nascita. */
    public function afterPersonalSaved(array $input, object $user): void
    {
        $userId = (int) ($user->id ?? 0);
        $link = ContactAccount::link($userId, ['phone_prefix' => (string) ($input['phone_prefix'] ?? ''), 'phone' => (string) ($input['phone'] ?? '')]);
        if (!($link->success ?? false)) {
            throw new AccountSaveFailed('contact');
        }
        $birth = trim((string) ($input['birth_date'] ?? ''));
        if (!(Contact::update(['birth_date' => $birth !== '' ? $birth : null], (int) $link->contact_id)->success ?? false)) {
            throw new AccountSaveFailed('contact');
        }
        CustomerContact::forget($userId);
    }

    /** Il modal della fatturazione, dopo quelli del core. */
    public function personalModals(array $modals, object $user, string $open, array $errors, array $values): array
    {
        $contact = $this->contact($user);
        if (!$this->billing($contact)) {
            return $modals;
        }
        $isOpen = $open === 'billing';
        $modals[] = AccountModal::make(
            'account-billing', (string) __t('account.billing.title'),
            AccountAddressForm::fields(Contact::billing(), $isOpen ? $values : $contact, $isOpen),
            Route::url('account.personal'), ['form' => 'billing'],
            $isOpen ? $errors : [], $isOpen,
        );
        return $modals;
    }

    /** Solo `billing`, e solo con la sezione accesa e la scheda: altrimenti il core risponde 404. */
    public function savePersonalForm(string $form, object $user, array $input): ?array
    {
        $contact = $form === 'billing' ? $this->contact($user) : [];
        if (!$this->billing($contact)) {
            return null;
        }
        $result = AccountBilling::save((int) $contact['id'], $input);
        CustomerContact::forget((int) ($user->id ?? 0));
        return $result->success ? [] : $result->messages;
    }

    public function pageErrors(array $errors, object $user, string $page): array
    {
        return [...$errors, ...CustomerContact::errors($this->contact($user))];
    }

    /** Nella transazione della conferma: la scheda collegata prende la nuova email. */
    public function afterEmailConfirmed(int $userId, string $email): void
    {
        $contact = Contact::find(['user_id' => $userId], 1);
        if (is_array($contact) && !empty($contact['id'])) {
            if (!(Contact::update(['email' => $email], (int) $contact['id'])->success ?? false)) {
                throw new AccountSaveFailed('contact');
            }
        }
        CustomerContact::forget($userId);
    }

    protected function contact(object $user): array
    {
        return CustomerContact::forUser((int) ($user->id ?? 0));
    }

    private function billing(array $contact): bool
    {
        return AccountRoutes::panel()->enabled('billing') && (int) ($contact['id'] ?? 0) > 0;
    }

    /** Il telefono della scheda è canonico (`+39333…`): nel campo va solo il numero, dopo il prefisso. */
    private static function localPhone(string $phone, string $prefix): string
    {
        if (str_starts_with(trim($phone), '+')) {
            $digits = (string) preg_replace('/\D+/', '', $phone);
            $prefixDigits = (string) preg_replace('/\D+/', '', $prefix);
            if ($prefixDigits !== '' && str_starts_with($digits, $prefixDigits)) {
                return substr($digits, strlen($prefixDigits));
            }
        }
        return $phone;
    }

    /** Inserisce `$own` dopo la chiave `$after`, o in coda se non c'è. */
    private static function insertAfter(array $items, string $after, array $own): array
    {
        $at = array_search($after, array_keys($items), true);
        if ($at === false) {
            return $items + $own;
        }
        return array_slice($items, 0, $at + 1, true) + $own + array_slice($items, $at + 1, null, true);
    }

    private static function rowIndex(array $rows, string $key): ?int
    {
        foreach (array_values($rows) as $i => $row) {
            if (is_array($row) && ($row['key'] ?? null) === $key) {
                return $i;
            }
        }
        return null;
    }

    /** Righe (lista con `key`) dopo quella con chiave `$after`, o in coda. */
    private static function insertRowsAfter(array $rows, string $after, array $own): array
    {
        $rows = array_values($rows);
        $at = self::rowIndex($rows, $after);
        if ($at === null) {
            return [...$rows, ...$own];
        }
        array_splice($rows, $at + 1, 0, $own);
        return $rows;
    }
}
