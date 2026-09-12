<?php

namespace App\Service\Rapport;

use App\Entity\Utilisateur;

/**
 * Rapport des ventes d'une journée, ventilé par famille de produits.
 *
 * Tous les montants sont en **centimes de FCFA** : la division par 100 n'a lieu
 * qu'à l'affichage, jamais ici.
 *
 * Trois totaux cohabitent, et il faut les trois pour que la page se relise :
 *
 * - `brutTtc`   — la somme des lignes, c'est-à-dire la somme des familles ;
 * - `remises`   — ce qui a été accordé sur les tickets ;
 * - `totalTtc`  — ce qui a réellement été encaissé, `brutTtc − remises`.
 *
 * Sans la ligne de remise, la somme des familles ne retomberait pas sur le total
 * du bas de page et le rapport paraîtrait faux — alors qu'il ne le serait pas.
 * C'est la même discipline que les exports comptables : les colonnes de la vente
 * font foi, les lignes ne servent qu'à ventiler.
 */
final readonly class VentesDuJour
{
    /**
     * @param list<FamilleVendue>    $familles      ventilation, dans l'ordre de la caisse
     * @param list<ReglementVentile> $reglements    ventilation par mode de règlement
     * @param ?Utilisateur           $caissier      caissière retenue, ou null pour toute l'équipe
     * @param int                    $tickets       tickets validés
     * @param int                    $annulations   tickets annulés (exclus de tous les montants)
     * @param int                    $montantAnnule montant des tickets annulés, en centimes
     */
    public function __construct(
        public \DateTimeImmutable $jour,
        public ?Utilisateur $caissier,
        public array $familles,
        public array $reglements,
        public int $brutTtc,
        public int $remises,
        public int $totalTtc,
        public int $totalHt,
        public int $totalTva,
        public int $quantite,
        public int $tickets,
        public int $annulations,
        public int $montantAnnule,
    ) {
    }

    /** Panier moyen en centimes ; zéro sans ticket, jamais une division par zéro. */
    public function panierMoyen(): int
    {
        return $this->tickets > 0 ? intdiv($this->totalTtc, $this->tickets) : 0;
    }

    /** Nombre d'articles distincts vendus, toutes familles confondues. */
    public function articlesDistincts(): int
    {
        $total = 0;
        foreach ($this->familles as $famille) {
            $total += \count($famille->articles);
        }

        return $total;
    }

    public function estVide(): bool
    {
        return 0 === $this->tickets && 0 === $this->annulations;
    }

    /** Intitulé de la caissière retenue, pour l'en-tête et le nom du fichier. */
    public function libelleCaissier(): string
    {
        return $this->caissier?->getNom() ?? 'Toutes les caissières';
    }
}
