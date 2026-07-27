<?php

namespace App\Comptabilite;

/**
 * Journaux comptables alimentés par ZedPOS.
 *
 * Un journal regroupe les écritures de même nature ; le code à deux lettres est
 * la convention SYSCOHADA courante, reprise telle quelle par les logiciels de
 * comptabilité qui importeront les fichiers.
 */
enum JournalComptable: string
{
    /** Ventes : centralisation des tickets, une écriture par rapport Z. */
    case VENTES = 'VE';

    /** Caisse : dépenses réglées en espèces, sorties de fonds, écarts de caisse. */
    case CAISSE = 'CA';

    /** Opérations diverses : pertes de stock valorisées. */
    case OPERATIONS_DIVERSES = 'OD';

    public function libelle(): string
    {
        return match ($this) {
            self::VENTES => 'Journal des ventes',
            self::CAISSE => 'Journal de caisse',
            self::OPERATIONS_DIVERSES => 'Opérations diverses',
        };
    }
}
