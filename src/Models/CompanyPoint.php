<?php

namespace Wonder\Plugin\CustomerAccount\Models;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Plugin\CustomerAccount\Models\Concerns\HasContactPointColumns;

/** Le email e i telefoni in più di un'azienda (`company_id`): come `ContactPoint`. */
class CompanyPoint extends Model
{
    use HasContactPointColumns;

    public static string $table = 'company_points';
    public static string $folder = 'companies';
    public static string $icon = 'bi bi-telephone';

    public static function ownerKey(): string
    {
        return 'company_id';
    }

    public static function ownerModel(): string
    {
        return Company::class;
    }

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }
}
