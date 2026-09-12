<?php

namespace App\Service\Rapport;

/** Un mode de règlement et ce qu'il a encaissé dans la journée. */
final readonly class ReglementVentile
{
    public function __construct(
        public string $mode,
        public int $montant,
        public int $tickets,
    ) {
    }
}
