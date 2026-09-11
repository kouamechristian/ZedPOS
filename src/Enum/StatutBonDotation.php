<?php

namespace App\Enum;

enum StatutBonDotation: string
{
    case BROUILLON = 'BROUILLON';
    case VALIDE = 'VALIDE';
    case ANNULE = 'ANNULE';

    public function libelle(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon',
            self::VALIDE => 'Validé',
            self::ANNULE => 'Annulé',
        };
    }
}
