<?php

namespace App\Comptabilite;

/**
 * Les écritures d'une période, prêtes à être écrites dans un fichier.
 *
 * Objet de transport pur : il ne connaît ni la base de données (c'est le rôle de
 * {@see \App\Service\Comptabilite\GenerateurEcrituresSyscohada}) ni les formats
 * de fichier (c'est le rôle de {@see \App\Service\Comptabilite\ExportComptable}).
 * L'écran d'aperçu et les trois formats consomment donc le **même** objet : les
 * chiffres affichés et les chiffres exportés ne peuvent pas diverger.
 */
final class JeuEcritures
{
    /** @var list<EcritureComptable> */
    public readonly array $ecritures;

    /** @var list<Controle> */
    public readonly array $controles;

    /**
     * @param list<EcritureComptable> $ecritures
     * @param list<Controle>          $controles
     */
    public function __construct(
        public readonly \DateTimeImmutable $du,
        public readonly \DateTimeImmutable $au,
        array $ecritures,
        array $controles = [],
    ) {
        // Tri chronologique, puis par journal : c'est l'ordre attendu dans un
        // fichier d'écritures, et il rend l'export reproductible — deux exports
        // d'une même période produisent des fichiers identiques, donc comparables.
        usort($ecritures, static fn (EcritureComptable $a, EcritureComptable $b): int => [$a->date, $a->journal->value, $a->piece]
            <=> [$b->date, $b->journal->value, $b->piece]);

        $this->ecritures = array_values($ecritures);
        $this->controles = array_values($controles);
    }

    public function estVide(): bool
    {
        return [] === $this->ecritures;
    }

    public function nombreEcritures(): int
    {
        return \count($this->ecritures);
    }

    public function nombreLignes(): int
    {
        $lignes = 0;
        foreach ($this->ecritures as $ecriture) {
            $lignes += \count($ecriture->lignes);
        }

        return $lignes;
    }

    /** Total du débit de la période, en centimes de FCFA. */
    public function totalDebit(): int
    {
        $total = 0;
        foreach ($this->ecritures as $ecriture) {
            foreach ($ecriture->lignes as $ligne) {
                $total += $ligne->debit;
            }
        }

        return $total;
    }

    /** Total du crédit de la période, en centimes de FCFA. */
    public function totalCredit(): int
    {
        $total = 0;
        foreach ($this->ecritures as $ecriture) {
            foreach ($ecriture->lignes as $ligne) {
                $total += $ligne->credit;
            }
        }

        return $total;
    }

    /**
     * Chaque écriture étant équilibrée à la construction, ce total l'est aussi.
     * On le vérifie quand même : c'est le contrôle que fera le cabinet comptable
     * à la réception du fichier, autant le lui présenter fait.
     */
    public function estEquilibre(): bool
    {
        return $this->totalDebit() === $this->totalCredit();
    }

    public function controlesSontBons(): bool
    {
        foreach ($this->controles as $controle) {
            if (!$controle->estBon()) {
                return false;
            }
        }

        return $this->estEquilibre();
    }

    /**
     * Balance générale de la période : un cumul débit / crédit par compte,
     * dans l'ordre des numéros de compte.
     *
     * @return list<array{compte: string, libelle: string, debit: int, credit: int, solde: int}>
     */
    public function balance(): array
    {
        $comptes = [];

        foreach ($this->ecritures as $ecriture) {
            foreach ($ecriture->lignes as $ligne) {
                $comptes[$ligne->compte] ??= [
                    'compte' => $ligne->compte,
                    'libelle' => $ligne->libelleCompte,
                    'debit' => 0,
                    'credit' => 0,
                    'solde' => 0,
                ];
                $comptes[$ligne->compte]['debit'] += $ligne->debit;
                $comptes[$ligne->compte]['credit'] += $ligne->credit;
            }
        }

        foreach ($comptes as $code => $ligne) {
            $comptes[$code]['solde'] = $ligne['debit'] - $ligne['credit'];
        }

        uksort($comptes, static fn (string $a, string $b): int => self::cleTri($a) <=> self::cleTri($b));

        return array_values($comptes);
    }

    /**
     * Regroupement par journal, pour la synthèse affichée à l'écran.
     *
     * @return list<array{journal: JournalComptable, ecritures: int, total: int}>
     */
    public function parJournal(): array
    {
        $journaux = [];

        foreach ($this->ecritures as $ecriture) {
            $code = $ecriture->journal->value;
            $journaux[$code] ??= ['journal' => $ecriture->journal, 'ecritures' => 0, 'total' => 0];
            ++$journaux[$code]['ecritures'];
            $journaux[$code]['total'] += $ecriture->total();
        }

        ksort($journaux);

        return array_values($journaux);
    }

    /**
     * Clé de tri d'un numéro de compte : complété à droite par des zéros pour
     * que 605 se classe avant 6056, comme dans une balance imprimée. Le code
     * exporté, lui, reste celui du plan (jamais cette clé).
     */
    private static function cleTri(string $compte): string
    {
        return str_pad($compte, 8, '0');
    }
}
