<?php
/** php tests/run.php — esegue tutti i test del modulo (WI_APP indica il checkout di wonder-image/app). */
declare(strict_types=1);

$failed = [];
foreach (glob(__DIR__.'/*Test.php') ?: [] as $file) {
    echo basename($file)."\n";
    passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($file), $status);
    if ($status !== 0) {
        $failed[] = basename($file);
    }
}
echo $failed === [] ? "\nTutti i test passano.\n" : "\nFalliti: ".implode(', ', $failed)."\n";
exit($failed === [] ? 0 : 1);
