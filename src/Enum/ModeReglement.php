<?php

namespace App\Enum;

/**
 * Moyens de paiement acceptés (espèces + mobile money courants en Côte d'Ivoire).
 */
enum ModeReglement: string
{
    case ESPECES = 'ESPECES';
    case WAVE = 'WAVE';
    case ORANGE_MONEY = 'ORANGE_MONEY';
    case MTN_MOMO = 'MTN_MOMO';
    case MOOV_MONEY = 'MOOV_MONEY';
    case CREDIT = 'CREDIT';
}
