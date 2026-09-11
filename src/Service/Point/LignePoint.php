<?php

namespace App\Service\Point;

/**
 * Le point d'un produit sur la période. Quantités en millièmes, montants en
 * centimes.
 *
 * `remuneration` est celle de la ligne : exacte en MARGE ; en COMMISSION, arrondie
 * au franc pour la lecture — le total de l'arrêté est calculé sur le montant
 * total, pas en additionnant ces arrondis.
 */
final readonly class LignePoint
{
    /** @param list<TranchePoint> $tranches du plus ancien au plus récent */
    public function __construct(
        public int $produitId,
        public string $produit,
        public string $unite,
        public int $qteConfiee,
        public int $qteRetournee,
        public int $qtePerdue,
        public int $qteVendue,
        public int $montantAttendu,
        public int $remuneration,
        public array $tranches,
    ) {
    }

    /** Un seul prix sur la période ? Sinon l'écran affiche la fourchette. */
    public function prixUnique(): ?int
    {
        $prix = array_unique(array_map(static fn (TranchePoint $t): int => $t->prixVente, $this->tranches));

        return 1 === \count($prix) ? reset($prix) : null;
    }
}
