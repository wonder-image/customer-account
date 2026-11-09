<?php

namespace Wonder\Plugin\CustomerAccount\Models;

use Wonder\App\Model;
use Wonder\App\Support\ModelCode;
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
 *
 * `code` e `slug` sono univoci e non cambiano dopo la creazione: i moduli
 * esterni (preventivi, progetti, sync) li usano per riconoscerlo.
 * `name_key` è il nome senza maiuscole, spazi e punteggiatura: serve a
 * trovare i doppioni con una query sull'indice. Un brand `archived` non si
 * propone più nelle scelte, ma resta leggibile sui documenti che lo usano.
 * `parent_id` fa del brand una linea di un altro (0 = nessuno): un livello
 * solo, con le regole di `CompanyBrands::parentProblem()`.
 */
class CompanyBrand extends Model
{
    public const CODE_PREFIX = 'cbr_';

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
            ...static::sqlColumnsFromDataSchema(['code']),
            Column::key('name')->length(150),
            Column::key('slug')->length(150)->unique(),
            Column::key('name_key')->length(150),
            Column::key('note')->type('TEXT'),
            Column::key('archived')->enum(['true', 'false'])->default('false'),
            Column::key('parent_id')->int()->null(false)->default('0'),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_name' => ['index' => 'name'],
            'ind_name_key' => ['index' => 'name_key'],
            'ind_parent' => ['index' => 'parent_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(self::CODE_PREFIX),
            Field::key('name')->text(),
            Field::key('slug')->text()->slug()->readonlyOnUpdate()->immutableOnUpdate(),
            Field::key('name_key')->text()->sanitize(false),
            Field::key('note')->text(),
            Field::key('archived')->text()->sanitize(false),
            Field::key('parent_id')->number()->decimals(0),
        ];
    }

    public static function newCode(): string
    {
        return ModelCode::make(static::class, self::CODE_PREFIX);
    }
}
