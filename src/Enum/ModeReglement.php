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

    public function libelle(): string
    {
        return match ($this) {
            self::ESPECES => 'Espèces',
            self::WAVE => 'Wave',
            self::ORANGE_MONEY => 'Orange Money',
            self::MTN_MOMO => 'MTN MoMo',
            self::MOOV_MONEY => 'Moov Money',
            self::CREDIT => 'Crédit',
        };
    }
}
