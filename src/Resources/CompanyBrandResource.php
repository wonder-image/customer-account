<?php

namespace Wonder\Plugin\CustomerAccount\Resources;

use InvalidArgumentException;
use Wonder\App\Resource;
use Wonder\App\ResourceSchema\{ApiSchema, FormField, NavigationSchema, PageSchema, PermissionSchema, RepeaterColumn, TableColumn};
use Wonder\Backend\Support\QuickCreateStore;
use Wonder\Elements\Components\{Card, Container, SectionTitle};
use Wonder\Elements\Form\Form;
use Wonder\Plugin\CustomerAccount\Models\CompanyBrand;
use Wonder\Plugin\CustomerAccount\Support\BrandData;
use Wonder\Plugin\CustomerAccount\Support\CompanyBrands;
use Wonder\Plugin\CustomerAccount\Support\ContactPoints;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;
use Wonder\Plugin\CustomerAccount\Support\Crm;

/**
 * I brand delle aziende. Non ha un menu: si scelgono e si creano dalla scheda
 * dell'azienda (creazione rapida). La creazione passa da
 * `CompanyBrands::findOrCreate()`: un nome che c'è già riprende quel brand.
 * Codice e slug nascono alla creazione e non cambiano; un brand si archivia
 * dal suo form (campo «Archiviato») e il form avvisa dei nomi simili.
 *
 * Il form ha due gruppi: i dati per tutti (in testa il logo accanto a nome,
 * gruppo, sito e archiviato, poi domini email e descrizione) e il «brand kit»
 * (recapiti con il loro ufficio, social, colori con il loro nome, tono,
 * settore, lingua, materiali), che compare e si salva solo se
 * `CompanyBrands::kitEnabled()`. Con il CRM spento (`Crm::enabled()`) il kit
 * mostra un'email e un telefono soli e nessun social: i recapiti in più e i
 * social già salvati restano dove sono.
 * Liste e scelte passano da `BrandData`: settori ATECO / NACE, lingue ISO.
 *
 * Un modulo che usa altri ruoli la estende e cambia `permissionSchema()`.
 */
class CompanyBrandResource extends Resource implements QuickCreateStore
{
    public static string $model = CompanyBrand::class;
    public static string $orderColumn = 'name';
    public static string $orderDirection = 'ASC';

    public static function path(): string { return 'company-brands'; }

    /** La Resource del «+» accanto a «Ufficio»: un modulo la cambia per i suoi permessi. */
    public static function officeTypeResource(): string
    {
        return OfficeTypeResource::class;
    }
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
            FormField::key('parent_id')->selectSearch(['' => (string) __t('contacts.option_none')] + CompanyBrands::parentOptions())->label($labels['parent_id']),
            FormField::key('logo')->fileDragDrop('image')->label($labels['logo']),
            FormField::key('website')->url()->label($labels['website']),
            FormField::key('domains')
                ->repeater([
                    RepeaterColumn::key('domain')->text()->placeholder('lapiana.it')->label((string) __t('contacts.brand_domain_row'))->columnFill(),
                ])
                ->nested()
                ->repeaterAddLabel((string) __t('contacts.brand_domain_add'))
                ->label($labels['domains']),
            FormField::key('description')->textarea()->label($labels['description']),
            FormField::key('note')->textarea()->label($labels['note']),
            FormField::key('archived')->select($yesNo)->value('false')->label($labels['archived']),
        ];

        $kit = CompanyBrands::kitEnabled();

        if (!$kit && !Crm::enabled()) {
            return $fields;
        }

        $primary = [
            FormField::key('email')->email()->label($labels['email']),
            FormField::key('phone_prefix')->phonePrefix()->label($labels['phone_prefix']),
            FormField::key('phone')->tel()->label($labels['phone']),
        ];

        if (Crm::enabled()) {
            $primary = [
                FormField::key('contacts')
                    ->repeater([
                        RepeaterColumn::key('kind')->select(ContactPoints::kindOptions())->value(ContactPoints::EMAIL)->label((string) __t('contacts.point_kind'))->columnSpan(3),
                        RepeaterColumn::key('email')->email()->label((string) __t('contacts.point_kind_email'))->columnFill()
                            ->visibleWhen('kind', ContactPoints::EMAIL),
                        RepeaterColumn::key('phone_prefix')->phonePrefix()->label((string) __t('contacts.point_prefix'))->columnSpan(2)
                            ->visibleWhen('kind', ContactPoints::PHONE_KINDS),
                        RepeaterColumn::key('phone')->tel()->label((string) __t('contacts.point_kind_phone'))->columnFill()
                            ->visibleWhen('kind', ContactPoints::PHONE_KINDS),
                        // Un ufficio solo: il recapito di un brand è una voce del kit, non un filtro per i documenti.
                        RepeaterColumn::key('label')
                            ->selectSearch(ContactRelations::departments())
                            ->value(ContactRelations::GENERAL)
                            ->quickCreate(static::officeTypeResource(), ['name'], null, 'name', (string) __t('contacts.office_type_add'))
                            ->quickCreateCompact()
                            ->label((string) __t('contacts.office_type'))
                            ->columnSpan(3),
                    ])
                    ->nested()
                    ->repeaterAddLabel((string) __t('contacts.point_add'))
                    ->label($labels['contacts']),
                FormField::key('socials')
                    ->repeater([
                        RepeaterColumn::key('network')->select(BrandData::socials())->label((string) __t('contacts.brand_social_network'))->columnSpan(4),
                        RepeaterColumn::key('url')->url()->label((string) __t('contacts.brand_social_url'))->columnFill(),
                    ])
                    ->nested()
                    ->repeaterAddLabel((string) __t('contacts.brand_social_add'))
                    ->label($labels['socials']),
            ];
        }

        // Il CRM senza il kit: solo recapiti e social.
        if (!$kit) {
            return [...$fields, ...$primary];
        }

        return [
            ...$fields,
            ...$primary,
            FormField::key('colors')
                ->repeater([
                    RepeaterColumn::key('color')->color()->label((string) __t('contacts.brand_color'))->columnSpan(4),
                    RepeaterColumn::key('name')->text()->placeholder((string) __t('contacts.brand_color_name_placeholder'))->label((string) __t('contacts.brand_color_name'))->columnFill(),
                ])
                ->nested()
                ->repeaterSortable()
                ->repeaterAddLabel((string) __t('contacts.brand_color_add'))
                ->label($labels['colors']),
            FormField::key('tone')->textarea()->label($labels['tone']),
            FormField::key('sector')->selectSearch(['' => (string) __t('contacts.option_none')] + BrandData::sectors())->label($labels['sector']),
            FormField::key('language')->selectSearch(BrandData::languages())->value(BrandData::DEFAULT_LANGUAGE)->label($labels['language']),
            FormField::key('assets_url')->url()->label($labels['assets_url']),
        ];
    }

    /**
     * Due gruppi: i dati del brand e, se acceso, il brand kit. La testata è
     * come quella del marchio del catalogo: il logo a sinistra, nome, brand
     * principale, sito e archiviazione accanto; sotto il resto. Un modulo
     * aggiunge i suoi riquadri con `extraFormFields()` e `extraFormCards()`.
     */
    public static function formLayoutSchema(): ?Form
    {
        $head = ['name', 'parent_id', 'website', 'archived'];
        $widths = ['sector' => 8, 'language' => 4, 'email' => 6, 'phone_prefix' => 2, 'phone' => 4];
        $extra = static::extraFormFields();
        $kit = array_flip(BrandData::KIT_FIELDS);
        $fields = [];
        $groups = [[], []];

        foreach (static::formSchema() as $field) {
            $fields[$field->name] = $field;
        }

        foreach ($fields as $name => $field) {
            if ($name !== 'logo' && !in_array($name, $head, true) && !in_array($name, $extra, true)) {
                $groups[isset($kit[$name]) ? 1 : 0][] = $field->columnSpan($widths[$name] ?? 12);
            }
        }

        $cards = [(new Card)->columns(12)->columnSpan(12)->components([
            SectionTitle::make((string) __t('contacts.brand_group_base'))->columnSpan(12),
            $fields['logo']->columnSpan(4),
            (new Container)->components(array_map(
                static fn (string $name) => $fields[$name]->columnSpan(12),
                array_values(array_filter($head, static fn (string $name): bool => isset($fields[$name])))
            ))->columns(12)->columnSpan(8),
            ...$groups[0],
        ])];

        if ($groups[1] !== []) {
            $cards[] = (new Card)->columns(12)->columnSpan(12)->components([
                CompanyBrands::kitEnabled()
                    ? SectionTitle::make((string) __t('contacts.brand_group_kit'))->tooltip((string) __t('contacts.brand_group_kit_tooltip'))->columnSpan(12)
                    : SectionTitle::make((string) __t('contacts.brand_group_crm'))->columnSpan(12),
                ...$groups[1],
            ]);
        }

        return (new Form)->columns(12)->components([...$cards, ...static::extraFormCards($fields)]);
    }

    /**
     * I campi di `formSchema()` che non vanno nei due gruppi: li dispone `extraFormCards()`.
     *
     * @return list<string>
     */
    protected static function extraFormFields(): array
    {
        return [];
    }

    /**
     * I riquadri in più di un modulo, dopo i dati del brand e il kit.
     *
     * @param array<string, mixed> $fields i campi del form per nome
     * @return list<Card>
     */
    protected static function extraFormCards(array $fields): array
    {
        return [];
    }

    /** Liste in JSON: nel form tornano righe del repeater. */
    public static function mutateFormValues(array $values, string $mode, string $context = 'backend'): array
    {
        $values['domains'] = array_map(static fn (string $domain): array => ['domain' => $domain], BrandData::domainsOf($values));
        $values['contacts'] = BrandData::contactsOf($values);
        $offices = ContactRelations::departments();

        // Il select dell'ufficio non ha una voce vuota: un recapito senza ufficio (o con un'etichetta di prima) è «Generale».
        foreach ($values['contacts'] as $index => $row) {
            if (!isset($offices[(string) ($row['label'] ?? '')])) {
                $values['contacts'][$index]['label'] = ContactRelations::GENERAL;
            }
        }

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

        // Righe colore + nome; vale anche la lista di soli esadecimali di prima.
        if (isset($values['colors']) && is_array($values['colors'])) {
            $values['colors'] = BrandData::palette($values['colors']) ?? [];
        }

        // Una lingua di prima con il paese (`en-GB`) si mostra con la sua lingua (`en`).
        $language = strtolower(substr(trim((string) ($values['language'] ?? '')), 0, 2));

        if ($language !== '' && isset(BrandData::languages()[$language])) {
            $values['language'] = $language;
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
     * esadecimali, social della lista); il kit solo se è acceso. Con il CRM
     * spento l'email e il telefono del form sono la prima email e il primo
     * telefono dei recapiti: gli altri recapiti e i social salvati restano. Il logo passa
     * solo come file caricato: il framework ne controlla estensione e
     * dimensione e ripulisce un SVG.
     */
    public static function mutateRequestValues(array $values, string $action, string $context = 'backend', ?array $oldValues = null): array
    {
        $upload = $values['logo'] ?? null;
        $manifest = $values['logo__wi_files'] ?? null;
        $kit = CompanyBrands::kitEnabled();
        $crm = Crm::enabled();
        $primaryOnly = $kit && !$crm;

        if ($primaryOnly) {
            $values = static::primaryContacts($values, $oldValues ?? []);
        }

        [$data, $problems] = BrandData::clean($values, $kit, $oldValues ?? [], $primaryOnly, $crm);

        if ($problems !== []) {
            $labels = static::labelSchema();
            throw new InvalidArgumentException(implode("\n", array_map(
                static fn (string $field): string => (string) __t('contacts.brand_invalid', ['field' => $labels[$field] ?? $field]),
                array_keys($problems)
            )));
        }

        $values = $data + array_intersect_key($values, array_flip(['name', 'note', 'archived', 'parent_id']));

        // Un valore non scalare dalla richiesta (`note[]=x`) vale vuoto.
        foreach (['name', 'note'] as $field) {
            if (array_key_exists($field, $values) && !is_scalar($values[$field])) {
                $values[$field] = '';
            }
        }

        // Un file o il suo ordine, mai un testo: un percorso o un URL scritti a mano non diventano il logo.
        if (is_array($upload) && isset($upload['name'])) {
            $values['logo'] = $upload;
        }

        if (is_string($manifest)) {
            $values['logo__wi_files'] = $manifest;
        }

        $name = is_scalar($values['name'] ?? null) ? trim((string) $values['name']) : '';
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

    /**
     * Con il CRM spento il form posta `email`, `phone_prefix` e `phone`: qui
     * diventano le prime due righe di `contacts`, con il tipo di telefono e
     * l'ufficio che avevano. Un campo non postato tiene il valore salvato;
     * senza nessuno dei tre i recapiti non si toccano.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    protected static function primaryContacts(array $values, array $stored): array
    {
        $posted = array_intersect_key($values, array_flip(['email', 'phone_prefix', 'phone']));

        if ($posted === []) {
            return $values;
        }

        $first = [];

        foreach (BrandData::contactsOf($stored) as $row) {
            $first[ContactPoints::family($row['kind'] ?? '')] ??= $row;
        }

        $email = $first['email'] ?? [];
        $phone = $first['phone'] ?? [];
        $values['contacts'] = [
            ['kind' => ContactPoints::EMAIL, 'email' => $posted['email'] ?? ($email['email'] ?? ''), 'label' => $email['label'] ?? ''],
            [
                'kind' => $phone['kind'] ?? ContactPoints::PHONE,
                'phone_prefix' => $posted['phone_prefix'] ?? ($phone['phone_prefix'] ?? ''),
                'phone' => $posted['phone'] ?? ($phone['phone'] ?? ''),
                'label' => $phone['label'] ?? '',
            ],
        ];

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
        $result = CompanyBrands::findOrCreate(is_scalar($values['name'] ?? null) ? (string) $values['name'] : '');

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
