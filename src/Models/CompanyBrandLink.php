<?php

namespace Wonder\Plugin\CustomerAccount\Models;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Il legame fra un'azienda (`companies`) e un brand (`company_brands`).
 * Si scrive solo da `Support\CompanyBrands`.
 *
 * Una coppia esiste una volta sola: staccare un brand chiude la riga
 * (`ended_at`), ricollegarlo la riapre. Così un documento può chiedere se il
 * brand era dell'azienda alla sua data (`CompanyBrands::belongsTo()`).
 */
class CompanyBrandLink extends Model
{
    /** Il brand è dell'azienda. */
    public const ROLE_OWNER = 'owner';
    /** L'azienda lo usa su licenza. */
    public const ROLE_LICENSEE = 'licensee';
    /** L'azienda commissiona lavori per un brand non suo (conto terzi). */
    public const ROLE_INTERMEDIARY = 'intermediary';
    /** I ruoli di base; altri si aggiungono con `CompanyBrands::addRole()`. */
    public const ROLES = [self::ROLE_OWNER, self::ROLE_LICENSEE, self::ROLE_INTERMEDIARY];

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
            // Testo e non enum: i ruoli si estendono senza toccare la tabella.
            Column::key('role')->length(40)->default(self::ROLE_OWNER),
            Column::key('is_default')->enum(['true', 'false'])->default('false'),
            Column::key('position')->int(),
            Column::key('ended_at')->datetime(),
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
            Field::key('role')->text()->sanitize(false),
            Field::key('is_default')->text()->sanitize(false),
            Field::key('position')->number()->decimals(0),
            Field::key('ended_at')->text()->sanitize(false),
        ];
    }
}
