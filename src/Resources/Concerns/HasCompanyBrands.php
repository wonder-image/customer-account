<?php

namespace Wonder\Plugin\CustomerAccount\Resources\Concerns;

use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\Input;
use Wonder\Plugin\CustomerAccount\Resources\CompanyBrandResource;
use Wonder\Plugin\CustomerAccount\Support\CompanyBrands;

/**
 * Il campo «Brand» di una Resource delle aziende: select cercabile multiplo,
 * con il «+» per crearne uno al volo.
 *
 * Il valore non è una colonna: si legge dal legame in `mutateFormValues()` e
 * si scrive in `syncRepeaterRelations()`, solo se il form l'ha mostrato
 * (campo `company_brands_form`), così una selezione vuota stacca tutto.
 * La classe deve usare anche `HasContactRelations` o estendere una Resource
 * che li ha.
 */
trait HasCompanyBrands
{
    public static string $brandsInput = 'brands';
    public static string $brandsMarker = 'company_brands_form';

    /** La Resource del «+»: un modulo la cambia per i suoi permessi. */
    public static function brandResource(): string
    {
        return CompanyBrandResource::class;
    }

    /** @return list<Input> */
    public static function brandFields(): array
    {
        return [
            FormField::key(static::$brandsInput)
                ->selectSearch(CompanyBrands::options(), true)
                ->quickCreate(static::brandResource(), ['name'], null, 'name', (string) __t('contacts.brand_add'))
                ->label((string) __t('contacts.brands_label')),
            FormField::key(static::$brandsMarker)->hidden()->value('1'),
        ];
    }

    /** Aggiunge al form i brand già legati. */
    public static function withBrandValues(array $values): array
    {
        $id = (int) ($values['id'] ?? 0);

        if ($id > 0 && !array_key_exists(static::$brandsInput, $values)) {
            $values[static::$brandsInput] = CompanyBrands::idsOf($id);
        }

        return $values;
    }

    public static function stripRelationInputValues(array $values): array
    {
        unset($values[static::$brandsInput], $values[static::$brandsMarker]);

        return parent::stripRelationInputValues($values);
    }

    public static function syncRepeaterRelations(
        int|string $parentId,
        array $post,
        array $files = [],
        string $action = 'store',
        string $context = 'backend'
    ): array {
        $summary = parent::syncRepeaterRelations($parentId, $post, $files, $action, $context);

        if (isset($post[static::$brandsMarker])) {
            $posted = $post[static::$brandsInput] ?? [];
            $summary[static::$brandsInput] = CompanyBrands::sync((int) $parentId, is_array($posted) ? $posted : [$posted]);
        }

        return $summary;
    }
}
