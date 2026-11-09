<?php

namespace Wonder\Plugin\CustomerAccount\Models;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Un brand: il nome commerciale con cui un'azienda si presenta (es. «La Piana
 * Ristorante» di STELI HOLIDAY SERVICE S.R.L.).
 *
 * Un brand può stare su più ragioni sociali e una ragione sociale può avere
 * più brand: il legame è in `CompanyBrandLink`. Lo legge e scrive
 * `Support\CompanyBrands`.
 */
class CompanyBrand extends Model
{
    public static string $table = 'company_brands';
    public static string $folder = 'contacts';
    public static string $icon = 'bi bi-award';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('name')->length(150),
            Column::key('note')->type('TEXT'),
        ];
    }

    public static function tablePseudos(): array
    {
        return ['ind_name' => ['index' => 'name']];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('name')->text(),
            Field::key('note')->text(),
        ];
    }
}
