<?php

namespace App\Enum;

/**
 * BROUILLON : saisi sur un point encore en brouillon, sans mouvement de stock.
 * VALIDE : écrit au stock, à la validation de l'arrêté. ANNULE : contre-passé
 * avec l'arrêté.
 */
enum StatutBonRetour: string
{
    case BROUILLON = 'BROUILLON';
    case VALIDE = 'VALIDE';
    case ANNULE = 'ANNULE';
}
