<?php

namespace Wonder\Plugin\CustomerAccount\Resources;

use Wonder\App\ResourceSchema\FormField;
use Wonder\Plugin\CustomerAccount\Models\Company;
use Wonder\Plugin\CustomerAccount\Resources\Concerns\HasCompanyBrands;
use Wonder\Plugin\CustomerAccount\Support\CompanyEvents;
use Wonder\Plugin\CustomerAccount\Support\ContactExport;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;

/** Le aziende della rubrica: clienti, fornitori, con i loro brand e referenti. */
class CompanyResource extends ContactResource
{
    use HasCompanyBrands;

    public static string $model = Company::class;

    public static function path(): string { return 'companies'; }
    public static function relationSide(): string { return ContactRelations::COMPANY; }
    public static function textSchema(): array
    {
        return ['label' => (string) __t('contacts.company'), 'plural_label' => (string) __t('contacts.companies')];
    }
    public static function labelSchema(): array
    {
        return parent::labelSchema() + [
            'is_customer' => (string) __t('contacts.is_customer'),
            'is_supplier' => (string) __t('contacts.is_supplier'),
        ];
    }
    /** Il brand è la prima cosa che chiede. */
    public static function formSchema(): array
    {
        return [...static::brandFields(), ...parent::formSchema()];
    }
    public static function mutateFormValues(array $values, string $mode, string $context = 'backend'): array
    {
        return static::withBrandValues(parent::mutateFormValues($values, $mode, $context));
    }
    public static function requiredKeys(): array { return ['business_name']; }
    public static function searchKeys(): array { return ['business_name', 'pi', 'cf', 'email', 'city']; }
    public static function exportFields(): array { return ContactExport::COMPANY_FIELDS; }
    public static function addressesPath(): array { return ['company-addresses', 'company_id']; }
    protected static function extraFormFields(): array
    {
        $options = ['true' => (string) __t('contacts.yes'), 'false' => (string) __t('contacts.no')];
        return [
            FormField::key('is_customer')->select($options)->value('true')->required(),
            FormField::key('is_supplier')->select($options)->value('false')->required(),
        ];
    }
    protected static function contactEvent(string $what): array
    {
        return [$what === 'created' ? CompanyEvents::CREATED : CompanyEvents::UPDATED, 'company'];
    }
}
