<?php

namespace App\Command;

use App\Comptabilite\FormatExport;
use App\Comptabilite\JeuEcritures;
use App\Service\Comptabilite\ExportComptable;
use App\Service\Comptabilite\GenerateurEcrituresSyscohada;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Export comptable SYSCOHADA d'une période, depuis la console.
 *
 * Même service que l'écran `/comptabilite` : les fichiers produits ici et ceux
 * téléchargés depuis le navigateur sont identiques, octet pour octet. La console
 * sert la transmission mensuelle au cabinet, qu'on planifie ou qu'on scripte.
 *
 * Envoi automatique le 1er de chaque mois, pour le mois écoulé :
 *
 *     0 7 1 * * cd /var/www/zedpos && php bin/console app:export-comptable \
 *       --mois=$(date -d 'last month' +\%Y-\%m) --format=fec -o /tmp/zedpos.txt
 */
#[AsCommand(
    name: 'app:export-comptable',
    description: 'Exporte les écritures comptables SYSCOHADA d\'une période.',
)]
class ExportComptableCommand extends Command
{
    public function __construct(
        private readonly GenerateurEcrituresSyscohada $generateur,
        private readonly ExportComptable $export,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $formats = implode(', ', array_column(FormatExport::cases(), 'value'));

        $this
            ->addOption('mois', 'm', InputOption::VALUE_REQUIRED, 'Mois complet au format AAAA-MM (raccourci pour --du/--au)')
            ->addOption('du', null, InputOption::VALUE_REQUIRED, 'Début de période au format AAAA-MM-JJ')
            ->addOption('au', null, InputOption::VALUE_REQUIRED, 'Fin de période (incluse) au format AAAA-MM-JJ')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Format : '.$formats, FormatExport::ECRITURES_CSV->value)
            ->addOption('sortie', 'o', InputOption::VALUE_REQUIRED, 'Fichier de destination (par défaut : sortie standard)')
            ->setHelp(<<<'AIDE'
                Produit les écritures comptables d'une période au plan SYSCOHADA révisé :
                journal des ventes (centralisation par rapport Z), journal de caisse
                (dépenses, sorties, écarts) et opérations diverses (pertes valorisées).

                  <info>php %command.full_name% --mois=2026-06 --format=fec -o juin.txt</info>
                  <info>php %command.full_name% --du=2026-06-01 --au=2026-06-30</info>
                  <info>php %command.full_name% --format=balance</info>

                Sans option de période, le mois en cours est exporté jusqu'à aujourd'hui.

                La commande signale en erreur tout contrôle non conforme (chiffre d'affaires,
                TVA, espèces, mouvements de caisse) : un fichier ne doit pas partir au cabinet
                sans que ces rapprochements soient justes.
                AIDE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $format = FormatExport::tryFrom((string) $input->getOption('format'));
        if (null === $format) {
            $io->error(\sprintf(
                'Format inconnu. Valeurs acceptées : %s.',
                implode(', ', array_column(FormatExport::cases(), 'value')),
            ));

            return Command::INVALID;
        }

        try {
            [$du, $au] = $this->periode($input);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $jeu = $this->generateur->construire($du, $au);
        $contenu = $this->export->rendre($jeu, $format);

        $destination = $input->getOption('sortie');
        if (null !== $destination) {
            if (false === file_put_contents((string) $destination, $contenu)) {
                $io->error(\sprintf('Impossible d\'écrire dans « %s ».', $destination));

                return Command::FAILURE;
            }

            $io->success(\sprintf(
                '%s : %d écriture(s), %d ligne(s) du %s au %s → %s',
                $format->libelle(),
                $jeu->nombreEcritures(),
                $jeu->nombreLignes(),
                $du->format('d/m/Y'),
                $au->format('d/m/Y'),
                $destination,
            ));
        } else {
            // Sortie brute : le fichier peut être redirigé ou passé à un tube.
            $output->write($contenu, false, OutputInterface::OUTPUT_RAW);
        }

        return $this->rapporterControles($io, $jeu, null !== $destination);
    }

    /**
     * Les contrôles ne sont rappelés que si la sortie n'est pas le flux standard :
     * les écrire à l'écran polluerait un fichier redirigé.
     */
    private function rapporterControles(SymfonyStyle $io, JeuEcritures $jeu, bool $verbeux): int
    {
        $anomalies = [];

        foreach ($jeu->controles as $controle) {
            if (!$controle->estBon()) {
                $anomalies[] = \sprintf('%s : écart de %d centimes.', $controle->libelle, $controle->ecart());
            }
        }

        if (!$jeu->estEquilibre()) {
            $anomalies[] = \sprintf(
                'Écritures déséquilibrées : %d centimes au débit contre %d au crédit.',
                $jeu->totalDebit(),
                $jeu->totalCredit(),
            );
        }

        if ([] !== $anomalies) {
            $io->getErrorStyle()->error(array_merge(['Contrôles non conformes :'], $anomalies));

            return Command::FAILURE;
        }

        if ($verbeux) {
            $io->text('Contrôles conformes : chiffre d\'affaires, TVA, espèces, mouvements de caisse et équilibre.');
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function periode(InputInterface $input): array
    {
        $mois = $input->getOption('mois');
        if (null !== $mois) {
            $debut = \DateTimeImmutable::createFromFormat('!Y-m-d', $mois.'-01');
            if (false === $debut) {
                throw new \InvalidArgumentException('Mois invalide. Format attendu : AAAA-MM.');
            }

            return [$debut, $debut->modify('last day of this month')];
        }

        $aujourdhui = new \DateTimeImmutable('today');

        $du = $this->lireDate($input->getOption('du'), 'du') ?? $aujourdhui->modify('first day of this month');
        $au = $this->lireDate($input->getOption('au'), 'au') ?? $aujourdhui;

        return $au < $du ? [$au, $du] : [$du, $au];
    }

    private function lireDate(mixed $valeur, string $option): ?\DateTimeImmutable
    {
        if (null === $valeur) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $valeur);
        if (false === $date) {
            throw new \InvalidArgumentException(\sprintf('Option --%s invalide. Format attendu : AAAA-MM-JJ.', $option));
        }

        return $date;
    }
}
