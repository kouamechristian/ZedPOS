<?php

namespace App\Enum\Magasin;

/**
 * Où en est une réception dans le plan de traitement du magasin :
 *
 *     RECUE → CONTROLEE → INSPECTEE → STOCKEE      (ou ANNULEE)
 *
 * On ne saute pas d'étape, et on ne revient pas en arrière une fois l'étape
 * suivante faite. Une étape peut en revanche être reprise tant que la suivante
 * n'a pas eu lieu (un comptage refait avant l'inspection). Seul le stockage fait
 * entrer la marchandise en stock.
 */
enum StatutReceptionMagasin: string
{
    case RECUE = 'RECUE';
    case CONTROLEE = 'CONTROLEE';
    case INSPECTEE = 'INSPECTEE';
    case STOCKEE = 'STOCKEE';
    case ANNULEE = 'ANNULEE';

    public function libelle(): string
    {
        return match ($this) {
            self::RECUE => 'Reçue',
            self::CONTROLEE => 'Contrôlée',
            self::INSPECTEE => 'Inspectée',
            self::STOCKEE => 'Stockée',
            self::ANNULEE => 'Annulée',
        };
    }

    /** Rang dans le plan de traitement : 1 à 4, 0 pour une réception annulée. */
    public function rang(): int
    {
        return match ($this) {
            self::RECUE => 1,
            self::CONTROLEE => 2,
            self::INSPECTEE => 3,
            self::STOCKEE => 4,
            self::ANNULEE => 0,
        };
    }

    /** Ce qui reste à faire, pour la liste et le tableau de bord. */
    public function prochaineEtape(): ?string
    {
        return match ($this) {
            self::RECUE => 'Contrôle',
            self::CONTROLEE => 'Inspection',
            self::INSPECTEE => 'Stockage',
            self::STOCKEE, self::ANNULEE => null,
        };
    }

    /** @return list<self> les quatre étapes, dans l'ordre */
    public static function etapes(): array
    {
        return [self::RECUE, self::CONTROLEE, self::INSPECTEE, self::STOCKEE];
    }
}
