<?php

namespace Wonder\Plugin\CustomerAccount;

use Wonder\App\Module\ConfigRepository;
use Wonder\App\Module\Contracts\ModuleInterface;

/** Entrypoint del modulo: percorsi di view, handler e traduzioni, configurazione. */
final class CustomerAccount implements ModuleInterface
{
    public const SLUG = 'customer-account';

    private static ?array $config = null;

    /** I default di `config/module.php` sotto quelli del sito; `$key` con i punti (`signup.fields`). */
    public static function config(?string $key = null, mixed $default = null): mixed
    {
        if (self::$config === null) {
            $defaults = require self::root().'/config/module.php';
            $site = class_exists(ConfigRepository::class) ? ConfigRepository::for(self::SLUG) : [];
            self::$config = array_replace_recursive((array) $defaults, (array) $site);
        }
        if ($key === null) {
            return self::$config;
        }
        $value = self::$config;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public static function root(): string
    {
        return dirname(__DIR__);
    }

    public static function manifestPath(): string
    {
        return self::root().'/module.json';
    }

    public static function handlerPath(string $path): string
    {
        return self::root().'/http/'.ltrim($path, '/');
    }

    /** La view del sito, se pubblicata, vince su quella del modulo. */
    public static function viewPath(string $path): string
    {
        $root = (string) ($GLOBALS['ROOT'] ?? '');
        $custom = $root.'/custom/modules/'.self::SLUG.'/view/'.ltrim($path, '/');

        return $root !== '' && is_file($custom) ? $custom : self::root().'/view/'.ltrim($path, '/');
    }

    /** View sigillata: sempre quella del package, senza override del sito. */
    public static function packageView(string $path): string
    {
        return self::root().'/view/'.ltrim($path, '/');
    }

    public static function langPath(): string
    {
        return self::root().'/lang';
    }

    public static function assetPath(string $path = ''): string
    {
        return self::root().'/resources/assets/'.ltrim($path, '/');
    }
}
