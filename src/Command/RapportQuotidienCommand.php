<?php

namespace App\Command;

use App\Service\RapportQuotidienTexte;
use App\Service\SyntheseJourneeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Synthèse de la journée en texte court, prête à être envoyée par WhatsApp ou
 * e-mail. La commande **n'envoie rien** : elle écrit le message sur la sortie
 * standard, à charge du cron de le transmettre.
 *
 * Planification à 21h30, après la clôture des caisses :
 *
 *     30 21 * * * cd /var/www/zedpos && php bin/console app:rapport-quotidien >> var/log/rapport.txt
 *
 * ou, pour un envoi direct par e-mail :
 *
 *     30 21 * * * cd /var/www/zedpos && php bin/console app:rapport-quotidien \
 *       | mail -s "ZedPOS - rapport du jour" dirigeante@exemple.ci
 */
#[AsCommand(
    name: 'app:rapport-quotidien',
    description: "Génère la synthèse du jour en texte court (WhatsApp / e-mail).",
)]
class RapportQuotidienCommand extends Command
{
    public function __construct(
        private readonly SyntheseJourneeService $syntheses,
        private readonly RapportQuotidienTexte $miseEnForme,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('date', 'd', InputOption::VALUE_REQUIRED, "Journée à rapporter au format AAAA-MM-JJ (par défaut : aujourd'hui)")
            ->addOption('fichier', 'f', InputOption::VALUE_REQUIRED, 'Écrit le message dans ce fichier au lieu de la sortie standard')
            ->setHelp(<<<'AIDE'
                Écrit sur la sortie standard la synthèse de la journée : chiffre d'affaires et
                comparaisons, tickets, panier moyen, règlements, top 5 des produits et points
                de vigilance (annulations, remises, écart de caisse, pertes, stock bas).

                  <info>php %command.full_name%</info>                     Journée en cours
                  <info>php %command.full_name% --date=2026-07-24</info>   Journée précise
                  <info>php %command.full_name% -f rapport.txt</info>      Écriture dans un fichier

                Planification à 21h30 (crontab) :

                  <comment>30 21 * * * cd /var/www/zedpos && php bin/console app:rapport-quotidien</comment>
                AIDE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $jour = null;
        $saisie = $input->getOption('date');
        if (null !== $saisie) {
            $jour = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $saisie);
            if (false === $jour) {
                $io->error('Date invalide. Format attendu : AAAA-MM-JJ.');

                return Command::INVALID;
            }
        }

        $message = $this->miseEnForme->construire($this->syntheses->construire($jour));

        $fichier = $input->getOption('fichier');
        if (null !== $fichier) {
            if (false === file_put_contents((string) $fichier, $message."\n")) {
                $io->error(\sprintf('Impossible d\'écrire dans « %s ».', $fichier));

                return Command::FAILURE;
            }

            $io->success(\sprintf('Rapport écrit dans « %s ».', $fichier));

            return Command::SUCCESS;
        }

        // Sortie brute, sans décoration : le message part tel quel dans un canal texte.
        $output->writeln($message, OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}
