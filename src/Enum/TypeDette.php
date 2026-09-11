<?php

namespace App\Enum;

/**
 * Origine d'une dette de vendeur.
 *
 * ECART_CAISSE ne naît **que** d'un point validé dont la gérante a choisi d'imputer
 * le manquant au vendeur ; AVANCE et AUTRE se saisissent à la main, sur la fiche du
 * vendeur, avec un commentaire obligatoire.
 */
enum TypeDette: string
{
    case ECART_CAISSE = 'ECART_CAISSE';
    case AVANCE = 'AVANCE';
    case AUTRE = 'AUTRE';

    public function libelle(): string
    {
        return match ($this) {
            self::ECART_CAISSE => 'Manquant au point',
            self::AVANCE => 'Avance',
            self::AUTRE => 'Autre',
        };
    }

    /** @return list<self> ce qui se saisit à la main */
    public static function manuelles(): array
    {
        return [self::AVANCE, self::AUTRE];
    }
}
