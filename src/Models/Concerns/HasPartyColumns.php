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
            Field::key('color')->text()->sanitize(false),
            Field::key('note')->text(),
            Field::key('custom_data')->json(),
            Field::key('active')->text()->sanitize(false),
        ];
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
