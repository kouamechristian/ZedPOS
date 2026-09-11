<?php

namespace App\Enum;

/**
 * Nature d'un emplacement de stock.
 *
 * - DEPOT : là où le stock est reçu, fabriqué et compté (la boutique) ;
 * - STAND : un point de vente détaché, approvisionné depuis un dépôt et qui y
 *   rend ses invendus.
 */
enum TypeEmplacement: string
{
    case DEPOT = 'DEPOT';
    case STAND = 'STAND';

    public function libelle(): string
    {
        return match ($this) {
            self::DEPOT => 'Dépôt',
            self::STAND => 'Stand',
        };
    }
}
