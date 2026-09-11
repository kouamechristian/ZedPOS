<?php

namespace App\Enum;

/**
 * Pas d'agrégation des rapports des stands : il découpe les lignes des tableaux et
 * l'axe des graphiques. Semaine du lundi au dimanche, comme partout ailleurs.
 */
enum GranulariteRapport: string
{
    case JOUR = 'jour';
    case SEMAINE = 'semaine';
    case MOIS = 'mois';

    public function libelle(): string
    {
        return match ($this) {
            self::JOUR => 'Jour',
            self::SEMAINE => 'Semaine',
            self::MOIS => 'Mois',
        };
    }

    /** Premier jour de la tranche qui contient `$jour`. */
    public function debutTranche(\DateTimeImmutable $jour): \DateTimeImmutable
    {
        $jour = $jour->setTime(0, 0);

        return match ($this) {
            self::JOUR => $jour,
            self::SEMAINE => $jour->modify('monday this week'),
            self::MOIS => $jour->modify('first day of this month'),
        };
    }

    public function suivante(\DateTimeImmutable $debut): \DateTimeImmutable
    {
        return match ($this) {
            self::JOUR => $debut->modify('+1 day'),
            self::SEMAINE => $debut->modify('+7 days'),
            self::MOIS => $debut->modify('first day of next month'),
        };
    }

    /** Clé stable d'une tranche : la date de son premier jour. */
    public function cle(\DateTimeImmutable $jour): string
    {
        return $this->debutTranche($jour)->format('Y-m-d');
    }

    /** Libellé court, pour une ligne de tableau ou un axe de graphique. */
    public function libelleTranche(\DateTimeImmutable $debut): string
    {
        static $mois = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

        return match ($this) {
            self::JOUR => $debut->format('d/m'),
            self::SEMAINE => 'sem. du '.$debut->format('d/m'),
            self::MOIS => $mois[(int) $debut->format('n') - 1].' '.$debut->format('Y'),
        };
    }
}
