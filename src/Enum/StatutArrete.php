<?php

namespace App\Enum;

/**
 * BROUILLON : la saisie du point est gardée, rien n'a touché au stock.
 * VALIDE : immuable. ANNULE : défait par une annulation tracée — l'arrêté reste,
 * pour que le journal et la numérotation ne perdent pas sa trace.
 */
enum StatutArrete: string
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
