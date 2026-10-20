<?php

namespace Wonder\Plugin\CustomerAccount\Account;

use Throwable;
use Wonder\Plugin\CustomerAccount\Models\ContactAddress;
use Wonder\App\Support\GoogleMaps;
use Wonder\Auth\Frontend\AccountAddressValidation;

/** Indirizzi della scheda del cliente: ognuno si legge, si cambia e si elimina solo dalla sua scheda. */
final class AccountAddresses
{
    public static function all(int $contactId): array
    {
        if ($contactId <= 0) { return []; }
        try {
            $rows = ContactAddress::find(['contact_id' => $contactId], null, 'position', 'ASC');
            return is_array($rows) ? array_values($rows) : [];
        } catch (Throwable) {
            return [];
        }
    }

    public static function find(int $contactId, int $addressId): ?array
    {
        if ($contactId <= 0 || $addressId <= 0) { return null; }
        $row = ContactAddress::find(['id' => $addressId, 'contact_id' => $contactId], 1);
        return is_array($row) && $row !== [] ? $row : null;
    }

    /** @return object{success: bool, messages: list<string>, id: int} */
    public static function save(int $contactId, array $input, ?int $addressId = null): object
    {
        if ($addressId !== null && self::find($contactId, $addressId) === null) {
            return (object) ['success' => false, 'messages' => [(string) __t('account.errors.save')], 'id' => 0];
        }
        $address = ContactAddress::address();
        $values = self::values($input);
        $messages = AccountAddressValidation::validate($address, $values);
        if ($messages !== []) {
            return (object) ['success' => false, 'messages' => $messages, 'id' => $addressId ?? 0];
        }
        if ($addressId === null) {
            // Predefinito se la scheda non ne ha uno attivo: ripara anche le schede di prima.
            $default = ContactAddress::find(['contact_id' => $contactId, 'is_default' => 'true'], 1);
            $result = ContactAddress::create($values + [
                'contact_id' => $contactId,
                'position' => self::nextPosition($contactId),
                'is_default' => is_array($default) && $default !== [] ? 'false' : 'true',
            ]);
            $id = (int) ($result->insert_id ?? 0);
        } else {
            $result = ContactAddress::update($values, $addressId);
            $id = $addressId;
        }
        return ($result->success ?? false)
            ? (object) ['success' => true, 'messages' => [], 'id' => $id]
            : (object) ['success' => false, 'messages' => [(string) __t('account.errors.save')], 'id' => $id];
    }

    /** Campi dell'indirizzo inviati, più place ID e coordinate puliti. */
    public static function values(array $input): array
    {
        return array_intersect_key($input, ContactAddress::address()->labels()) + GoogleMaps::cleanGeo($input);
    }

    /** La posizione dopo l'ultima della scheda, letta con un solo MAX. */
    public static function nextPosition(int $contactId): int
    {
        $row = ContactAddress::find(['contact_id' => $contactId], 1, null, null, 'MAX(`position`) AS max_position');
        return (int) (is_array($row) ? ($row['max_position'] ?? 0) : 0) + 1;
    }

    /** Se si elimina il predefinito, lo diventa l'indirizzo rimasto con la posizione più bassa. */
    public static function delete(int $contactId, int $addressId): bool
    {
        $address = self::find($contactId, $addressId);
        if ($address === null || !(bool) (ContactAddress::delete($addressId)->success ?? false)) {
            return false;
        }
        if (($address['is_default'] ?? 'false') === 'true') {
            $next = ContactAddress::find(['contact_id' => $contactId], 1, 'position', 'ASC');
            if (is_array($next) && !empty($next['id'])) {
                ContactAddress::update(['is_default' => 'true'], (int) $next['id']);
            }
        }
        return true;
    }

    /** Righe della scheda: «via numero, cap» e «città (provincia)». */
    public static function card(array $address): array
    {
        $number = trim((string) ($address['phone'] ?? ''));
        $phone = $number !== '' ? trim((string) ($address['phone_prefix'] ?? '').' '.$number) : '';
        $province = trim((string) ($address['province'] ?? ''));
        return [
            'name' => trim((string) ($address['name'] ?? '').' '.(string) ($address['surname'] ?? '')),
            'phone' => $phone,
            'phone_href' => $phone !== '' ? 'tel:'.preg_replace('/[^0-9+]/', '', $phone) : '',
            'lines' => array_values(array_filter([
                trim(trim((string) ($address['street'] ?? '').' '.(string) ($address['number'] ?? '')).', '.(string) ($address['cap'] ?? ''), ', '),
                trim((string) ($address['city'] ?? '').($province !== '' ? ' ('.$province.')' : '')),
            ])),
        ];
    }
}
