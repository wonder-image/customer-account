<?php

namespace Wonder\Plugin\CustomerAccount\Resources;

use InvalidArgumentException;
use Wonder\Plugin\CustomerAccount\Models\Contact;
use Wonder\Plugin\CustomerAccount\Resources\Concerns\HasContactRelations;
use Wonder\Plugin\CustomerAccount\Support\ContactEvents;
use Wonder\Plugin\CustomerAccount\Support\ContactExport;
use Wonder\App\Resource;
use Wonder\App\ResourceRegistry;
use Wonder\App\ResourceSchema\{ApiSchema, FormField, NavigationSchema, PageSchema, PermissionSchema, TableColumn, TableLayoutSchema};
use Wonder\App\Support\GoogleMaps;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Form\Form;
use Wonder\Http\Csrf;

/**
 * La rubrica delle persone, senza campi commerciali né credenziali. Email e
 * telefoni stanno tutti nel riquadro «Contatti» (`HasContactPoints`): il
 * principale di ogni tipo si salva nelle colonne `email`, `phone_prefix`,
 * `phone`, gli altri in `contact_points`. Un POST con i soli campi singoli
 * (senza `contact_box_form`) si salva come prima.
 * `CompanyResource` la estende per le aziende: i punti che cambiano sono
 * `requiredKeys()`, `exportFields()`, `addressesPath()` e gli eventi.
 */
class ContactResource extends Resource
{
    use HasContactRelations;

    public static string $model = Contact::class;
    public static string $orderColumn = 'id';

    public static function path(): string { return 'contacts'; }
    public static function isTableFallback(): bool { return true; }
    public static function textSchema(): array
    {
        return ['label' => (string) __t('contacts.contact'), 'plural_label' => (string) __t('contacts.title')];
    }
    public static function labelSchema(): array
    {
        return static::$model::billing()->labels() + [
            'email' => (string) __t('contacts.email'), 'note' => (string) __t('contacts.note'),
            'active' => (string) __t('contacts.active'),
        ];
    }
    public static function formSchema(): array
    {
        // Telefono ed email stanno nel riquadro «Contatti», non nei campi della fatturazione.
        $fields = array_diff_key(static::$model::billingFormSchema(), ['phone_prefix' => true, 'phone' => true]);
        foreach (static::requiredKeys() as $key) { $fields[$key]->required(); }
        // Un'attività cercata per nome dà la ragione sociale, se è vuota (lo script del modulo).
        if (isset($fields['business_name'], $fields['street'])) { $fields['street']->attribute('data-ca-place-name="business_name"'); }
        return [
            ...array_values($fields),
            ...static::extraFormFields(),
            ...static::contactBoxFields(),
            FormField::key('note')->textarea(),
            FormField::key('active')->select(['true' => (string) __t('contacts.enabled'), 'false' => (string) __t('contacts.disabled')])->value('true')->required(),
            ...static::contactRelationFields(),
        ];
    }
    public static function formLayoutSchema(): ?Form
    {
        $widths = ['country' => 12, 'is_customer' => 6, 'is_supplier' => 6, 'street' => 9, 'number' => 3, 'province' => 4, 'city' => 5, 'cap' => 3, 'phone_prefix' => 3, 'phone' => 9, 'name' => 6, 'surname' => 6,
            'pi' => 6, 'sdi' => 6];
        $fields = array_map(static fn ($field) => $field->columnSpan($widths[$field->name] ?? 12), static::formSchema());
        return (new Form)->columns(12)->components([(new Card)->columns(12)->columnSpan(12)->components([...$fields, static::contactFormScript()])]);
    }
    public static function tableSchema(): array
    {
        return [
            TableColumn::key('name')->text()->link('edit')->formatter(static fn (array $row): string => htmlspecialchars(static::displayName($row), ENT_QUOTES, 'UTF-8')),
            TableColumn::key('email')->text(), TableColumn::key('city')->text(),
            TableColumn::key('actions')->button()->actions(['edit']),
        ];
    }
    /** Il nome di una scheda: la ragione sociale se c'è, altrimenti nome e cognome. */
    public static function displayName(array $row): string
    {
        $name = trim((string) ($row['business_name'] ?? ''));
        if ($name === '') { $name = trim(($row['name'] ?? '').' '.($row['surname'] ?? '')); }
        return $name !== '' ? $name : '#'.($row['id'] ?? '');
    }
    /** I campi obbligatori della fatturazione. */
    public static function requiredKeys(): array { return ['name', 'surname']; }
    /** I campi della ricerca nell'elenco. */
    public static function searchKeys(): array { return ['name', 'surname', 'email', 'city']; }
    /** I campi dell'export, in ordine. */
    public static function exportFields(): array { return ContactExport::PERSON_FIELDS; }
    /** La Resource degli indirizzi di spedizione e la colonna che la lega. */
    public static function addressesPath(): array { return ['contact-addresses', 'contact_id']; }
    /** Campi del form in più dopo la fatturazione. */
    protected static function extraFormFields(): array { return []; }
    public static function mutateFormValues(array $values, string $mode, string $context = 'backend'): array
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' && trim((string) ($values['phone_prefix'] ?? '')) === '') {
            $country = (string) ($values['country'] ?? 'IT');
            $prefix = $country !== '' ? (string) countryPhonePrefix($country) : '';
            if ($prefix !== '' && !str_starts_with($prefix, '+')) { $prefix = '+'.ltrim($prefix, '0'); }
            $values['phone_prefix'] = $prefix;
        }
        return $values;
    }
    public static function pageSchema(): PageSchema
    {
        return PageSchema::for(static::class)->disable(['delete'])->redirect('store', 'edit')->redirect('update', 'edit')
            ->actions('edit', static function (array $row): array {
                [$path, $key] = static::addressesPath();
                return [[
                    'label' => (string) __t('contacts.addresses'), 'icon' => 'bi bi-geo-alt',
                    'href' => __r('backend.resource.'.$path.'.list').'?'.$key.'='.(int) ($row['id'] ?? 0),
                ]];
            });
    }
    public static function apiSchema(): ApiSchema { return ApiSchema::for(static::class)->enabled(false); }
    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backendCrud(['admin', 'administrator']);
    }
    public static function navigationSchema(): NavigationSchema
    {
        // Una Resource più ricca sulla stessa tabella (modulo o sito) tiene il menu; gli URL generici restano.
        $visible = true;
        foreach (ResourceRegistry::classes() as $resource) {
            if ($resource !== static::class && !is_subclass_of($resource, static::class) && $resource::modelTable() === static::$model::$table) {
                $visible = false;
                break;
            }
        }
        return NavigationSchema::for(static::class)->title(static::textSchema()['plural_label'])->sectionOrder(400)
            ->authority(['admin', 'administrator'])->enabled($visible);
    }
    public static function tableLayoutSchema(): TableLayoutSchema
    {
        return TableLayoutSchema::for(static::class)->searchFields(static::searchKeys())
            ->download(ContactExport::FORMATS)->downloadAsText()
            ->downloadColumns(ContactExport::columns(static::labelSchema(), [static::class, 'displayName'], [], static::exportFields()))
            ->downloadFileName(create_link(static::textSchema()['plural_label']).'-'.date('Y-m-d'));
    }
    public static function mutateRequestValues(array $values, string $action, string $context = 'backend', ?array $oldValues = null): array
    {
        static::verifyFormToken($values);
        // Il riquadro «Contatti» rimette il principale nelle colonne; le altre righe le scrive `syncRepeaterRelations()`.
        // Con un account l'email di accesso non si cambia da qui, con o senza riquadro: si scarta.
        $values = static::applyContactPoints(static::withoutAccessEmail($values, $oldValues), $oldValues);
        // I contatti di lavoro dei referenti: il repeater non arriva qui (lo toglie `stripRelationInputValues()`).
        static::assertRelationContacts($_POST);
        $geo = static::postedGeo($values);
        $values = array_intersect_key($values, static::labelSchema()) + $geo;
        foreach (array_diff_key($values, $geo) as $key => $value) {
            if (!is_scalar($value) && $value !== null) { throw new InvalidArgumentException((string) __t('contacts.field_invalid', ['field' => static::labelSchema()[$key]])); }
        }
        foreach (['active', 'is_customer', 'is_supplier'] as $flag) {
            if (array_key_exists($flag, $values) && !in_array($values[$flag], ['true', 'false'], true)) {
                throw new InvalidArgumentException((string) __t('contacts.invalid'));
            }
        }
        foreach (static::requiredKeys() as $key) {
            if (trim((string) ($values[$key] ?? '')) === '') {
                throw new InvalidArgumentException((string) __t('contacts.field_required', ['field' => static::labelSchema()[$key]]));
            }
        }
        $country = (string) ($values['country'] ?? '');
        $province = (string) ($values['province'] ?? '');
        if (($country !== '' && !array_key_exists($country, countries()))
            || ($province !== '' && !array_key_exists($province, states($country)))) {
            throw new InvalidArgumentException((string) __t('contacts.invalid_address'));
        }
        if ($action === 'store') { $values['code'] = static::$model::newCode(); }
        return $values;
    }
    /**
     * Place ID e coordinate della fatturazione, ripuliti (`GoogleMaps::cleanGeo()`):
     * solo se il form li ha postati, così un salvataggio senza non li azzera.
     * Arrivano dal browser: servono per mappe e distanze, mai per decidere.
     */
    protected static function postedGeo(array $values): array
    {
        return array_intersect_key($values, array_flip(['place_id', 'lat', 'lng'])) !== [] ? GoogleMaps::cleanGeo($values) : [];
    }
    /** Difesa locale: il controller del core ha già verificato `_csrf`, che arriva qui con il POST. */
    protected static function verifyFormToken(array $values): void
    {
        if (!Csrf::verify($values)) {
            throw new InvalidArgumentException((string) __t('contacts.csrf'));
        }
    }

    /** Emette l'evento di creazione: le sottoclassi che lo ridefiniscono chiamano `parent::afterStore()`. */
    public static function afterStore(object $result, array $values = []): void
    {
        parent::afterStore($result, $values);
        static::dispatchContactEvent('created', (int) ($result->insert_id ?? 0), $result);
    }

    /** Emette l'evento di modifica. */
    public static function afterUpdate(int|string $id, object $result, array $values = []): void
    {
        parent::afterUpdate($id, $result, $values);
        static::dispatchContactEvent('updated', (int) $id, $result);
    }

    /** Il nome dell'evento (`created`, `updated`) e la chiave della scheda nel payload. */
    protected static function contactEvent(string $what): array
    {
        return [$what === 'created' ? ContactEvents::CREATED : ContactEvents::UPDATED, 'contact'];
    }

    protected static function dispatchContactEvent(string $what, int $id, object $result): void
    {
        if ($id <= 0 || ($result->success ?? true) === false) {
            return;
        }

        $row = static::$model::findById($id);

        if (is_array($row) && isset($row['id'])) {
            [$event, $key] = static::contactEvent($what);
            ContactEvents::dispatch($event, ['id' => $id, $key => $row]);
        }
    }
}
