<?php

namespace App\Enum;

/**
 * Comment le vendeur d'un stand est rémunéré sur ce qu'il vend.
 *
 * - COMMISSION : un taux du montant attendu, au prix de vente ;
 * - MARGE : la différence entre prix de vente et prix de cession, ligne par ligne.
 *
 * Fixé par la dirigeante seule, sur le stand : c'est ce que la boutique encaisse.
 */
enum ModeRemuneration: string
{
    case COMMISSION = 'COMMISSION';
    case MARGE = 'MARGE';

    public function libelle(): string
    {
        return match ($this) {
            self::COMMISSION => 'Commission sur les ventes',
            self::MARGE => 'Marge (prix de vente − prix de cession)',
        };
    }
}
