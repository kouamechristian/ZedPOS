<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\LigneFicheTechnique;

/**
 * Calcule le coût de revient matières, la marge brute et le taux de marge d'un
 * article à partir de sa fiche technique, et signale une marge sous le seuil.
 *
 * Convention monétaire : tout est en entiers.
 * - quantité      : millièmes d'unité de stock
 * - coût matière  : centimes de FCFA par unité
 * - pourcentages  : points de base (500 = 5,00 %)
 */
class CalculateurCoutMatiere
{
    /**
     * @param int $seuilMargeParDefautBp Seuil d'alerte par défaut, en points de base (6000 = 60 %)
     */
    public function __construct(private readonly int $seuilMargeParDefautBp = 6000)
    {
    }

    /**
     * @param int|null $seuilBp Seuil d'alerte spécifique, en points de base (null = valeur par défaut)
     */
    public function calculer(Article $article, ?int $seuilBp = null): ResultatCout
    {
        $seuil = $seuilBp ?? $this->seuilMargeParDefautBp;
        $fiche = $article->getFicheTechnique();

        $calculable = false;
        $coutRevient = 0;
        if (null !== $fiche) {
            foreach ($fiche->getLignes() as $ligne) {
                $calculable = true;
                $coutRevient += $this->coutLigne($ligne);
            }
        }

        $prix = $article->getPrixVenteTtc();
        $margeBrute = $prix - $coutRevient;
        $tauxMargeBp = $prix > 0 ? intdiv($margeBrute * 10000, $prix) : 0;
        $sousSeuil = $calculable && $prix > 0 && $tauxMargeBp < $seuil;

        return new ResultatCout($calculable, $coutRevient, $margeBrute, $tauxMargeBp, $seuil, $sousSeuil);
    }

    /**
     * Coût d'une ligne, en centimes, ajusté du taux de perte de transformation.
     *
     * coût = quantité × coût unitaire, majoré pour compenser la perte :
     *        coût_ajusté = coût_brut / (1 − perte)
     * Calcul en une seule division entière arrondie au centime le plus proche.
     */
    private function coutLigne(LigneFicheTechnique $ligne): int
    {
        $quantite = $ligne->getQuantite();                                  // millièmes d'unité
        $coutUnite = $ligne->getMatierePremiere()->getCoutMoyenPondere();   // centimes / unité
        $perteBp = max(0, min($ligne->getPourcentagePerte(), 9999));        // borné à < 100 %

        // coût = quantité × coûtUnité / 1000  ÷  (1 − perteBp/10000)
        $numerateur = $quantite * $coutUnite * 10000;
        $denominateur = 1000 * (10000 - $perteBp);

        return intdiv($numerateur + intdiv($denominateur, 2), $denominateur);
    }
}
