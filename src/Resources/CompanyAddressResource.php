<?php

namespace Wonder\Plugin\CustomerAccount\Resources;

use Wonder\App\ResourceSchema\FormField;
use Wonder\Plugin\CustomerAccount\Models\{Company, CompanyAddress};
use Wonder\Plugin\CustomerAccount\Support\{CompanyAddresses, CompanyBrands};

/**
 * Gli indirizzi di spedizione di un'azienda, con i brand per cui valgono
 * (vuoto = tutti). I brand non sono una colonna: si leggono in
 * `mutateFormValues()` e si scrivono con `CompanyAddresses::setBrands()`, solo
 * se il form li ha mostrati (campo `address_brands_form`).
 */
class CompanyAddressResource extends ContactAddressResource
{
    public const BRANDS_INPUT = 'brand_ids';
    public const BRANDS_MARKER = 'address_brands_form';

    public static string $model = CompanyAddress::class;
    public static function path(): string { return 'company-addresses'; }
    public static function parentModel(): string { return Company::class; }
    public static function parentKey(): string { return 'company_id'; }
    protected static function parentResourceLabel(): string { return (string) __t('contacts.company'); }

    /** Le scelte sono tutti i brand: il form non sa quale azienda mostra, il salvataggio tiene solo i suoi. */
    public static function formSchema(): array
    {
        return [
            ...parent::formSchema(),
            FormField::key(self::BRANDS_INPUT)->selectSearch(CompanyBrands::options(), true)->label((string) __t('contacts.address_brands')),
            FormField::key(self::BRANDS_MARKER)->hidden()->value('1'),
        ];
    }

    public static function mutateFormValues(array $values, string $mode, string $context = 'backend'): array
    {
        $id = (int) ($values['id'] ?? 0);

        if ($id > 0 && !array_key_exists(self::BRANDS_INPUT, $values)) {
            $values[self::BRANDS_INPUT] = CompanyAddresses::brandsOf($id);
        }

        return parent::mutateFormValues($values, $mode, $context);
    }

    public static function syncRepeaterRelations(
        int|string $parentId,
        array $post,
        array $files = [],
        string $action = 'store',
        string $context = 'backend'
    ): array {
        $summary = parent::syncRepeaterRelations($parentId, $post, $files, $action, $context);

        if (isset($post[self::BRANDS_MARKER])) {
            $posted = $post[self::BRANDS_INPUT] ?? [];
            $summary[self::BRANDS_INPUT] = CompanyAddresses::setBrands((int) $parentId, is_array($posted) ? $posted : [$posted]);
        }

        return $summary;
    }
}
