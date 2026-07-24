<?php

namespace App\Service;

use App\Entity\Vente;

final readonly class ResultatEncaissement
{
    public function __construct(
        public Vente $vente,
        /** Rendu de monnaie, en centimes de FCFA. */
        public int $rendu,
        /** Vraie si la requête a été rejouée (idempotence) et non recréée. */
        public bool $rejoue,
    ) {
    }
}
