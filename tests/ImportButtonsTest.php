<?php
/** php tests/ImportButtonsTest.php — i bottoni «Importa ...» di indirizzi e referenti negli elenchi. */
declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use Wonder\App\Import\{Change, Context, Layout, Layouts};
use Wonder\Plugin\CustomerAccount\Support\Import\{ImportExtension, ImportExtensions, ImportLinks};

/** Un tracciato che lascia passare solo chi ha `id` 1. */
final class TracciatoFinto extends Layout
{
    public function __construct(private string $etichetta) {}
    public static function key(): string { return 'finto'; }
    public function label(): string { return $this->etichetta; }
    public function permits(object $user): bool { return (int) ($user->id ?? 0) === 1; }
    public function columns(): array { return []; }
    public function validate(array $rows, Context $context): array { return []; }
    public function apply(array $data, Context $context): array { return []; }
    public function undo(Change $change, Context $context): void {}
}

Layouts::reset();
Layouts::set('indirizzi', new TracciatoFinto('Indirizzi'));
Layouts::set('referenti', new TracciatoFinto('Referenti'));

$url = static fn (string $key): string => '/import?layout='.$key;
$ammesso = (object) ['id' => 1];

echo "ImportLinks\n";

check('un bottone per tracciato, nell\'ordine dato, con il link al caricamento', function () use ($url, $ammesso) {
    $bottoni = ImportLinks::buttons(['indirizzi', 'referenti'], $ammesso, $url);

    return count($bottoni) === 2
        && ($bottoni[0]->toArray()['attributes']['href'] ?? '') === '/import?layout=indirizzi'
        && ($bottoni[1]->toArray()['attributes']['href'] ?? '') === '/import?layout=referenti'
        && $bottoni[0]->getLabel() === 'contacts.import.link';
});

check('niente bottone a chi il tracciato non lo permette o senza utente', function () use ($url) {
    return ImportLinks::buttons(['indirizzi'], (object) ['id' => 2], $url) === []
        && ImportLinks::buttons(['indirizzi'], null, $url) === [];
});

check('un tracciato che non c\'è (modulo spento) non dà bottone', function () use ($url, $ammesso) {
    Layouts::set('referenti', false);
    $bottoni = ImportLinks::buttons(['indirizzi', 'referenti'], $ammesso, $url);
    Layouts::set('referenti', new TracciatoFinto('Referenti'));

    return count($bottoni) === 1;
});

echo "\nRegistrazione\n";

check('il config del modulo registra i quattro tracciati con la loro chiave', function () {
    $config = require __DIR__.'/../config/module.php';
    $trovati = Layouts::all([['import' => $config['import']]]);

    return isset($trovati['contacts_people'], $trovati['contacts_companies'], $trovati['contacts_addresses'], $trovati['contacts_relations']);
});

echo "\nFreccia indietro\n";

check('vince la lista della prima estensione che ne dà una, altrimenti quella del tracciato', function () {
    $nulla = new class extends ImportExtension {};
    $gestionale = new class extends ImportExtension {
        public function backUrl(string $layout): ?string { return $layout === 'indirizzi' ? '/gestionale/clienti' : null; }
    };
    $base = static fn (): string => '/rubrica';
    $rotta = static fn (): string => throw new RuntimeException('rotta assente');

    return ImportExtensions::backUrl([$nulla, $gestionale], 'indirizzi', $base) === '/gestionale/clienti'
        && ImportExtensions::backUrl([$nulla, $gestionale], 'referenti', $base) === '/rubrica'
        && ImportExtensions::backUrl([], 'referenti', $rotta) === null;
});

Layouts::reset();
summary();
