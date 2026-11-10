<?php

namespace Wonder\Plugin\CustomerAccount\Resources;

use InvalidArgumentException;
use Wonder\App\Resource;
use Wonder\App\ResourceSchema\{ApiSchema, FormField, NavigationSchema, PageSchema, PermissionSchema, RepeaterColumn, TableColumn};
use Wonder\Backend\Support\QuickCreateStore;
use Wonder\Elements\Components\{Card, SectionTitle};
use Wonder\Elements\Form\Form;
use Wonder\Plugin\CustomerAccount\Models\CompanyBrand;
use Wonder\Plugin\CustomerAccount\Support\BrandData;
use Wonder\Plugin\CustomerAccount\Support\CompanyBrands;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;

/**
 * I brand delle aziende. Non ha un menu: si scelgono e si creano dalla scheda
 * dell'azienda (creazione rapida). La creazione passa da
 * `CompanyBrands::findOrCreate()`: un nome che c'è già riprende quel brand.
 * Codice e slug nascono alla creazione e non cambiano; un brand si archivia
 * dal suo form (campo «Archiviato») e il form avvisa dei nomi simili.
 *
 * Il form ha due gruppi: i dati per tutti (logo, sito, dominio, descrizione)
 * e il «brand kit», che compare e si salva solo se `CompanyBrands::kitEnabled()`.
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
        $labels = [
            'name' => (string) __t('contacts.brand_name'),
            'note' => (string) __t('contacts.note'),
            'archived' => (string) __t('contacts.brand_archived'),
            'parent_id' => (string) __t('contacts.brand_parent'),
            'logo' => (string) __t('contacts.brand_logo'),
        ];

        foreach ([...BrandData::BASE_FIELDS, ...BrandData::KIT_FIELDS] as $field) {
            $labels[$field] = (string) __t('contacts.brand_'.$field);
        }

        return $labels;
    }
    public static function formSchema(): array
    {
        $yesNo = ['false' => (string) __t('contacts.no'), 'true' => (string) __t('contacts.yes')];
        $labels = static::labelSchema();
        $fields = [
            FormField::key('name')->text()->label($labels['name'])->required(),
            FormField::key('parent_id')->selectSearch(['' => '—'] + CompanyBrands::parentOptions())->label($labels['parent_id']),
            FormField::key('logo')->fileDragDrop('image')->label($labels['logo']),
            FormField::key('website')->text()->label($labels['website']),
            FormField::key('domain')->text()->label($labels['domain']),
            FormField::key('description')->textarea()->label($labels['description']),
            FormField::key('note')->textarea()->label($labels['note']),
            FormField::key('archived')->select($yesNo)->value('false')->label($labels['archived']),
        ];

        if (!CompanyBrands::kitEnabled()) {
            return $fields;
        }

        return [
            ...$fields,
            FormField::key('email')->email()->label($labels['email']),
            FormField::key('phone_prefix')->text()->label($labels['phone_prefix']),
            FormField::key('phone')->text()->label($labels['phone']),
            FormField::key('socials')
                ->repeater([
                    RepeaterColumn::key('network')->select(BrandData::socials())->label((string) __t('contacts.brand_social_network'))->columnSpan(4),
                    RepeaterColumn::key('url')->text()->label((string) __t('contacts.brand_social_url'))->columnSpan(8),
                ])
                ->nested()
                ->repeaterAddLabel((string) __t('contacts.brand_social_add'))
                ->label($labels['socials']),
            FormField::key('colors')->text()->label($labels['colors']),
            FormField::key('tone')->textarea()->label($labels['tone']),
            FormField::key('sector')->text()->label($labels['sector']),
            FormField::key('language')->text()->label($labels['language']),
            FormField::key('assets_url')->text()->label($labels['assets_url']),
        ];
    }
    /** Due gruppi: i dati del brand e, se acceso, il brand kit. */
    public static function formLayoutSchema(): ?Form
    {
        $widths = ['phone_prefix' => 3, 'phone' => 9, 'website' => 8, 'domain' => 4, 'sector' => 8, 'language' => 4];
        $kit = array_flip(BrandData::KIT_FIELDS);
        $groups = [[], []];

        foreach (static::formSchema() as $field) {
            $groups[isset($kit[$field->name]) ? 1 : 0][] = $field->columnSpan($widths[$field->name] ?? 12);
        }

        $cards = [(new Card)->columns(12)->columnSpan(12)->components([SectionTitle::make((string) __t('contacts.brand_group_base'))->columnSpan(12), ...$groups[0]])];

        if ($groups[1] !== []) {
            $cards[] = (new Card)->columns(12)->columnSpan(12)->components([
                SectionTitle::make((string) __t('contacts.brand_group_kit'))->tooltip((string) __t('contacts.brand_group_kit_tooltip'))->columnSpan(12),
                ...$groups[1],
            ]);
        }

        return (new Form)->columns(12)->components($cards);
    }
    /** Social e colori stanno in JSON: nel form tornano righe e testo. */
    public static function mutateFormValues(array $values, string $mode, string $context = 'backend'): array
    {
        foreach (['socials', 'colors'] as $field) {
            if (isset($values[$field]) && is_string($values[$field])) {
                $decoded = json_decode($values[$field], true);
                $values[$field] = is_array($decoded) ? $decoded : [];
            }
        }

        if (isset($values['socials']) && is_array($values['socials']) && !array_is_list($values['socials'])) {
            $rows = [];

            foreach ($values['socials'] as $network => $url) {
                $rows[] = ['network' => (string) $network, 'url' => (string) $url];
            }

            $values['socials'] = $rows;
        }

        if (isset($values['colors']) && is_array($values['colors'])) {
            $values['colors'] = implode(', ', array_filter($values['colors'], 'is_string'));
        }

        return parent::mutateFormValues($values, $mode, $context);
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
     * non si controlla. Il brand principale (`parent_id`) passa dalle regole
     * di `CompanyBrands::parentProblem()`: un livello solo. I dati del brand
     * passano dalle regole di `BrandData` (URL solo http/https, colori
     * esadecimali, social della lista); il kit solo se è acceso. Il logo passa
     * solo come file caricato: il framework ne controlla estensione e
     * dimensione e ripulisce un SVG.
     */
    public static function mutateRequestValues(array $values, string $action, string $context = 'backend', ?array $oldValues = null): array
    {
        $upload = $values['logo'] ?? null;
        $manifest = $values['logo__wi_files'] ?? null;
        [$data, $problems] = BrandData::clean($values, CompanyBrands::kitEnabled());

        if ($problems !== []) {
            $labels = static::labelSchema();
            throw new InvalidArgumentException(implode("\n", array_map(
                static fn (string $field): string => (string) __t('contacts.brand_invalid', ['field' => $labels[$field] ?? $field]),
                array_keys($problems)
            )));
        }

        $values = $data + array_intersect_key($values, array_flip(['name', 'note', 'archived', 'parent_id']));

        // Un file o il suo ordine, mai un testo: un percorso o un URL scritti a mano non diventano il logo.
        if (is_array($upload) && isset($upload['name'])) {
            $values['logo'] = $upload;
        }

        if (is_string($manifest)) {
            $values['logo__wi_files'] = $manifest;
        }

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

        if (array_key_exists('parent_id', $values)) {
            $raw = $values['parent_id'];
            $parentId = is_scalar($raw) && preg_match('/^\d*$/', trim((string) $raw)) === 1 ? (int) $raw : -1;
            $problem = $parentId < 0 ? 'missing' : CompanyBrands::parentProblem($id, $parentId);

            if ($problem !== '') {
                throw new InvalidArgumentException((string) __t('contacts.brand_parent_'.$problem));
            }

            $values['parent_id'] = $parentId;
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
