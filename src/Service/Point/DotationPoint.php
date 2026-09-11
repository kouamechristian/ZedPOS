<?php

namespace App\Service\Point;

/**
 * Une ligne de dotation validée, telle que le calcul du point la voit : quantité
 * et **prix figés sur la ligne**, jamais relus sur le produit.
 */
final readonly class DotationPoint
{
    /**
     * @param int $ordre       id du bon : départage deux dotations du même jour (ordre de saisie) et, avec le produit, désigne la ligne
     * @param int $quantite    millièmes d'unité
     * @param int $prixVente   centimes, figé à la validation du bon
     * @param int $prixCession centimes, figé à la validation du bon
     */
    public function __construct(
        public int $produitId,
        public string $produit,
        public string $unite,
        public \DateTimeImmutable $date,
        public int $ordre,
        public int $quantite,
        public int $prixVente,
        public int $prixCession,
    ) {
    }
}
