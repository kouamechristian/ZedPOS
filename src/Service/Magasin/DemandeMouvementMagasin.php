<?php

namespace App\Service\Magasin;

use App\Entity\Magasin\MagasinEmplacement;
use App\Entity\Magasin\MagasinProduit;
use App\Enum\Magasin\TypeMouvementMagasin;

/**
 * Un mouvement demandé au {@see MagasinStockService} : ce qu'un bon veut faire
 * bouger, avant contrôle. Quantité signée, en millièmes d'unité de stock.
 */
final readonly class DemandeMouvementMagasin
{
    public function __construct(
        public MagasinProduit $produit,
        public MagasinEmplacement $emplacement,
        public int $quantite,
        public TypeMouvementMagasin $type,
        public string $documentType,
        public int $documentId,
        public ?string $motif = null,
    ) {
    }
}
