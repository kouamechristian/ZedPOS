<?php

namespace App\Command;

use App\Repository\ArreteRepository;
use App\Service\ArreteService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reporte sur les lignes de dotation le vendu des points **validés avant** que les
 * rapports des stands n'existent. Un point validé depuis le fait de lui-même.
 *
 * Sans danger à rejouer : ne touche qu'aux points dont une ligne n'a pas encore sa
 * part, et recalcule depuis les documents rattachés et les taux figés — le même
 * calcul que l'écran du point.
 */
#[AsCommand(name: 'app:stands:figer-ventes', description: 'Reporte le vendu des points validés sur leurs lignes de dotation (rapports des stands).')]
class FigerVentesStandsCommand extends Command
{
    public function __construct(
        private readonly ArreteRepository $arretes,
        private readonly ArreteService $points,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $traites = 0;

        foreach ($this->arretes->validesSansVentesFigees() as $arrete) {
            $this->points->figerVentes($arrete);
            ++$traites;
        }
        $this->em->flush();

        $io->success(0 === $traites ? 'Rien à reporter : tous les points validés ont déjà leurs ventes par ligne.' : \sprintf('%d point(s) reporté(s) sur leurs lignes de dotation.', $traites));

        return Command::SUCCESS;
    }
}
