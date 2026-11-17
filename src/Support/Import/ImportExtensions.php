<?php

namespace Wonder\Plugin\CustomerAccount\Support\Import;

use LogicException;
use Throwable;
use Wonder\App\Logger;
use Wonder\App\Module\ConfigRepository;

/**
 * Le estensioni dei tracciati della rubrica: quelle di `contacts_import.extensions`
 * di ogni modulo, più quelle aggiunte da codice (`add()`, per esempio dal sito
 * in `custom/function`). Una classe che non si costruisce va nel log e si salta.
 */
final class ImportExtensions
{
    /** @var list<class-string<ImportExtension>|ImportExtension> */
    private static array $added = [];

    /** @param class-string<ImportExtension>|ImportExtension $extension */
    public static function add(string|ImportExtension $extension): void
    {
        self::$added[] = $extension;
    }

    public static function reset(): void
    {
        self::$added = [];
    }

    /**
     * @param array<string, array<string, mixed>>|null $configs le config dei moduli (per i test)
     * @return list<ImportExtension>
     */
    public static function all(?array $configs = null): array
    {
        $classes = [];

        foreach ($configs ?? ConfigRepository::all() as $config) {
            foreach ((array) ($config['contacts_import']['extensions'] ?? []) as $extension) {
                $classes[] = $extension;
            }
        }

        $items = [];
        $seen = [];

        foreach ([...$classes, ...self::$added] as $extension) {
            $class = is_object($extension) ? $extension::class : (string) $extension;

            if (isset($seen[$class]) || !($extension instanceof ImportExtension || is_subclass_of($class, ImportExtension::class))) {
                continue;
            }

            try {
                $items[] = $extension instanceof ImportExtension ? $extension : new $class();
                $seen[$class] = true;
            } catch (Throwable $exception) {
                Logger::log($exception, 'backend', 'import', 'ERROR', 'error', ['class' => $class], false);
            }
        }

        return $items;
    }

    /**
     * Il Model con cui scrive il tracciato: quello di un'estensione, se una lo
     * cambia, altrimenti `$default`.
     *
     * @param list<ImportExtension> $extensions
     * @param class-string $default
     * @return class-string
     */
    public static function model(array $extensions, string $layout, string $default): string
    {
        $model = null;

        foreach ($extensions as $extension) {
            $own = $extension->model($layout);

            if ($own === null || $own === $model) {
                continue;
            }

            if ($model !== null || !is_a($own, $default, true)) {
                throw new LogicException('Tracciato '.$layout.': Model in conflitto tra le estensioni ('.($model ?? $default).', '.$own.').');
            }

            $model = $own;
        }

        return $model ?? $default;
    }

    /**
     * Chi può caricare: un'estensione che nega vince, poi una che concede,
     * altrimenti `$default`.
     *
     * @param list<ImportExtension> $extensions
     */
    public static function permits(array $extensions, string $layout, object $user, bool $default): bool
    {
        $granted = false;

        foreach ($extensions as $extension) {
            $answer = $extension->permits($layout, $user);

            if ($answer === false) {
                return false;
            }

            $granted = $granted || $answer === true;
        }

        return $granted || $default;
    }

    /** L'elenco di una Resource, dalla rotta che il core le dà. */
    public static function listUrl(string $resource): string
    {
        return (string) __r('backend.resource.'.$resource::slug().'.list');
    }

    /**
     * La prima lista indicata da un'estensione, altrimenti quella del tracciato.
     *
     * @param list<ImportExtension> $extensions
     * @param callable(): ?string $default
     */
    public static function backUrl(array $extensions, string $layout, callable $default): ?string
    {
        try {
            foreach ($extensions as $extension) {
                if (($url = $extension->backUrl($layout)) !== null) {
                    return $url;
                }
            }

            return $default();
        } catch (\Throwable) {
            return null;
        }
    }
}
