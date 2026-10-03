<?php

namespace App\Enum\Magasin;

/**
 * Où part la marchandise qui quitte le magasin. Purement descriptif : le module
 * ne suit pas de stock à destination — sortie du magasin, la marchandise est
 * consommée, et l'analyse ventile les sorties par destination et par motif.
 */
enum DestinationSortieMagasin: string
{
    case ATELIER = 'ATELIER';
    case CUISINE = 'CUISINE';
    case BOUTIQUE = 'BOUTIQUE';
    case AUTRE = 'AUTRE';

    public function libelle(): string
    {
        return match ($this) {
            self::ATELIER => 'Atelier',
            self::CUISINE => 'Cuisine',
            self::BOUTIQUE => 'Boutique',
            self::AUTRE => 'Autre',
        };
    }
}
