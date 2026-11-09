<?php

namespace Wonder\Plugin\CustomerAccount\Console;

use mysqli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Wonder\Plugin\CustomerAccount\Migrations\CompanySplitMigration;
use Wonder\Sql\Connection;

/**
 * `php forge customer-account:companies` — cosa farà la separazione delle
 * aziende dai contatti al prossimo `forge update`. Non scrive niente.
 */
final class CompaniesCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('customer-account:companies')
            ->setDescription('Mostra quali contatti diventeranno aziende al prossimo forge update, senza toccarli');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        self::boot();

        $mysqli = ($GLOBALS['mysqli'] ?? null) instanceof mysqli ? $GLOBALS['mysqli'] : Connection::Connect('main');

        $plan = CompanySplitMigration::plan($mysqli);

        if (!$plan['needed']) {
            $output->writeln('Aziende già separate dai contatti: niente da fare.');

            return Command::SUCCESS;
        }

        $lines = [
            'moved' => 'Diventano aziende (la scheda contatto va nel cestino)',
            'linked' => 'Restano persone con account e si collegano a una nuova azienda (accesso admin)',
            'supplier' => 'Privati fornitori: nasce un\'azienda fornitore collegata',
            'skipped' => 'Aziende senza ragione sociale: restano contatti, da sistemare a mano',
        ];

        foreach ($lines as $key => $label) {
            $ids = $plan[$key];
            $output->writeln(sprintf('%s: %d%s', $label, count($ids), $ids === [] ? '' : ' (#'.implode(', #', $ids).')'));
        }

        $output->writeln('Riferimenti esterni da rinominare («contacts» → «contact»): '.$plan['references']);
        $output->writeln('Si applica con forge update.');

        return Command::SUCCESS;
    }

    /** `forge` da solo non apre il DB: lo apre il bootstrap del sito, come in `update`. */
    private static function boot(): void
    {
        $root = getcwd() ?: '.';
        $bootstrap = $root.'/vendor/wonder-image/app/wonder-image.php';

        if (function_exists('sqlTable') || !file_exists($bootstrap)) {
            return;
        }

        $GLOBALS['ROOT'] = $root;

        require_once $bootstrap;
    }
}
