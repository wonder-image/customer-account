<?php

namespace Wonder\Plugin\CustomerAccount\Account;

use Wonder\Plugin\CustomerAccount\Models\Contact;

/**
 * La scheda cliente dell'utente del pannello: collegata al primo accesso, letta una volta per richiesta.
 * `[]` vuol dire che non si può usare (per esempio è collegata a un altro utente).
 */
final class CustomerContact
{
    /** @var array<int, array> */
    private static array $memo = [];

    public static function forUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        if (!isset(self::$memo[$userId])) {
            $contact = Contact::find(['user_id' => $userId], 1);
            if (!is_array($contact) || empty($contact['id'])) {
                ContactAccount::link($userId);
                $contact = Contact::find(['user_id' => $userId], 1);
            }
            self::$memo[$userId] = is_array($contact) && !empty($contact['id']) ? $contact : [];
        }
        return self::$memo[$userId];
    }

    /** Da chiamare dopo ogni scrittura sulla scheda, se nella stessa richiesta la si rilegge. */
    public static function forget(?int $userId = null): void
    {
        if ($userId === null) {
            self::$memo = [];
        } else {
            unset(self::$memo[$userId]);
        }
    }

    /** La scheda che non si può usare si dice nella pagina, mai nei modal. @return list<string> */
    public static function errors(array $contact): array
    {
        return $contact === [] ? [(string) __t('account.errors.contact')] : [];
    }
}
