<?php

namespace App\Service\Magasin;

/**
 * Mouvement refusé : il aurait laissé un stock du magasin négatif.
 *
 * Une `\DomainException` comme les autres refus métier : les contrôleurs
 * l'affichent telle quelle. Le message dit le produit, la zone, ce qui reste et
 * ce qui était demandé — dans l'unité de stock et, s'il y en a une, en unités
 * d'achat : « 18 sacs (900 kg) ».
 */
final class StockMagasinInsuffisantException extends \DomainException
{
    public function __construct(
        public readonly string $produit,
        public readonly string $emplacement,
        public readonly int $disponible,
        public readonly int $demande,
        string $disponibleLisible,
        string $demandeLisible,
    ) {
        parent::__construct(\sprintf(
            'Stock insuffisant pour « %s » (%s) : %s disponible, %s demandé.',
            $produit,
            $emplacement,
            $disponibleLisible,
            $demandeLisible,
        ));
    }
}
