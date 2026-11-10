<?php

namespace Wonder\Plugin\CustomerAccount\Models;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Di cosa si occupa un contatto di un'azienda (amministrazione@ → fatture):
 * le stesse aree dei referenti (`ContactRelations::departments()`). Nessuna
 * riga = si occupa di tutto. Si scrive solo da `Support\ContactPoints`.
 *
 * Si riconosce dal valore (`kind`, `value`) e non dall'id di `company_points`:
 * il contatto principale sta nelle colonne dell'azienda, e quando cambia il
 * principale l'area segue l'email.
 */
class CompanyPointDepartment extends Model
{
    public static string $table = 'company_point_departments';
    public static string $folder = 'companies';
    public static string $icon = 'bi bi-diagram-3';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('company_id')->int()->null(false)->foreign(Company::$table),
            Column::key('kind')->enum(['email', 'phone'])->default('email'),
            Column::key('value')->length(190)->null(false),
            // Testo e non enum: le aree si estendono con `ContactRelations::addDepartment()`.
            Column::key('department')->length(40)->null(false),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'uq_point_department' => ['unique' => ['company_id', 'kind', 'value', 'department']],
            'ind_department' => ['index' => 'department'],
        ];
    }

    public static function dataSchema(): array
    {
        // Valori già validati da `ContactPoints`: niente entità nel database.
        return [
            Field::key('company_id')->number()->decimals(0),
            Field::key('kind')->text()->sanitize(false),
            Field::key('value')->text()->sanitize(false),
            Field::key('department')->text()->sanitize(false),
        ];
    }
}
