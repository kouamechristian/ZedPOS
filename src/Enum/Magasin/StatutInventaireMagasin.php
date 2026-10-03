<?php

namespace App\Enum\Magasin;

/**
 * En cours → validé, ou abandonné. Seule la validation touche au stock ; une
 * feuille abandonnée reste en base (numéro, auteur), sans aucun mouvement.
 */
enum StatutInventaireMagasin: string
{
    case EN_COURS = 'EN_COURS';
    case VALIDE = 'VALIDE';
    case ABANDONNE = 'ABANDONNE';

    public function libelle(): string
    {
        return match ($this) {
            self::EN_COURS => 'En cours',
            self::VALIDE => 'Validé',
            self::ABANDONNE => 'Abandonné',
        };
    }
}
