<?php

namespace Wonder\Plugin\CustomerAccount;

use Wonder\App\Module\Contracts\ModuleInterface;

/** Entrypoint del modulo: percorsi di view, handler e traduzioni. */
final class CustomerAccount implements ModuleInterface
{
    public const SLUG = 'customer-account';

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

    public static function langPath(): string
    {
        return self::root().'/lang';
    }

    public static function assetPath(string $path = ''): string
    {
        return self::root().'/resources/assets/'.ltrim($path, '/');
    }
}
