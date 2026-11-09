<?php

namespace Wonder\Plugin\CustomerAccount\Account;

use Wonder\App\Schema\Extensions\AddressExtension;
use Wonder\Auth\Frontend\AccountAddressValidation;
use Wonder\Plugin\CustomerAccount\Models\Company;
use Wonder\Plugin\CustomerAccount\Models\Contact;

/** Dati di fatturazione della scheda del cliente. */
final class AccountBilling
{
    /** @return object{success: bool, messages: list<string>} */
    public static function save(int $contactId, array $input): object
    {
        return self::store(Contact::class, Contact::billing(), $contactId, $input);
    }

    /**
     * Fatturazione di un'azienda, con la stessa validazione della scheda.
     * Il diritto di modificarla lo controlla chi chiama.
     *
     * @return object{success: bool, messages: list<string>}
     */
    public static function saveCompany(int $companyId, array $input): object
    {
        return self::store(Company::class, Company::billing(), $companyId, $input);
    }

    /** @param class-string<Contact|Company> $model */
    private static function store(string $model, AddressExtension $billing, int $id, array $input): object
    {
        if ($id <= 0) {
            return (object) ['success' => false, 'messages' => [(string) __t('account.errors.contact')]];
        }
        $values = array_intersect_key($input, $billing->labels());
        $messages = AccountAddressValidation::validate($billing, $values);
        if ($messages !== []) {
            return (object) ['success' => false, 'messages' => $messages];
        }
        $result = $model::update($values, $id);

        return ($result->success ?? false)
            ? (object) ['success' => true, 'messages' => []]
            : (object) ['success' => false, 'messages' => [(string) __t('account.errors.save')]];
    }

    /** Una sola riga: intestatario, codice fiscale, indirizzo. I dati da società stanno sulle aziende. */
    public static function rows(array $contact): array
    {
        $text = static fn (string $key): string => trim((string) ($contact[$key] ?? ''));
        $holder = trim($text('name').' '.$text('surname'));
        $tax = $text('cf');
        $address = implode(', ', array_filter([
            trim($text('street').' '.$text('number')),
            trim($text('cap').' '.$text('city').' '.$text('province')),
            $text('country'),
        ]));
        $value = static fn (string $text): string => $text !== '' ? $text : '—';

        return [
            ['key' => 'billing', 'columns' => [
                ['label' => (string) __t('account.billing.holder'), 'value' => $value($holder)],
                ['label' => (string) __t('account.billing.tax'), 'value' => $value($tax)],
                ['label' => (string) __t('account.billing.address'), 'value' => $value($address)],
            ], 'action' => [
                'label' => (string) __t('account.actions.edit'), 'href' => '', 'modal' => 'account-billing',
                'icon' => 'bi bi-pencil', 'disabled' => false, 'hint' => '',
            ]],
        ];
    }
}
