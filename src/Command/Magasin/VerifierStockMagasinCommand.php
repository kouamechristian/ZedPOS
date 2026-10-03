<?php

namespace App\Command\Magasin;

use App\Service\Magasin\MagasinStockService;
use App\Service\Magasin\QuantiteMagasin;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Contrôle de l'invariant du magasin : chaque stock égale la somme de ses
 * mouvements.
 *
 * Ne corrige rien, et c'est voulu : en temps normal il ne trouve aucun écart,
 * et un écart signale une écriture faite hors de `MagasinStockService` (un SQL
 * direct, une restauration partielle) — à comprendre avant d'y toucher. Sort en
 * **code 1** s'il en trouve, pour pouvoir le surveiller en tâche planifiée.
 */
#[AsCommand(name: 'magasin:stock:verifier', description: 'Vérifie que le stock du magasin égale la somme de ses mouvements.')]
class VerifierStockMagasinCommand extends Command
{
    public function __construct(private readonly MagasinStockService $stock)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $ecarts = $this->stock->verifier();

        if ([] === $ecarts) {
            $io->success('Stock du magasin cohérent : chaque stock égale la somme de ses mouvements.');

            return Command::SUCCESS;
        }

        $io->error(\sprintf('%d écart(s) entre le stock et ses mouvements.', \count($ecarts)));
        $io->table(
            ['Produit', 'Emplacement', 'Stock', 'Somme des mouvements'],
            array_map(static fn (array $e): array => [
                $e['produit'],
                $e['emplacement'],
                QuantiteMagasin::nombre($e['stock']),
                QuantiteMagasin::nombre($e['mouvements']),
            ], $ecarts),
        );

        return Command::FAILURE;
    }
}
