<?php

namespace Wonder\Plugin\CustomerAccount\Resources;

use InvalidArgumentException;
use Wonder\App\Resource;
use Wonder\App\ResourceSchema\{ApiSchema, FormField, NavigationSchema, PageSchema, PermissionSchema, TableColumn};
use Wonder\Backend\Support\QuickCreateStore;
use Wonder\Plugin\CustomerAccount\Models\OfficeType;
use Wonder\Plugin\CustomerAccount\Support\OfficeTypes;

/**
 * I tipi ufficio creati a mano. Non ha un menu: nascono dal «+» accanto a
 * «Tipo ufficio», sui recapiti e sui referenti (creazione rapida), e dal
 * loro elenco si rinominano. Non si eliminano: i recapiti che li usano
 * resterebbero con un tipo che non esiste più.
 *
 * Un nome che è già di un tipo di serie si rifiuta; uno già creato si riusa.
 *
 * Un modulo che usa altri ruoli la estende e cambia `permissionSchema()`.
 */
class OfficeTypeResource extends Resource implements QuickCreateStore
{
    public static string $model = OfficeType::class;
    public static string $orderColumn = 'name';
    public static string $orderDirection = 'ASC';

    public static function path(): string
    {
        return 'office-types';
    }

    public static function textSchema(): array
    {
        return ['label' => (string) __t('contacts.office_type'), 'plural_label' => (string) __t('contacts.office_types')];
    }

    public static function labelSchema(): array
    {
        return ['name' => (string) __t('contacts.office_type_name')];
    }

    public static function formSchema(): array
    {
        return [
            FormField::key('name')->text()->label(static::labelSchema()['name'])->required(),
        ];
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('name')->text()->link('edit')->formatter(static fn (array $row): string => htmlspecialchars(OfficeTypes::label((string) ($row['name'] ?? '')), ENT_QUOTES, 'UTF-8')),
            TableColumn::key('actions')->button()->actions(['edit']),
        ];
    }

    public static function pageSchema(): PageSchema
    {
        return PageSchema::for(static::class)->disable(['delete', 'view'])->redirect('store', 'list')->redirect('update', 'list');
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backendCrud(['admin', 'administrator']);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)->title(static::textSchema()['plural_label'])->enabled(false);
    }

    /** Passa solo il nome, e solo se non è già di un altro tipo. */
    public static function mutateRequestValues(array $values, string $action, string $context = 'backend', ?array $oldValues = null): array
    {
        $name = OfficeTypes::clean($values['name'] ?? '');

        if ($name === '') {
            throw new InvalidArgumentException((string) __t('contacts.office_type_required'));
        }

        if (OfficeTypes::builtIn($name) || OfficeTypes::idByName($name, (int) ($oldValues['id'] ?? 0)) > 0) {
            throw new InvalidArgumentException((string) __t('contacts.office_type_taken', ['name' => $name]));
        }

        return ['name' => $name];
    }

    /** Il «+» accanto a «Tipo ufficio»: un nome già creato riprende quel tipo. */
    public static function quickCreateStore(array $values, string $labelField): array
    {
        $name = OfficeTypes::clean($values['name'] ?? '');

        if ($name === '') {
            return ['error' => (string) __t('contacts.office_type_required')];
        }

        // Un tipo di serie non ha un id: si sceglie dall'elenco.
        if (OfficeTypes::builtIn($name)) {
            return ['error' => (string) __t('contacts.office_type_taken', ['name' => $name])];
        }

        $result = OfficeTypes::findOrCreate($name);

        if ($result === null) {
            return ['error' => (string) __t('contacts.office_type_not_created')];
        }

        return [
            'id' => $result['id'],
            'label' => $result['name'],
            'item' => ['id' => $result['id'], 'name' => $result['name']],
            'message' => $result['created'] ? '' : (string) __t('contacts.office_type_existing', ['name' => $result['name']]),
        ];
    }
}
