<?php

namespace Wonder\Plugin\CustomerAccount\Models;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Il tipo ufficio di un recapito di una persona: la gemella di
 * `CompanyPointDepartment`, con gli stessi tipi dei referenti
 * (`ContactRelations::departments()`). Nessuna riga = nessun tipo. Si scrive
 * solo da `Support\ContactPoints`.
 *
 * Si riconosce dal valore (`kind`, `value`) e non dall'id di `contact_points`:
 * il recapito principale sta nelle colonne della persona.
 */
class ContactPointDepartment extends Model
{
    public static string $table = 'contact_point_departments';
    public static string $folder = 'contacts';
    public static string $icon = 'bi bi-diagram-3';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('contact_id')->int()->null(false)->foreign(Contact::$table),
            Column::key('kind')->enum(['email', 'phone', 'mobile', 'whatsapp'])->default('email'),
            Column::key('value')->length(190)->null(false),
            Column::key('department')->length(40)->null(false),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'uq_contact_point_department' => ['unique' => ['contact_id', 'kind', 'value', 'department']],
            'ind_department' => ['index' => 'department'],
        ];
    }

    public static function dataSchema(): array
    {
        // Valori già validati da `ContactPoints`: niente entità nel database.
        return [
            Field::key('contact_id')->number()->decimals(0),
            Field::key('kind')->text()->sanitize(false),
            Field::key('value')->text()->sanitize(false),
            Field::key('department')->text()->sanitize(false),
        ];
    }
}
