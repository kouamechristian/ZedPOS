<?php

namespace App\Enum\Magasin;

/**
 * Brouillon → validée → annulée. Seule la validation fait sortir du stock ;
 * l'annulation d'une sortie validée la contre-passe.
 */
enum StatutSortieMagasin: string
{
    case BROUILLON = 'BROUILLON';
    case VALIDEE = 'VALIDEE';
    case ANNULEE = 'ANNULEE';

    public function libelle(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon',
            self::VALIDEE => 'Validée',
            self::ANNULEE => 'Annulée',
        };
    }
}
