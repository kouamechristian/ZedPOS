<?php

namespace App\Enum;

/**
 * Ce que la gérante fait d'un manquant au point — **choisi explicitement**, jamais
 * par défaut : une dette ne se crée pas en silence, une perte ne s'avale pas sans
 * justification.
 */
enum TraitementEcart: string
{
    /** Le manquant devient une dette du vendeur, ouverte à la validation. */
    case DETTE = 'DETTE';
    /** La boutique absorbe le manquant ; justification obligatoire. */
    case PERTE = 'PERTE';

    public function libelle(): string
    {
        return match ($this) {
            self::DETTE => 'Imputé en dette du vendeur',
            self::PERTE => 'Passé en perte',
        };
    }
}
