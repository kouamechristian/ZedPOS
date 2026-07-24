<?php

namespace App\Enum;

/**
 * Les deux modes de caisse de ZedPOS.
 */
enum ModeVente: string
{
    case BOULANGERIE = 'BOULANGERIE';
    case FASTFOOD = 'FASTFOOD';
}
