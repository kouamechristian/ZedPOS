<?php

namespace App\Service;

/**
 * Stockage des images de touches produits.
 *
 * Point d'entrée unique : le dépôt sur disque, le redimensionnement et la
 * suppression vivent dans {@see StockageImages}. Les contrôleurs ne manipulent
 * ni chemin ni `move()`.
 */
class ImageArticle extends StockageImages
{
    /**
     * Côté maximal, en pixels.
     *
     * Une touche fait ~92 px de large ; 400 laisse la marge nécessaire aux écrans
     * à forte densité (×3) et aux grilles plus larges, sans stocker de photo
     * d'imprimerie.
     */
    private const COTE_MAX = 400;

    public function __construct(string $repertoire)
    {
        parent::__construct($repertoire, '/uploads/articles/', self::COTE_MAX);
    }
}
