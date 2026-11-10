<?php

namespace Wonder\Plugin\CustomerAccount\Models;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\CustomerAccount\Models\Concerns\HasDeliveryAddress;
use Wonder\Sql\TableSchema as Column;

/** Dove si consegna a un'azienda: sedi, magazzini, cantieri. Stesso schema di `ContactAddress`. */
class CompanyAddress extends Model
{
    use HasDeliveryAddress;

    public static string $table = 'company_addresses';
    public static string $folder = 'companies';
    public static string $icon = 'bi bi-geo';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('company_id')->int()->null(false)->foreign(Company::$table),
            Column::key('label')->length(100),
            static::businessNameColumn(),
            ...static::address()->tableSchema(),
            Column::key('is_default')->enum(['true', 'false'])->default('false'),
            Column::key('position')->int(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_company' => ['index' => 'company_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('company_id')->number()->decimals(0),
            Field::key('label')->text(),
            Field::key('business_name')->text(),
            ...static::address()->dataSchema(),
            Field::key('is_default')->text()->sanitize(false),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
