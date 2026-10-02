<?php

namespace App\Enum;

/**
 * Les deux déclarations du module Production et vitrine.
 *
 * PRODUCTION : ce que le boulanger ou le pâtissier a fabriqué.
 * VITRINE    : ce que la vendeuse a sorti du fournil pour le mettre en vente.
 */
enum TypeSaisieProduction: string
{
    case PRODUCTION = 'PRODUCTION';
    case VITRINE = 'VITRINE';

    public function libelle(): string
    {
        return match ($this) {
            self::PRODUCTION => 'Production',
            self::VITRINE => 'Mise en vitrine',
        };
    }

    /** Préfixe du numéro : PRD-AAAAMMJJ-XXX, VIT-AAAAMMJJ-XXX. */
    public function prefixe(): string
    {
        return match ($this) {
            self::PRODUCTION => 'PRD',
            self::VITRINE => 'VIT',
        };
    }
}
