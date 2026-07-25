<?php

namespace App\Service;

/**
 * Photographie chiffrée d'une journée d'exploitation.
 *
 * Alimente à la fois l'écran de pilotage et la commande `app:rapport-quotidien` :
 * les deux affichent donc rigoureusement les mêmes nombres.
 *
 * Montants en centimes de FCFA ; variations en **points de base** (10 000 = +100 %),
 * pour rester en arithmétique entière comme le reste du projet.
 */
final readonly class SyntheseJournee
{
    /**
     * @param list<array{mode: string, libelle: string, nombre: int, montant: int}> $parReglement
     * @param list<array{nom: string, quantite: int, montant: int}>                 $topProduits
     * @param list<array{jour: string, ca: int}>                                    $serie30Jours
     * @param list<string>                                                          $rupturesStock noms des matières sous seuil
     */
    public function __construct(
        public \DateTimeImmutable $jour,
        public int $caJour,
        public int $caVeille,
        public int $caSemainePrecedente,
        public ?int $variationVeilleBp,
        public ?int $variationSemaineBp,
        public int $nombreTickets,
        public int $panierMoyen,
        public array $parReglement,
        public int $annulationsNombre,
        public int $annulationsMontant,
        public int $remisesNombre,
        public int $remisesMontant,
        public ?int $ecartCaisse,
        public int $sessionsCloturees,
        public array $rupturesStock,
        public int $pertesMontant,
        public int $pertesNombre,
        public array $topProduits,
        public array $serie30Jours,
    ) {
    }

    /**
     * Y a-t-il quelque chose à signaler à la dirigeante ? Sert à masquer le bloc
     * « points de vigilance » quand la journée est parfaitement propre.
     */
    public function aDesPointsDeVigilance(): bool
    {
        return $this->annulationsNombre > 0
            || $this->remisesNombre > 0
            || 0 !== (int) $this->ecartCaisse
            || [] !== $this->rupturesStock
            || $this->pertesMontant > 0;
    }

    /**
     * Variation exprimée en pourcentage signé, prête à l'affichage (« +12,5 % »).
     * Null quand la référence est nulle : aucune comparaison n'a de sens.
     */
    public function variationLisible(?int $bp): ?string
    {
        if (null === $bp) {
            return null;
        }

        $pourcent = $bp / 100;

        return \sprintf('%+.1f %%', $pourcent);
    }
}
