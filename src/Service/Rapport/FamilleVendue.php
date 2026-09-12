<?php

namespace App\Service\Rapport;

/**
 * Une famille de produits dans le rapport de journée, et le détail de ses articles.
 */
final readonly class FamilleVendue
{
    /**
     * @param string             $nom      libellé de la famille, ou « Sans famille »
     * @param list<ArticleVendu> $articles détail, du plus vendu au moins vendu
     * @param int                $quantite quantité totale, en millièmes d'unité
     * @param int                $montant  montant TTC de la famille, en centimes
     */
    public function __construct(
        public string $nom,
        public array $articles,
        public int $quantite,
        public int $montant,
    ) {
    }
}
