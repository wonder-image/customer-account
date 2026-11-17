<?php

namespace Wonder\Plugin\CustomerAccount\Support\Import;

use Wonder\Plugin\CustomerAccount\Models\Contact;
use Wonder\Plugin\CustomerAccount\Resources\ContactResource;
use Wonder\Plugin\CustomerAccount\Support\ContactEvents;
use Wonder\Plugin\CustomerAccount\Support\ContactPoints;

/** Le persone della rubrica: riconosciute per ID, codice, codice fiscale, email. */
class PeopleLayout extends PartyLayout
{
    public const KEY = 'contacts_people';

    public static function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->text('people');
    }

    protected function resource(): string
    {
        return ContactResource::class;
    }

    protected function baseModel(): string
    {
        return Contact::class;
    }

    protected function owner(): string
    {
        return ContactPoints::CONTACT;
    }

    protected function target(): string
    {
        return 'contact';
    }

    protected function identifiers(): array
    {
        return ['cf', 'email'];
    }

    protected function event(string $what): array
    {
        return [$what === 'created' ? ContactEvents::CREATED : ContactEvents::UPDATED, 'contact'];
    }
}
