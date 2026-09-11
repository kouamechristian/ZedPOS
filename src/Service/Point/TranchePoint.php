<?php

namespace App\Service\Point;

/**
 * La part d'une ligne de dotation dans le vendu, à son prix figé. Montants en
 * centimes, quantités en millièmes.
 */
final readonly class TranchePoint
{
    public function __construct(
        public \DateTimeImmutable $date,
        public int $quantiteConfiee,
        public int $quantiteVendue,
        public int $prixVente,
        public int $prixCession,
        public int $montantAttendu,
        public int $marge,
        /** Le bon de la ligne ({@see DotationPoint::$ordre}) : avec le produit, désigne la ligne de dotation. */
        public int $ordre = 0,
    ) {
    }
}
