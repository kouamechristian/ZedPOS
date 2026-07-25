<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\MatierePremiere;

/**
 * Valorise une quantité de stock au coût moyen pondéré (matière première) ou au
 * coût de revient (article fabriqué), en centimes de FCFA.
 */
class ValorisationService
{
    public function __construct(private readonly CalculateurCoutMatiere $calculateur)
    {
    }

    /**
     * Valorisation d'une perte, en centimes : quantité (millièmes) × coût unitaire.
     */
    public function valoriser(?MatierePremiere $matiere, ?Article $article, int $quantiteMillimes): int
    {
        return intdiv($quantiteMillimes * $this->coutUnitaire($matiere, $article), 1000);
    }

    /**
     * Coût d'une unité, en centimes.
     */
    public function coutUnitaire(?MatierePremiere $matiere, ?Article $article): int
    {
        if (null !== $matiere) {
            return $matiere->getCoutMoyenPondere();
        }

        if (null !== $article) {
            $resultat = $this->calculateur->calculer($article);

            return $resultat->calculable ? $resultat->coutRevient : 0;
        }

        return 0;
    }
}
