<?php

namespace Wonder\Plugin\CustomerAccount\Resources;

use InvalidArgumentException;
use Wonder\App\Resource;
use Wonder\App\ResourceSchema\{ApiSchema, FormField, NavigationSchema, PageSchema, PermissionSchema, TableColumn};
use Wonder\Backend\Support\QuickCreateStore;
use Wonder\Plugin\CustomerAccount\Models\CompanyBrand;
use Wonder\Plugin\CustomerAccount\Support\CompanyBrands;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;

/**
 * I brand delle aziende. Non ha un menu: si scelgono e si creano dalla scheda
 * dell'azienda (creazione rapida). La creazione passa da
 * `CompanyBrands::findOrCreate()`: un nome che c'è già riprende quel brand.
 * Codice e slug nascono alla creazione e non cambiano; un brand si archivia
 * dal suo form (campo «Archiviato») e il form avvisa dei nomi simili.
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
        return [
            'name' => (string) __t('contacts.brand_name'),
            'note' => (string) __t('contacts.note'),
            'archived' => (string) __t('contacts.brand_archived'),
        ];
    }
    public static function formSchema(): array
    {
        $yesNo = ['false' => (string) __t('contacts.no'), 'true' => (string) __t('contacts.yes')];

        return [
            FormField::key('name')->text()->label(static::labelSchema()['name'])->required(),
            FormField::key('note')->textarea()->label(static::labelSchema()['note']),
            FormField::key('archived')->select($yesNo)->value('false')->label(static::labelSchema()['archived']),
        ];
    }
    public static function tableSchema(): array
    {
        return [
            // La cella finisce nell'HTML così com'è: il nome creato da codice non passa da sanitize().
            TableColumn::key('name')->text()->link('edit')->formatter(static fn (array $row): string => htmlspecialchars(CompanyBrands::label($row), ENT_QUOTES, 'UTF-8')),
            TableColumn::key('code')->text(),
            TableColumn::key('archived')
                ->booleanBadge()
                ->badgeOn((string) __t('contacts.brand_archived'), 'bi bi-archive', 'secondary')
                ->badgeOff((string) __t('contacts.brand_in_use'), 'bi bi-check2', 'success')
                ->size('little'),
            TableColumn::key('actions')->button()->actions(['edit']),
        ];
    }
    /** Dopo la creazione si torna sulla scheda: lì l'avviso dei brand simili. */
    public static function pageSchema(): PageSchema
    {
        return PageSchema::for(static::class)->disable(['delete', 'view'])->redirect('store', 'edit')->redirect('update', 'list');
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
    /**
     * Passano solo nome, note e archiviazione. Lo slug nasce dal nome alla
     * creazione (univoco, con `-1`, `-2`…), il codice lo dà il framework
     * (`uniqueCode`); `name_key` segue il nome. Un nome identico a quello di
     * un altro brand non archiviato si rifiuta, ma solo in creazione o se il
     * nome cambia: un doppione storico (o il brand archiviato da `merge()`)
     * si può ancora correggere e archiviare. Un brand che resta archiviato
     * non si controlla.
     */
    public static function mutateRequestValues(array $values, string $action, string $context = 'backend', ?array $oldValues = null): array
    {
        $values = array_intersect_key($values, static::labelSchema());
        $name = trim((string) ($values['name'] ?? ''));
        $id = (int) ($oldValues['id'] ?? 0);

        if ($name === '') {
            throw new InvalidArgumentException((string) __t('contacts.brand_required'));
        }

        $values['archived'] = $action === 'update' ? ContactRelations::flag($values['archived'] ?? ($oldValues['archived'] ?? 'false')) : 'false';
        $renamed = $action !== 'update' || !CompanyBrands::sameName($name, (string) ($oldValues['name'] ?? ''));
        $same = $renamed && $values['archived'] === 'false' ? CompanyBrands::byName($name) : null;

        if ($same !== null && (int) $same['id'] !== $id) {
            throw new InvalidArgumentException((string) __t('contacts.brand_taken', ['name' => CompanyBrands::label($same)]));
        }

        $values['name_key'] = CompanyBrands::nameKey($name);

        if ($action === 'store') {
            $values['slug'] = CompanyBrands::slugFor($name);
        }

        return $values;
    }

    /** Avvisa dei brand con un nome quasi uguale: probabili doppioni da unire. */
    public static function formNotices(array $values, string $mode): array
    {
        $similar = CompanyBrands::similar((string) ($values['name'] ?? ''), (int) ($values['id'] ?? 0));

        if ($similar === []) {
            return [];
        }

        return [[
            'level' => 'warning',
            'message' => (string) __t('contacts.brand_similar', ['names' => self::names($similar)]),
        ]];
    }

    /** Il «+» della scheda azienda: un nome che c'è già riprende quel brand. */
    public static function quickCreateStore(array $values, string $labelField): array
    {
        $result = CompanyBrands::findOrCreate((string) ($values['name'] ?? ''));

        if ($result === null) {
            return ['error' => (string) __t('contacts.brand_not_created')];
        }

        $label = CompanyBrands::label($result['brand']);
        $similar = $result['created'] ? CompanyBrands::similar($label, $result['id']) : [];
        $message = match (true) {
            !$result['created'] => (string) __t('contacts.brand_existing', ['name' => $label]),
            $similar !== [] => (string) __t('contacts.brand_similar', ['names' => self::names($similar)]),
            default => '',
        };

        // Il messaggio va in un toast che il framework escapa: qui resta testo.
        return [
            'id' => $result['id'],
            'label' => $label,
            'item' => ['id' => $result['id'], 'name' => $label],
            'message' => $message,
        ];
    }

    /** @param list<array<string, mixed>> $brands */
    private static function names(array $brands): string
    {
        return implode(', ', array_map(
            static fn (array $brand): string => CompanyBrands::label($brand).(($brand['code'] ?? '') !== '' ? ' ('.$brand['code'].')' : ''),
            $brands
        ));
    }
}
