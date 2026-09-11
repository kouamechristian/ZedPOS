<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\Emplacement;
use App\Entity\MatierePremiere;
use App\Enum\TypeMouvementStock;

/**
 * Un mouvement à enregistrer, en attente de `StockManager::enregistrerMouvements()`.
 *
 * Existe pour les lots : une vente déstocke plusieurs matières, une feuille
 * d'inventaire corrige plusieurs produits, et tout doit passer — ou être refusé —
 * d'un seul bloc.
 */
final readonly class DemandeMouvementStock
{
    /**
     * @param int $quantite millièmes d'unité, signée (+ entrée, − sortie), jamais nulle
     */
    public function __construct(
        public Article|MatierePremiere $produit,
        public Emplacement $emplacement,
        public int $quantite,
        public TypeMouvementStock $type,
        public ?string $documentType = null,
        public ?int $documentId = null,
        public ?string $motif = null,
    ) {
    }
}
