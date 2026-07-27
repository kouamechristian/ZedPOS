<?php

namespace App\Enum;

/**
 * États d'une feuille d'inventaire.
 *
 * Deux seulement, et le passage est à sens unique : une fois validée, la feuille
 * a produit des mouvements de stock et ne peut plus être touchée. C'est la même
 * règle qu'une session de caisse clôturée.
 */
enum StatutInventaire: string
{
    case EN_COURS = 'EN_COURS';
    case VALIDE = 'VALIDE';

    public function libelle(): string
    {
        return match ($this) {
            self::EN_COURS => 'En cours',
            self::VALIDE => 'Validé',
        };
    }
}
