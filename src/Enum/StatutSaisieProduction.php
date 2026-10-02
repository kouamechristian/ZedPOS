<?php

namespace App\Enum;

/**
 * Une déclaration naît validée : il n'y a pas de brouillon à l'atelier, on déclare
 * ce qui vient de sortir du four. Une erreur s'annule (motif obligatoire, tracé)
 * et se ressaisit.
 */
enum StatutSaisieProduction: string
{
    case VALIDEE = 'VALIDEE';
    case ANNULEE = 'ANNULEE';

    public function libelle(): string
    {
        return match ($this) {
            self::VALIDEE => 'Validée',
            self::ANNULEE => 'Annulée',
        };
    }
}
