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
}
