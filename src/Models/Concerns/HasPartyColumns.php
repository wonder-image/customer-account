<?php

namespace Wonder\Plugin\CustomerAccount\Models\Concerns;

use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Le colonne che persone e aziende hanno uguali: ruolo di cliente, stato,
 * note e dati liberi. Codice, email e colore arrivano da `dataSchema()`.
 */
trait HasPartyColumns
{
    protected static function partyColumns(): array
    {
        return [
            Column::key('is_customer')->enum(['true', 'false'])->default('true'),
            // Il tipo del telefono principale (fisso, cellulare, WhatsApp): vedi `ContactPoints`.
            Column::key('phone_kind')->length(20)->default('phone'),
            Column::key('note')->type('TEXT'),
            Column::key('custom_data')->json(),
            Column::key('active')->enum(['true', 'false'])->default('true'),
        ];
    }

    protected static function partyFields(): array
    {
        return [
            Field::key('email')->email(),
            Field::key('is_customer')->text()->sanitize(false),
            Field::key('phone_kind')->text()->sanitize(false),
            Field::key('color')->text()->sanitize(false),
            Field::key('note')->text(),
            Field::key('custom_data')->json(),
            Field::key('active')->text()->sanitize(false),
        ];
    }

    /**
     * I campi di fatturazione per il form. Dove il Paese può mancare
     * (`countryOptional()`, le persone) il select ha anche l'opzione vuota:
     * senza, una scheda senza paese mostrerebbe il primo dell'elenco.
     *
     * @return array<string, \Wonder\App\ResourceSchema\Input>
     */
    public static function billingFormSchema(): array
    {
        $fields = static::billing()->formSchema();

        if (static::countryOptional() && isset($fields['country']) && function_exists('countries')) {
            $options = (array) ($fields['country']->get('options') ?? []);
            $fields['country']->options(['' => (string) __t('contacts.option_none')] + ($options !== [] ? $options : countries()));
        }

        return $fields;
    }

    /** Il Paese della fatturazione può restare vuoto? */
    protected static function countryOptional(): bool
    {
        return false;
    }

    /** L'indirizzo di fatturazione già composto, come lo mostra il core. */
    public static function decorate(array $row): array
    {
        try {
            return static::billing()->decorate($row);
        } catch (\Throwable) {
            // Nei comandi di `forge` le funzioni globali del core non ci sono.
            return $row;
        }
    }

    public static function create(array $values): object
    {
        if (trim((string) ($values['code'] ?? '')) === '') {
            $values['code'] = static::newCode();
        }

        return parent::create($values);
    }
}
