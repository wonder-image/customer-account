<?php

namespace Wonder\Plugin\CustomerAccount\Models;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * I brand per cui vale un indirizzo di un'azienda (`company_addresses`): la
 * sede di un brand, il magazzino di un altro. Nessuna riga = l'indirizzo vale
 * per tutti i brand. Si scrive solo da `Support\CompanyAddresses`.
 */
class CompanyAddressBrand extends Model
{
    public static string $table = 'company_address_brands';
    public static string $folder = 'companies';
    public static string $icon = 'bi bi-award';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('address_id')->int()->null(false)->foreign(CompanyAddress::$table),
            Column::key('brand_id')->int()->null(false)->foreign(CompanyBrand::$table),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'uq_address_brand' => ['unique' => ['address_id', 'brand_id']],
            'ind_brand' => ['index' => 'brand_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('address_id')->number()->decimals(0),
            Field::key('brand_id')->number()->decimals(0),
        ];
    }
}
