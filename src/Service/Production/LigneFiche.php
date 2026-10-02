<?php

namespace App\Service\Production;

use App\Entity\Article;

/**
 * Une ligne de la fiche de production telle qu'on la lit : ce qui a été
 * produit et mis en vitrine (déclaré ou repris de la caisse précédente), et ce
 * que la caisse a vendu (lu dans les ventes). Quantités en millièmes ;
 * `montant` : ce que les ventes de l'article ont rapporté, en centimes.
 *
 *     en vitrine = repris de la caisse précédente + mis en vitrine
 *     reste      = en vitrine − vendu
 *     au fournil = produit − mis en vitrine        (la reprise n'a pas été produite)
 *
 * Le reste peut être **négatif** : la caisse vend sans attendre la déclaration,
 * un croissant encaissé avant d'être mis en vitrine s'y lit tel quel.
 */
final readonly class LigneFiche
{
    public function __construct(
        public Article $article,
        public int $produite,
        /** Reprise comprise. */
        public int $vitrine,
        public int $vendue,
        public int $montant = 0,
        public int $reprise = 0,
    ) {
    }

    public function reste(): int
    {
        return $this->vitrine - $this->vendue;
    }

    public function auFournil(): int
    {
        return $this->produite - ($this->vitrine - $this->reprise);
    }

    public function estVide(): bool
    {
        return 0 === $this->produite && 0 === $this->vitrine && 0 === $this->vendue;
    }
}
