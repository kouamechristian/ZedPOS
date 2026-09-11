<?php

namespace App\Service\Point;

use App\Enum\MotifRetour;

/** Un retour ou une perte sur la période. Quantité en millièmes. */
final readonly class RetourPoint
{
    public function __construct(
        public int $produitId,
        public int $quantite,
        public MotifRetour $motif,
    ) {
    }
}
