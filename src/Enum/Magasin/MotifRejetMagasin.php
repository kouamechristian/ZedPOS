<?php

namespace App\Enum\Magasin;

/**
 * Pourquoi l'inspection rejette une partie de la livraison. Une quantité
 * rejetée n'entre jamais en stock ; le motif dit au fournisseur ce qu'il reprend.
 */
enum MotifRejetMagasin: string
{
    case AVARIE = 'AVARIE';
    case CASSE = 'CASSE';
    case PERIME = 'PERIME';
    case NON_CONFORME = 'NON_CONFORME';
    case AUTRE = 'AUTRE';

    public function libelle(): string
    {
        return match ($this) {
            self::AVARIE => 'Avarié',
            self::CASSE => 'Cassé',
            self::PERIME => 'Périmé',
            self::NON_CONFORME => 'Non conforme',
            self::AUTRE => 'Autre',
        };
    }
}
