<?php

namespace Wonder\Plugin\CustomerAccount\Models;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Il legame fra un'azienda (`companies`) e un brand (`company_brands`).
 * Una coppia esiste una volta sola; si scrive solo da `Support\CompanyBrands::sync()`.
 */
class CompanyBrandLink extends Model
{
    public static string $table = 'company_brand_links';
    public static string $folder = 'contacts';
    public static string $icon = 'bi bi-link-45deg';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('company_id')->int()->null(false)->foreign(Company::$table),
            Column::key('brand_id')->int()->null(false)->foreign(CompanyBrand::$table),
            Column::key('position')->int(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'uq_company_brand' => ['unique' => ['company_id', 'brand_id']],
            'ind_brand' => ['index' => 'brand_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('company_id')->number()->decimals(0),
            Field::key('brand_id')->number()->decimals(0),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
