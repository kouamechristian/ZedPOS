<?php

namespace App\Service;

/**
 * Résultat du calcul de coût de revient / marge d'un article.
 *
 * Tous les montants sont en centimes de FCFA ; les taux sont en points de base
 * (6000 = 60,00 %).
 */
final readonly class ResultatCout
{
    public function __construct(
        /** Le coût est-il calculable (l'article possède-t-il une fiche technique renseignée) ? */
        public bool $calculable,
        /** Coût de revient matières, ajusté des pertes, en centimes. */
        public int $coutRevient,
        /** Marge brute (prix de vente − coût de revient), en centimes. */
        public int $margeBrute,
        /** Taux de marge en points de base (marge / prix de vente). */
        public int $tauxMargeBp,
        /** Seuil d'alerte appliqué, en points de base. */
        public int $seuilBp,
        /** La marge est-elle passée sous le seuil ? */
        public bool $sousSeuil,
    ) {
    }

    public function tauxMargePourcent(): float
    {
        return $this->tauxMargeBp / 100;
    }

    public function seuilPourcent(): float
    {
        return $this->seuilBp / 100;
    }
}
