<?php

namespace App\Enum;

/**
 * Rythme habituel du point avec le vendeur d'un stand.
 *
 * **Une valeur proposée, jamais une contrainte** : elle pré-remplit la période à
 * l'écran de l'arrêté, et rien ne refuse un point fait plus tôt ou plus tard.
 */
enum PeriodicitePoint: string
{
    case JOUR = 'JOUR';
    case SEMAINE = 'SEMAINE';
    case MOIS = 'MOIS';

    public function libelle(): string
    {
        return match ($this) {
            self::JOUR => 'Chaque jour',
            self::SEMAINE => 'Chaque semaine',
            self::MOIS => 'Chaque mois',
        };
    }
}
