<?php

namespace App\Comptabilite;

/**
 * Formats de fichier proposés à l'export comptable.
 *
 * Trois besoins distincts, pas trois variantes du même : le CSV se relit et se
 * corrige à la main, le FEC s'importe dans un logiciel de comptabilité, la
 * balance se contrôle d'un coup d'œil sans rien importer du tout.
 */
enum FormatExport: string
{
    /** Écritures détaillées, séparateur « ; », lisible dans Excel / LibreOffice. */
    case ECRITURES_CSV = 'ecritures';

    /** Fichier des écritures comptables : tabulé, importable par le cabinet. */
    case FEC = 'fec';

    /** Balance générale : un cumul débit / crédit et un solde par compte. */
    case BALANCE_CSV = 'balance';

    public function libelle(): string
    {
        return match ($this) {
            self::ECRITURES_CSV => 'Écritures (CSV)',
            self::FEC => 'Fichier des écritures comptables (FEC)',
            self::BALANCE_CSV => 'Balance générale (CSV)',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ECRITURES_CSV => 'Le détail des écritures, une ligne par mouvement. '
                .'S\'ouvre directement dans un tableur — le format à envoyer par courriel.',
            self::FEC => 'Fichier normalisé à 18 colonnes, séparé par des tabulations. '
                .'C\'est ce fichier que le cabinet comptable importe dans son logiciel.',
            self::BALANCE_CSV => 'Un cumul par compte sur la période. Sert à vérifier '
                .'les grandes masses avant de transmettre les écritures.',
        };
    }

    public function extension(): string
    {
        return match ($this) {
            self::ECRITURES_CSV, self::BALANCE_CSV => 'csv',
            self::FEC => 'txt',
        };
    }

    public function typeMime(): string
    {
        return match ($this) {
            self::ECRITURES_CSV, self::BALANCE_CSV => 'text/csv; charset=UTF-8',
            self::FEC => 'text/plain; charset=UTF-8',
        };
    }
}
