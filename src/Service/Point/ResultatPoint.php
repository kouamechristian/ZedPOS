<?php

namespace App\Service\Point;

/**
 * Résultat du point d'un stand sur une période. Montants en centimes de FCFA,
 * quantités en millièmes.
 */
final readonly class ResultatPoint
{
    /** @param list<LignePoint> $lignes */
    public function __construct(
        public array $lignes,
        public int $qteConfiee,
        public int $qteRetournee,
        public int $qtePerdue,
        public int $qteVendue,
        public int $montantAttendu,
        public int $remuneration,
        public int $netARemettre,
        public ?int $montantRemis,
        public ?int $ecart,
    ) {
    }

    public function ligne(int $produitId): ?LignePoint
    {
        foreach ($this->lignes as $ligne) {
            if ($ligne->produitId === $produitId) {
                return $ligne;
            }
        }

        return null;
    }
}
