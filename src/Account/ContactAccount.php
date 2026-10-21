<?php

namespace Wonder\Plugin\CustomerAccount\Account;

use Wonder\Plugin\CustomerAccount\Models\Contact;

/** Links an account to its fiscal identity without requiring billing data. */
class ContactAccount
{
    /**
     * Collega l'utente alla sua scheda (prima per `user_id`, poi per email) o la crea.
     * Nome, cognome ed email della scheda diventano quelli dell'utente; il telefono solo se arriva pieno.
     */
    public static function link(int $userId, array $input = []): object
    {
        return self::write($userId, $input, true);
    }

    /**
     * Come `link()`, ma una scheda che esiste già tiene nome, cognome, email e telefono:
     * si scrivono solo `user_id`, `is_customer` e `active`. Per chi collega una scheda
     * compilata altrove (l'ordine da ospite). Senza scheda la crea con i dati dell'utente.
     */
    public static function linkKeepingIdentity(int $userId, array $input = []): object
    {
        return self::write($userId, $input, false);
    }

    private static function write(int $userId, array $input, bool $overwriteIdentity): object
    {
        $user = \infoUser($userId, 'id');
        if (!($user->exists ?? false)) {
            return (object) ['success' => false, 'reason' => 'user_not_found'];
        }

        $byUser = Contact::find(['user_id' => $userId], 1);
        $byEmail = Contact::find(['email' => (string) ($user->email ?? '')], 1);
        $contact = is_array($byUser) && !empty($byUser['id']) ? $byUser : $byEmail;
        $exists = is_array($contact) && !empty($contact['id']);

        if ($exists
            && (int) ($contact['user_id'] ?? 0) > 0
            && (int) $contact['user_id'] !== $userId) {
            return (object) ['success' => false, 'reason' => 'contact_link_conflict'];
        }

        $values = [
            'user_id' => $userId,
            'is_customer' => 'true',
            'active' => 'true',
        ];

        if (!$exists || $overwriteIdentity) {
            $values = [
                'name' => (string) ($user->name ?? ''),
                'surname' => (string) ($user->surname ?? ''),
                'email' => (string) ($user->email ?? ''),
            ] + $values;

            if (!empty($input['phone'])) {
                $values['phone_prefix'] = preg_replace('/[^0-9+]/', '', (string) ($input['phone_prefix'] ?? ''));
                $values['phone'] = preg_replace('/\D+/', '', (string) $input['phone']);
            }
        }

        $result = $exists
            ? Contact::update($values, (int) $contact['id'])
            : Contact::create($values);

        return (object) [
            'success' => (bool) ($result->success ?? false),
            'reason' => ($result->success ?? false) ? '' : 'contact_write_failed',
            'contact_id' => (int) ($contact['id'] ?? $result->insert_id ?? 0),
        ];
    }
}
