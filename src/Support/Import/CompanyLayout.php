<?php

namespace Wonder\Plugin\CustomerAccount\Support\Import;

use Wonder\Plugin\CustomerAccount\Models\Company;
use Wonder\Plugin\CustomerAccount\Resources\CompanyResource;
use Wonder\Plugin\CustomerAccount\Support\CompanyEvents;
use Wonder\Plugin\CustomerAccount\Support\ContactPoints;

/** Le aziende della rubrica: riconosciute per ID, codice, partita IVA. La capogruppo resta fuori. */
class CompanyLayout extends PartyLayout
{
    public const KEY = 'contacts_companies';

    public static function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->text('companies');
    }

    protected function resource(): string
    {
        return CompanyResource::class;
    }

    protected function baseModel(): string
    {
        return Company::class;
    }

    protected function owner(): string
    {
        return ContactPoints::COMPANY;
    }

    protected function target(): string
    {
        return 'company';
    }

    protected function identifiers(): array
    {
        return ['pi'];
    }

    protected function flags(): array
    {
        return ['is_customer', 'is_supplier'];
    }

    protected function defaultCountry(): ?string
    {
        return 'IT';
    }

    protected function event(string $what): array
    {
        return [$what === 'created' ? CompanyEvents::CREATED : CompanyEvents::UPDATED, 'company'];
    }
}
