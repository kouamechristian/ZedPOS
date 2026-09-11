<?php

namespace App\Enum;

/**
 * Pourquoi un produit confié ne compte pas comme vendu.
 *
 * INVENDU revient au dépôt (transfert RETOUR) ; les autres sortent du stock du
 * stand sans retour (PERTE). Dans les deux cas, le vendeur ne le doit pas.
 */
enum MotifRetour: string
{
    case INVENDU = 'INVENDU';
    case CASSE = 'CASSE';
    case PERIME = 'PERIME';
    case AUTRE = 'AUTRE';

    public function libelle(): string
    {
        return match ($this) {
            self::INVENDU => 'Invendu',
            self::CASSE => 'Casse',
            self::PERIME => 'Périmé',
            self::AUTRE => 'Autre perte',
        };
    }

    public function estPerte(): bool
    {
        return self::INVENDU !== $this;
    }

    /** @return list<self> motifs de perte, dans l'ordre de l'écran */
    public static function pertes(): array
    {
        return [self::CASSE, self::PERIME, self::AUTRE];
    }
}
