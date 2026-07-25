<?php

namespace App\Service;

use App\Entity\MouvementCaisse;
use App\Entity\SessionCaisse;

/**
 * Synthèse chiffrée d'une session de caisse, indépendante du support.
 *
 * Le même objet alimente le **ticket X** (photographie intermédiaire, la session
 * reste ouverte) et le **rapport Z** (clôture définitive) : seul `definitif`
 * change, ainsi que la présence du montant compté et de l'écart.
 *
 * Tous les montants sont en centimes de FCFA.
 *
 * @phpstan-type LigneReglement array{mode: string, libelle: string, nombre: int, montant: int}
 * @phpstan-type LigneFamille array{famille: string, quantite: int, montant: int}
 */
final readonly class RapportCaisse
{
    /**
     * @param list<array{mode: string, libelle: string, nombre: int, montant: int}> $parReglement ventilation par moyen de paiement (somme = CA)
     * @param list<array{famille: string, quantite: int, montant: int}>             $parFamille   CA brut par famille de produits
     * @param list<MouvementCaisse>                                                 $mouvements   dépenses et sorties de la session
     */
    public function __construct(
        public SessionCaisse $session,
        public bool $definitif,
        public \DateTimeImmutable $genereA,
        public int $caTotal,
        public int $totalHt,
        public int $totalTva,
        public int $nombreTickets,
        public int $panierMoyen,
        public array $parReglement,
        public array $parFamille,
        public int $remisesMontant,
        public int $remisesNombre,
        public int $annulationsMontant,
        public int $annulationsNombre,
        public int $especesEncaissees,
        public int $renduTotal,
        public int $depenses,
        public int $sorties,
        public array $mouvements,
        public int $fondCaisse,
        public int $theorique,
        public ?int $montantCompte,
        public ?int $ecart,
    ) {
    }

    public function titre(): string
    {
        return $this->definitif ? 'RAPPORT Z — CLÔTURE' : 'TICKET X — SYNTHÈSE';
    }

    /** Un écart existe-t-il (clôture seulement) ? */
    public function aUnEcart(): bool
    {
        return null !== $this->ecart && 0 !== $this->ecart;
    }
}
