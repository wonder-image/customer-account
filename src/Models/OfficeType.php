<?php

namespace Wonder\Plugin\CustomerAccount\Models;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Un tipo ufficio creato a mano (es. «Marketing»), oltre a quelli di serie
 * di `ContactRelation::DEPARTMENTS`. Sui recapiti e sui referenti si salva
 * il suo id, come testo, nella colonna `department`.
 *
 * Lo legge e scrive `Support\OfficeTypes`. Non si elimina: i recapiti che lo
 * usano resterebbero con un tipo che non esiste più.
 */
class OfficeType extends Model
{
    public static string $table = 'contact_office_types';
    public static string $folder = 'contacts';
    public static string $icon = 'bi bi-diagram-3';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('name')->length(80)->null(false),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_name' => ['index' => 'name'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('name')->text(),
        ];
    }
}
