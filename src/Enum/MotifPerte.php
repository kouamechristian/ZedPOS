<?php

namespace App\Enum;

enum MotifPerte: string
{
    case CASSE = 'CASSE';
    case PERIME = 'PERIME';
    case INVENDU = 'INVENDU';
    case ERREUR_PRODUCTION = 'ERREUR_PRODUCTION';
    case PERSONNEL = 'PERSONNEL';
    case OFFERT = 'OFFERT';

    public function libelle(): string
    {
        return match ($this) {
            self::CASSE => 'Casse',
            self::PERIME => 'Périmé',
            self::INVENDU => 'Invendu',
            self::ERREUR_PRODUCTION => 'Erreur de production',
            self::PERSONNEL => 'Personnel',
            self::OFFERT => 'Offert',
        };
    }
}
