<?php
/**
 * Avvio dei test del modulo: il framework viene da `WI_APP` (un checkout di wonder-image/app con vendor),
 * altrimenti da `../app` accanto al modulo. Nessun database: i test che servono il sito sono nei moduli che lo usano.
 */
declare(strict_types=1);

$app = rtrim((string) (getenv('WI_APP') ?: dirname(__DIR__, 2).'/app'), '/');
if (!is_file($app.'/vendor/autoload.php')) {
    fwrite(STDERR, "wonder-image/app con vendor non trovato in {$app}: imposta WI_APP.\n");
    exit(1);
}
$loader = require $app.'/vendor/autoload.php';
$loader->addPsr4('Wonder\\Plugin\\CustomerAccount\\', dirname(__DIR__).'/src', true);
define('WI_APP_ROOT', $app);

if (!function_exists('__t')) {
    function __t(string $key, array $replace = []): string { return $key; }
}

$GLOBALS['__tests'] = 0;
$GLOBALS['__failures'] = 0;

function check(string $name, callable $fn): void
{
    $GLOBALS['__tests']++;
    try {
        $ok = $fn() !== false;
    } catch (\Throwable $e) {
        $ok = false;
        $name .= ' — '.$e->getMessage();
    }
    if (!$ok) {
        $GLOBALS['__failures']++;
    }
    echo ($ok ? '  ✓ ' : '  ✗ ').$name."\n";
}

function summary(): never
{
    echo "\n{$GLOBALS['__tests']} test, {$GLOBALS['__failures']} falliti\n";
    exit($GLOBALS['__failures'] > 0 ? 1 : 0);
}
