<?php

namespace App\Enum;

/**
 * Nature de la période d'un arrêté, **déduite des dates**, jamais déclarée : un
 * jour si début et fin coïncident, une semaine si la fin est le dimanche de la
 * semaine du début, un mois si c'est le dernier jour de son mois, libre sinon.
 * Voir {@see \App\Service\Point\PeriodeArrete::granularite()}.
 */
enum GranulariteArrete: string
{
    case JOUR = 'JOUR';
    case SEMAINE = 'SEMAINE';
    case MOIS = 'MOIS';
    case LIBRE = 'LIBRE';

    public function libelle(): string
    {
        return match ($this) {
            self::JOUR => 'Jour',
            self::SEMAINE => 'Semaine',
            self::MOIS => 'Mois',
            self::LIBRE => 'Période libre',
        };
    }
}
