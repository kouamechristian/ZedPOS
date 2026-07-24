<?php

namespace App\Enum;

enum TypeMouvementStock: string
{
    case ENTREE = 'ENTREE';
    case SORTIE_VENTE = 'SORTIE_VENTE';
    case SORTIE_PRODUCTION = 'SORTIE_PRODUCTION';
    case PERTE = 'PERTE';
    case INVENTAIRE = 'INVENTAIRE';
}
