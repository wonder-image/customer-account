<?php

namespace Wonder\Plugin\CustomerAccount\Resources;

use InvalidArgumentException;
use Wonder\App\Resource;
use Wonder\App\ResourceSchema\{ApiSchema, FormField, NavigationSchema, PageSchema, PermissionSchema, TableColumn};
use Wonder\Backend\Support\QuickCreateStore;
use Wonder\Plugin\CustomerAccount\Models\CompanyBrand;
use Wonder\Plugin\CustomerAccount\Support\CompanyBrands;

/**
 * I brand delle aziende. Non ha un menu: si scelgono e si creano dalla scheda
 * dell'azienda (creazione rapida). La creazione passa da
 * `CompanyBrands::findOrCreate()`: un nome che c'è già riprende quel brand.
 *
 * Un modulo che usa altri ruoli la estende e cambia `permissionSchema()`.
 */
class CompanyBrandResource extends Resource implements QuickCreateStore
{
    public static string $model = CompanyBrand::class;
    public static string $orderColumn = 'name';
    public static string $orderDirection = 'ASC';

    public static function path(): string { return 'company-brands'; }
    public static function textSchema(): array
    {
        return ['label' => (string) __t('contacts.brand'), 'plural_label' => (string) __t('contacts.brands')];
    }
    public static function labelSchema(): array
    {
        return ['name' => (string) __t('contacts.brand_name'), 'note' => (string) __t('contacts.note')];
    }
    public static function formSchema(): array
    {
        return [
            FormField::key('name')->text()->label(static::labelSchema()['name'])->required(),
            FormField::key('note')->textarea()->label(static::labelSchema()['note']),
        ];
    }
    public static function tableSchema(): array
    {
        return [
            TableColumn::key('name')->text()->link('edit'),
            TableColumn::key('actions')->button()->actions(['edit']),
        ];
    }
    public static function pageSchema(): PageSchema
    {
        return PageSchema::for(static::class)->disable(['delete', 'view'])->redirect('store', 'list')->redirect('update', 'list');
    }
    public static function apiSchema(): ApiSchema { return ApiSchema::for(static::class)->enabled(false); }
    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backendCrud(['admin', 'administrator']);
    }
    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)->title(static::textSchema()['plural_label'])->enabled(false);
    }
    public static function mutateRequestValues(array $values, string $action, string $context = 'backend', ?array $oldValues = null): array
    {
        $values = array_intersect_key($values, static::labelSchema());
        $name = trim((string) ($values['name'] ?? ''));

        if ($name === '') {
            throw new InvalidArgumentException((string) __t('contacts.brand_required'));
        }

        $same = CompanyBrands::byName($name);

        if ($same !== null && (int) $same['id'] !== (int) ($oldValues['id'] ?? 0)) {
            throw new InvalidArgumentException((string) __t('contacts.brand_taken', ['name' => CompanyBrands::label($same)]));
        }

        return $values;
    }

    /** Il «+» della scheda azienda: un nome che c'è già riprende quel brand. */
    public static function quickCreateStore(array $values, string $labelField): array
    {
        $result = CompanyBrands::findOrCreate((string) ($values['name'] ?? ''));

        if ($result === null) {
            return ['error' => (string) __t('contacts.brand_not_created')];
        }

        $label = CompanyBrands::label($result['brand']);

        return [
            'id' => $result['id'],
            'label' => $label,
            'item' => ['id' => $result['id'], 'name' => $label],
            'message' => $result['created'] ? '' : (string) __t('contacts.brand_existing', ['name' => $label]),
        ];
    }
}
