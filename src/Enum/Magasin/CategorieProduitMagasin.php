<?php

namespace App\Enum\Magasin;

/**
 * Famille d'un produit du magasin. Sert à filtrer le stock et l'analyse : la
 * farine et les sachets ne se surveillent pas avec les mêmes yeux.
 */
enum CategorieProduitMagasin: string
{
    case MATIERE = 'MATIERE';
    case EMBALLAGE = 'EMBALLAGE';
    case ENTRETIEN = 'ENTRETIEN';
    case AUTRE = 'AUTRE';

    public function libelle(): string
    {
        return match ($this) {
            self::MATIERE => 'Matière',
            self::EMBALLAGE => 'Emballage',
            self::ENTRETIEN => 'Entretien',
            self::AUTRE => 'Autre',
        };
    }
}
