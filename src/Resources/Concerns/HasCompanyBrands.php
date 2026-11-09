<?php

namespace Wonder\Plugin\CustomerAccount\Resources\Concerns;

use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\Input;
use Wonder\App\ResourceSchema\RepeaterColumn;
use Wonder\Plugin\CustomerAccount\Models\CompanyBrandLink;
use Wonder\Plugin\CustomerAccount\Resources\CompanyBrandResource;
use Wonder\Plugin\CustomerAccount\Support\CompanyBrands;

/**
 * Il campo «Brand» di una Resource delle aziende: un repeater con il brand
 * (select cercabile con il «+» per crearne uno al volo), il ruolo
 * dell'azienda verso il brand e il predefinito.
 *
 * Il valore non è una colonna e il repeater non ha una `RepeaterRelation`:
 * il sync del core non lo tocca. Si legge da `CompanyBrands::linksOf()` in
 * `mutateFormValues()` e si scrive in `syncRepeaterRelations()` con
 * `CompanyBrands::sync()`, solo se il form l'ha mostrato (campo
 * `company_brands_form`), così un repeater vuoto stacca tutto.
 * La classe deve usare anche `HasContactRelations` o estendere una Resource
 * che li ha. Porta con sé `HasCompanyGroup`: la capogruppo postata si
 * valida sempre, anche dove il form non mostra il campo.
 */
trait HasCompanyBrands
{
    use HasCompanyGroup;

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
        $yesNo = ['false' => (string) __t('contacts.no'), 'true' => (string) __t('contacts.yes')];

        return [
            FormField::key(static::$brandsInput)
                ->repeater([
                    RepeaterColumn::key('brand_id')
                        ->selectSearch(['' => '—'] + CompanyBrands::options())
                        ->quickCreate(static::brandResource(), ['name'], null, 'name', (string) __t('contacts.brand_add'))
                        ->label((string) __t('contacts.brand'))
                        ->columnSpan(6),
                    RepeaterColumn::key('role')
                        ->select(CompanyBrands::roles())
                        ->value(CompanyBrandLink::ROLE_OWNER)
                        ->label((string) __t('contacts.brand_role'))
                        ->columnSpan(3),
                    RepeaterColumn::key('is_default')
                        ->select($yesNo)
                        ->value('false')
                        ->label((string) __t('contacts.brand_is_default'))
                        ->columnSpan(3),
                ])
                ->nested()
                ->repeaterSortable()
                ->repeaterAddLabel((string) __t('contacts.brand_add_row'))
                ->label((string) __t('contacts.brands_label')),
            FormField::key(static::$brandsMarker)->hidden()->value('1'),
        ];
    }

    /** Aggiunge al form i brand legati, con ruolo e predefinito. */
    public static function withBrandValues(array $values): array
    {
        $id = (int) ($values['id'] ?? 0);

        if ($id > 0 && !array_key_exists(static::$brandsInput, $values)) {
            $values[static::$brandsInput] = array_map(static fn (array $link): array => [
                'brand_id' => $link['brand_id'],
                'role' => $link['role'],
                'is_default' => $link['is_default'] ? 'true' : 'false',
            ], CompanyBrands::linksOf($id));
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
            $items = [];

            // Righe del repeater o, da codice, una lista di id: passano solo le chiavi note.
            foreach (is_array($posted) ? $posted : [$posted] as $item) {
                $items[] = is_array($item) ? array_intersect_key($item, array_flip(['brand_id', 'role', 'is_default'])) : $item;
            }

            $summary[static::$brandsInput] = CompanyBrands::sync((int) $parentId, $items);
        }

        return $summary;
    }
}
