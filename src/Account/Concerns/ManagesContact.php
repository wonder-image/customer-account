<?php

namespace Wonder\Plugin\CustomerAccount\Account\Concerns;

use Wonder\Plugin\CustomerAccount\Account\CustomerContact;

/** Per i controller del pannello che lavorano sulla scheda cliente dell'utente (customer-account, ecommerce). */
trait ManagesContact
{
    protected function contact(int $userId): array
    {
        return CustomerContact::forUser($userId);
    }

    /** @return list<string> */
    protected function contactErrors(array $contact): array
    {
        return CustomerContact::errors($contact);
    }
}
