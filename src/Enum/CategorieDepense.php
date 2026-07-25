<?php

namespace App\Enum;

/**
 * Catégories de dépenses réglées en espèces depuis le tiroir-caisse.
 */
enum CategorieDepense: string
{
    case APPROVISIONNEMENT = 'APPROVISIONNEMENT';
    case TRANSPORT = 'TRANSPORT';
    case ENTRETIEN = 'ENTRETIEN';
    case ELECTRICITE_EAU = 'ELECTRICITE_EAU';
    case PETIT_EQUIPEMENT = 'PETIT_EQUIPEMENT';
    case AVANCE_PERSONNEL = 'AVANCE_PERSONNEL';
    case DIVERS = 'DIVERS';

    public function libelle(): string
    {
        return match ($this) {
            self::APPROVISIONNEMENT => 'Approvisionnement',
            self::TRANSPORT => 'Transport / livraison',
            self::ENTRETIEN => 'Entretien / nettoyage',
            self::ELECTRICITE_EAU => 'Électricité / eau',
            self::PETIT_EQUIPEMENT => 'Petit équipement',
            self::AVANCE_PERSONNEL => 'Avance au personnel',
            self::DIVERS => 'Divers',
        };
    }
}
