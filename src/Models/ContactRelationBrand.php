<?php

namespace Wonder\Plugin\CustomerAccount\Models;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * I brand seguiti da un referente (`contact_relations`). Nessuna riga = il
 * referente segue tutti i brand dell'azienda. Si scrive solo da
 * `Support\ContactRelations`.
 */
class ContactRelationBrand extends Model
{
    public static string $table = 'contact_relation_brands';
    public static string $folder = 'contacts';
    public static string $icon = 'bi bi-award';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('relation_id')->int()->null(false)->foreign(ContactRelation::$table),
            Column::key('brand_id')->int()->null(false)->foreign(CompanyBrand::$table),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'uq_relation_brand' => ['unique' => ['relation_id', 'brand_id']],
            'ind_brand' => ['index' => 'brand_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('relation_id')->number()->decimals(0),
            Field::key('brand_id')->number()->decimals(0),
        ];
    }
}
