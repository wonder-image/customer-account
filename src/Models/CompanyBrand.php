<?php

namespace Wonder\Plugin\CustomerAccount\Models;

use Throwable;
use Wonder\App\Model;
use Wonder\App\Support\MediaFileManager;
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
 *
 * I dati del brand (logo, sito, dominio, descrizione) e il «brand kit»
 * (recapiti, social, colori, tono, settore, lingua, materiali) seguono le
 * regole di `Support\BrandData`. Il logo è un'immagine caricata dal framework
 * (un SVG lo ripulisce `uploadFiles()` del core); `socials` e `colors` sono
 * JSON scritti già validati.
 */
class CompanyBrand extends Model
{
    public const CODE_PREFIX = 'cbr_';
    /** La cartella del logo, sotto `$folder`. */
    public const LOGO_DIR = '/brand-logos/';

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
            Column::key('logo')->json(),
            Column::key('website')->length(255),
            Column::key('domain')->length(190),
            Column::key('description')->type('TEXT'),
            Column::key('email')->length(190),
            Column::key('phone_prefix')->length(8),
            Column::key('phone')->length(30),
            Column::key('socials')->type('TEXT'),
            Column::key('colors')->type('TEXT'),
            Column::key('tone')->type('TEXT'),
            Column::key('sector')->length(100),
            Column::key('language')->length(10),
            Column::key('assets_url')->length(255),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_name' => ['index' => 'name'],
            'ind_name_key' => ['index' => 'name_key'],
            'ind_parent' => ['index' => 'parent_id'],
            'ind_domain' => ['index' => 'domain'],
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
            Field::key('logo')
                ->image()
                ->extensions(['png', 'jpg', 'jpeg', 'webp', 'svg'])
                ->maxSize(2)
                ->maxFile(1)
                ->dir(self::LOGO_DIR)
                ->name('{slug}'),
            // URL, dominio, recapiti, social e colori arrivano già validati da `BrandData`.
            Field::key('website')->text()->sanitize(false),
            Field::key('domain')->text()->sanitize(false),
            Field::key('description')->text(),
            Field::key('email')->text()->sanitize(false),
            Field::key('phone_prefix')->text()->sanitize(false),
            Field::key('phone')->text()->sanitize(false),
            Field::key('socials')->text()->sanitize(false),
            Field::key('colors')->text()->sanitize(false),
            Field::key('tone')->text(),
            Field::key('sector')->text(),
            Field::key('language')->text()->sanitize(false),
            Field::key('assets_url')->text()->sanitize(false),
        ];
    }

    /**
     * Il logo come percorso sotto la cartella dei caricamenti
     * (`contacts/brand-logos/la-piana.png`), vuoto se non c'è: per i record
     * e i sync, che non portano file.
     */
    public static function logoPath(array $brand): string
    {
        $file = self::logoFile($brand);

        return $file === '' ? '' : trim(static::$folder, '/').'/'.trim(self::LOGO_DIR, '/').'/'.$file;
    }

    /** L'URL pubblico del logo, vuoto se non c'è o se il sito non è avviato (comandi, test). */
    public static function logoUrl(array $brand): string
    {
        $file = self::logoFile($brand);

        if ($file === '') {
            return '';
        }

        try {
            return (string) (static::storedFileUrl($file, ['dir' => self::LOGO_DIR]) ?? '');
        } catch (Throwable) {
            return '';
        }
    }

    /** Il nome del file del logo; un nome con percorsi o schemi non vale. */
    private static function logoFile(array $brand): string
    {
        $file = MediaFileManager::decodeStoredFiles($brand['logo'] ?? '')[0] ?? '';

        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,190}$/', $file) === 1 ? $file : '';
    }

    public static function newCode(): string
    {
        return ModelCode::make(static::class, self::CODE_PREFIX);
    }
}
