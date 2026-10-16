<?php

namespace Wonder\Plugin\CustomerAccount\Support;

/**
 * Le colonne dell'export dei contatti, condivise da ogni Resource sulla
 * tabella `contacts` (la rubrica del modulo, i clienti e i fornitori del
 * gestionale): anagrafica, recapiti e indirizzo di fatturazione, poi stato e
 * data di creazione. Le righe le carica il core secondo la `querySchema()`
 * della Resource che esporta.
 */
final class ContactExport
{
    /** formato → etichetta del bottone «Esporta» */
    public const FORMATS = ['xlsx' => 'Excel (.xlsx)', 'csv' => 'CSV (.csv)'];

    /** I campi della scheda, nell'ordine del file: il telefono è già completo di prefisso. */
    public const FIELDS = [
        'type', 'name', 'surname', 'business_name', 'cf', 'pi', 'sdi', 'pec', 'email', 'phone',
        'street', 'number', 'more', 'cap', 'city', 'province', 'country', 'note',
    ];

    /**
     * @param array<string, string> $labels etichette della Resource (`labelSchema()`): i campi senza etichetta restano fuori
     * @param null|callable(array): string $displayName il nome come lo mostra l'elenco
     * @param list<array{label: string, value: string|callable}> $extra colonne in più, prima di stato e creazione
     * @return list<array{label: string, value: string|callable}>
     */
    public static function columns(array $labels, ?callable $displayName = null, array $extra = []): array
    {
        $columns = [['label' => 'ID', 'value' => 'id']];
        if ($displayName !== null) {
            $columns[] = ['label' => (string) __t('contacts.contact'), 'value' => static fn (array $row): string => (string) $displayName($row)];
        }
        foreach (self::FIELDS as $field) {
            if (!isset($labels[$field])) {
                continue;
            }
            $columns[] = $field === 'type'
                ? ['label' => (string) $labels[$field], 'value' => static fn (array $row): string => self::type((string) ($row['type'] ?? ''))]
                : ['label' => (string) $labels[$field], 'value' => $field];
        }

        return [
            ...$columns,
            ...$extra,
            ['label' => (string) ($labels['active'] ?? __t('contacts.active')), 'value' => static fn (array $row): string => (string) __t(($row['active'] ?? 'true') === 'false' ? 'contacts.disabled' : 'contacts.enabled')],
            ['label' => (string) __t('contacts.created'), 'value' => 'creation'],
        ];
    }

    private static function type(string $type): string
    {
        return in_array($type, ['private', 'business'], true) ? (string) __t('components.forms.options.address_type.'.$type) : $type;
    }
}
