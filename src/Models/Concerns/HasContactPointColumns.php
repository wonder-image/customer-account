<?php

namespace Wonder\Plugin\CustomerAccount\Models\Concerns;

use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Le colonne di un'email o di un telefono in più di una scheda (persona o
 * azienda): cambia solo la colonna del padrone, come per gli indirizzi.
 * L'email e il telefono principali restano sulla scheda.
 */
trait HasContactPointColumns
{
    /** La colonna che punta alla scheda (`contact_id`, `company_id`). */
    abstract public static function ownerKey(): string;

    /** @return class-string<\Wonder\App\Model> */
    abstract public static function ownerModel(): string;

    public static function tableSchema(): array
    {
        return [
            Column::key(static::ownerKey())->int()->null(false)->foreign(static::ownerModel()::$table),
            Column::key('kind')->enum(['email', 'phone'])->default('email'),
            Column::key('value')->length(190)->null(false),
            Column::key('phone_prefix')->length(8),
            // Testo e non enum: le etichette si estendono con `ContactPoints::addLabel()`.
            Column::key('label')->length(40),
            Column::key('position')->int(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_owner' => ['index' => static::ownerKey()],
            'ind_value' => ['index' => ['kind', 'value']],
        ];
    }

    public static function dataSchema(): array
    {
        // Valori già validati da `ContactPoints`: niente entità nel database.
        return [
            Field::key(static::ownerKey())->number()->decimals(0),
            Field::key('kind')->text()->sanitize(false),
            Field::key('value')->text()->sanitize(false),
            Field::key('phone_prefix')->text()->sanitize(false),
            Field::key('label')->text()->sanitize(false),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
