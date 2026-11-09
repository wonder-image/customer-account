<?php

namespace Wonder\Plugin\CustomerAccount\Resources;

use InvalidArgumentException;
use Wonder\Plugin\CustomerAccount\Models\{Contact, ContactAddress};
use Wonder\App\ResourceSchema\{FormField, NavigationSchema, PageSchema, TableColumn, TableLayoutSchema};
use Wonder\App\Support\GoogleMaps;
use Wonder\Auth\Frontend\{AccountAddressForm, AccountAddressValidation};

/**
 * Gli indirizzi di spedizione, sempre legati a una scheda esistente.
 * `CompanyAddressResource` la estende per le aziende con `parentModel()` e `parentKey()`.
 */
class ContactAddressResource extends ContactResource
{
    public static string $model = ContactAddress::class;
    public static function path(): string { return 'contact-addresses'; }
    /** @return class-string<\Wonder\App\Model> */
    public static function parentModel(): string { return Contact::class; }
    public static function parentKey(): string { return 'contact_id'; }
    /** Gli indirizzi non emettono eventi della rubrica. */
    protected static function dispatchContactEvent(string $what, int $id, object $result): void {}
    public static function textSchema(): array
    {
        return ['label' => (string) __t('contacts.address'), 'plural_label' => (string) __t('contacts.addresses')];
    }
    public static function labelSchema(): array
    {
        return static::$model::address()->labels() + [static::parentKey() => static::parentResourceLabel(), 'label' => (string) __t('contacts.address_label')];
    }
    protected static function parentResourceLabel(): string
    {
        return (string) __t('contacts.contact');
    }
    public static function contactOptions(): array
    {
        $options = [];
        foreach (static::parentModel()::all() as $row) { $options[(int) $row['id']] = parent::displayName($row); }
        return $options;
    }
    public static function formSchema(): array
    {
        return [
            FormField::key(static::parentKey())->selectSearch(static::contactOptions())->required(),
            FormField::key('label')->text(),
            ...array_values(AccountAddressForm::fields(static::$model::address())),
        ];
    }
    public static function mutateFormValues(array $values, string $mode, string $context = 'backend'): array
    {
        $key = static::parentKey();
        if ($mode === 'create' && !isset($values[$key]) && isset($_GET[$key]) && ctype_digit((string) $_GET[$key])) {
            $values[$key] = (int) $_GET[$key];
        }
        return parent::mutateFormValues($values, $mode, $context);
    }
    public static function navigationSchema(): NavigationSchema { return NavigationSchema::for(static::class)->enabled(false); }
    public static function pageSchema(): PageSchema { return PageSchema::for(static::class)->disable(['delete']); }
    public static function tableSchema(): array
    {
        return [TableColumn::key(static::parentKey())->text(), TableColumn::key('label')->text()->link('edit'),
            TableColumn::key('name')->text()->link('edit'), TableColumn::key('street')->text(), TableColumn::key('city')->text(),
            TableColumn::key('actions')->button()->actions(['edit'])];
    }
    public static function tableLayoutSchema(): TableLayoutSchema
    {
        return TableLayoutSchema::for(static::class)->searchFields(['label', 'name', 'surname', 'city', 'street'])
            ->filterCustom(static::parentResourceLabel(), static::parentKey(), static::contactOptions());
    }
    public static function mutateRequestValues(array $values, string $action, string $context = 'backend', ?array $oldValues = null): array
    {
        static::verifyFormToken($values);
        $values = array_intersect_key($values, static::labelSchema()) + GoogleMaps::cleanGeo($values);
        $key = static::parentKey();
        $contactId = filter_var($values[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$contactId || !static::parentModel()::findById($contactId) || ($oldValues !== null && $contactId !== (int) $oldValues[$key])) {
            throw new InvalidArgumentException((string) __t('contacts.invalid_contact'));
        }
        if (isset($values['label']) && (!is_string($values['label']) || mb_strlen($values['label']) > 100)) {
            throw new InvalidArgumentException((string) __t('contacts.field_invalid', ['field' => static::labelSchema()['label']]));
        }
        $errors = AccountAddressValidation::validate(static::$model::address(), $values);
        if ($errors !== []) { throw new InvalidArgumentException(implode("\n", $errors)); }
        $values[$key] = $contactId;
        return $values;
    }
}
