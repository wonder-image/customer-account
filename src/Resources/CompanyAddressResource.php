<?php

namespace Wonder\Plugin\CustomerAccount\Resources;

use Wonder\Plugin\CustomerAccount\Models\{Company, CompanyAddress};

/** Gli indirizzi di spedizione di un'azienda. */
class CompanyAddressResource extends ContactAddressResource
{
    public static string $model = CompanyAddress::class;
    public static function path(): string { return 'company-addresses'; }
    public static function parentModel(): string { return Company::class; }
    public static function parentKey(): string { return 'company_id'; }
    protected static function parentResourceLabel(): string { return (string) __t('contacts.company'); }
}
