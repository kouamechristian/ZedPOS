<?php

namespace App\Enum\Magasin;

/**
 * Pourquoi la marchandise sort. Production : la consommation normale. Les autres
 * motifs sont des sorties sans consommation (marchandise avariée jetée, renvoyée
 * au fournisseur) — c'est ce que l'analyse doit pouvoir isoler.
 */
enum MotifSortieMagasin: string
{
    case PRODUCTION = 'PRODUCTION';
    case PERTE_AVARIE = 'PERTE_AVARIE';
    case RETOUR_FOURNISSEUR = 'RETOUR_FOURNISSEUR';
    case AUTRE = 'AUTRE';

    public function libelle(): string
    {
        return match ($this) {
            self::PRODUCTION => 'Production',
            self::PERTE_AVARIE => 'Perte / avarie',
            self::RETOUR_FOURNISSEUR => 'Retour fournisseur',
            self::AUTRE => 'Autre',
        };
    }
}
