<?php

namespace Wonder\Plugin\CustomerAccount\Account;

use Wonder\Auth\Frontend\AccountAddressForm;
use Wonder\Auth\Frontend\AccountController;
use Wonder\Auth\Frontend\AccountModal;
use Wonder\Http\Route;
use Wonder\Plugin\CustomerAccount\Account\Concerns\ManagesContact;
use Wonder\Plugin\CustomerAccount\CustomerAccount;
use Wonder\Plugin\CustomerAccount\Models\ContactAddress;

/** Le pagine di customer-account nel pannello: indirizzi di spedizione e il vecchio indirizzo della fatturazione. */
class CustomerAccountController extends AccountController
{
    use ManagesContact;

    public function handle(string $action, array $parameters = []): void
    {
        match ($action) {
            'addresses' => $this->addresses(),
            'addresses.create' => $this->addressEditor(null),
            'addresses.edit' => $this->addressEditor((int) ($parameters['id'] ?? 0)),
            'addresses.delete' => $this->deleteAddress((int) ($parameters['id'] ?? 0)),
            'billing' => $this->billing(),
            default => parent::handle($action, $parameters),
        };
    }

    protected function addresses(): void
    {
        $this->requireSection('addresses');
        $this->renderAddresses('', [], []);
    }

    /**
     * Elenco a schede con i suoi modal: uno di modifica e uno di conferma per indirizzo, più quello nuovo.
     * `$open` è `new` o l'id dell'indirizzo il cui modal si riapre con `$errors` e `$values`.
     *
     * @param list<string> $errors Già tradotti.
     */
    protected function renderAddresses(string $open, array $errors, array $values): void
    {
        $contact = $this->contact((int) $this->user()->id);
        $contactId = (int) ($contact['id'] ?? 0);
        $cards = [];
        $modals = [];

        foreach (AccountAddresses::all($contactId) as $row) {
            $id = (int) $row['id'];
            $card = AccountAddresses::card($row) + ['id' => $id];
            $isOpen = $open === (string) $id;
            $cards[] = $card;
            $modals[] = AccountModal::make(
                'account-address-'.$id, (string) __t('account.addresses.edit_title'),
                AccountAddressForm::fields(ContactAddress::address(), $isOpen ? $values : $row, $isOpen),
                Route::url('account.addresses.edit', ['id' => $id]), [],
                $isOpen ? $errors : [], $isOpen,
            );
            $modals[] = AccountModal::confirm(
                'account-address-delete-'.$id, (string) __t('account.addresses.delete_title'),
                implode(', ', $card['lines']),
                Route::url('account.addresses.delete', ['id' => $id]), (string) __t('account.addresses.delete'),
            );
        }
        // Senza scheda cliente non si scrive: niente modal per aggiungere, solo l'errore.
        if ($contactId > 0) {
            $isNew = $open === 'new';
            $modals[] = AccountModal::make(
                'account-address-new', (string) __t('account.addresses.create_title'),
                AccountAddressForm::fields(ContactAddress::address(), $isNew ? $values : [], $isNew),
                Route::url('account.addresses.create'), [],
                $isNew ? $errors : [], $isNew,
            );
        }

        $this->page(CustomerAccount::packageView('pages/account/addresses.php'), 'addresses', [
            'title' => (string) __t('account.navigation.addresses'),
            'seo_url' => Route::url('account.addresses'),
            'cards' => $cards,
            'can_add' => $contactId > 0,
            'modals' => $modals,
            'errors' => $this->contactErrors($contact),
        ]);
    }

    /** Nuovo indirizzo (`$id` nullo) o modifica. Il POST con errori riapre l'elenco con il modal aperto; il GET è il ripiego senza JS. */
    protected function addressEditor(?int $id): void
    {
        $this->requireSection('addresses');
        $contactId = (int) ($this->contact((int) $this->user()->id)['id'] ?? 0);
        $row = [];
        if ($id !== null) {
            $row = AccountAddresses::find($contactId, $id) ?? $this->notFound();
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->requireCsrf();
            if ($contactId <= 0) {
                $this->renderAddresses('', [], []);
                return;
            }
            $post = $this->post();
            $result = AccountAddresses::save($contactId, $post, $id);
            if ($result->success) {
                $this->flash((string) __t('account.saved'));
                $this->redirect(Route::url('account.addresses'));
            }
            $this->renderAddresses($id === null ? 'new' : (string) $id, $result->messages, $post);
            return;
        }
        if ($contactId <= 0) {
            $this->renderAddresses('', [], []);
            return;
        }

        $url = $id === null ? Route::url('account.addresses.create') : Route::url('account.addresses.edit', ['id' => $id]);
        $this->page(CustomerAccount::packageView('pages/account/address-form.php'), 'addresses', [
            'title' => (string) __t($id === null ? 'account.addresses.create_title' : 'account.addresses.edit_title'),
            'seo_url' => $url,
            'fields' => AccountAddressForm::fields(ContactAddress::address(), $row),
            'action' => $url,
            'back_url' => Route::url('account.addresses'),
        ]);
    }

    /** Elimina un indirizzo della scheda del cliente: prima il CSRF, poi la proprietà (404 se non è suo). */
    protected function deleteAddress(int $id): void
    {
        $this->requireSection('addresses');
        $this->requireCsrf();
        if (!AccountAddresses::delete((int) ($this->contact((int) $this->user()->id)['id'] ?? 0), $id)) {
            $this->notFound();
        }
        $this->flash((string) __t('account.addresses.deleted'));
        $this->redirect(Route::url('account.addresses'));
    }

    /** La fatturazione sta in «Dati personali»: il suo vecchio indirizzo porta lì. */
    protected function billing(): never
    {
        $this->requireSection('billing');
        $this->redirect(Route::url('account.personal'));
    }
}
