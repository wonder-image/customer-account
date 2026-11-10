<?php

namespace Wonder\Plugin\CustomerAccount\Models;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Le aree di cui si occupa un referente (`contact_relations`): amministrazione,
 * acquisti, … Nessuna riga = si occupa di tutto (la ditta piccola dove una
 * persona fa tutto). Si scrive solo da `Support\ContactRelations`.
 */
class ContactRelationDepartment extends Model
{
    public static string $table = 'contact_relation_departments';
    public static string $folder = 'contacts';
    public static string $icon = 'bi bi-diagram-3';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('relation_id')->int()->null(false)->foreign(ContactRelation::$table),
            // Testo e non enum: le aree si estendono con `ContactRelations::addDepartment()`.
            Column::key('department')->length(40)->null(false),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'uq_relation_department' => ['unique' => ['relation_id', 'department']],
            'ind_department' => ['index' => 'department'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('relation_id')->number()->decimals(0),
            Field::key('department')->text()->sanitize(false),
        ];
    }
}
