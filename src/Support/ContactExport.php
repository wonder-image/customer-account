<?php

namespace Wonder\Plugin\CustomerAccount\Support;

/**
 * Le colonne degli export della rubrica, condivise da ogni Resource su
 * `contacts` o `companies` (la rubrica del modulo, i clienti del gestionale):
 * anagrafica, recapiti e indirizzo di fatturazione, poi stato e data di
 * creazione. Le righe le carica il core secondo la `querySchema()` della
 * Resource che esporta.
 */
final class ContactExport
{
    /** formato → etichetta del bottone «Esporta» */
    public const FORMATS = ['xlsx' => 'Excel (.xlsx)', 'csv' => 'CSV (.csv)'];

    /** I campi di una persona, nell'ordine del file: il telefono è già completo di prefisso. */
    public const PERSON_FIELDS = [
        'name', 'surname', 'cf', 'email', 'phone',
        'street', 'number', 'more', 'cap', 'city', 'province', 'country', 'note',
    ];

    /** I campi di un'azienda, nell'ordine del file. */
    public const COMPANY_FIELDS = [
        'business_name', 'cf', 'pi', 'sdi', 'pec', 'email', 'phone',
        'street', 'number', 'more', 'cap', 'city', 'province', 'country', 'note',
    ];

    /** @deprecated usa `PERSON_FIELDS` o `COMPANY_FIELDS` */
    public const FIELDS = self::PERSON_FIELDS;

    /**
     * @param array<string, string> $labels etichette della Resource (`labelSchema()`): i campi senza etichetta restano fuori
     * @param null|callable(array): string $displayName il nome come lo mostra l'elenco
     * @param list<array{label: string, value: string|callable}> $extra colonne in più, prima di stato e creazione
     * @param list<string>|null $fields i campi, in ordine (default: `PERSON_FIELDS`)
     * @return list<array{label: string, value: string|callable}>
     */
    public static function columns(array $labels, ?callable $displayName = null, array $extra = [], ?array $fields = null): array
    {
        $columns = [['label' => 'ID', 'value' => 'id']];
        if ($displayName !== null) {
            $columns[] = ['label' => (string) __t('contacts.contact'), 'value' => static fn (array $row): string => (string) $displayName($row)];
        }
        foreach ($fields ?? self::PERSON_FIELDS as $field) {
            if (isset($labels[$field])) {
                $columns[] = ['label' => (string) $labels[$field], 'value' => $field];
            }
        }

        return [
            ...$columns,
            ...$extra,
            ['label' => (string) ($labels['active'] ?? __t('contacts.active')), 'value' => static fn (array $row): string => (string) __t(($row['active'] ?? 'true') === 'false' ? 'contacts.disabled' : 'contacts.enabled')],
            ['label' => (string) __t('contacts.created'), 'value' => 'creation'],
        ];
    }
}
