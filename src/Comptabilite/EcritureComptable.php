<?php

namespace App\Comptabilite;

/**
 * Une écriture comptable : un ensemble de lignes équilibrées, rattachées à un
 * journal, à une date et à une pièce justificative.
 *
 * **L'équilibre est vérifié à la construction**, pas au moment de l'export : un
 * fichier déséquilibré serait rejeté par le logiciel du cabinet comptable, et il
 * vaut mieux échouer là où l'erreur est commise que là où elle est constatée.
 */
final class EcritureComptable
{
    /** @var list<LigneEcriture> */
    public readonly array $lignes;

    /**
     * @param string $piece   référence de la pièce justificative (ex. « Z12 »,
     *                        le rapport de clôture qui reste au classeur)
     * @param list<LigneEcriture> $lignes
     */
    public function __construct(
        public readonly JournalComptable $journal,
        public readonly \DateTimeImmutable $date,
        public readonly string $piece,
        public readonly string $libelle,
        array $lignes,
    ) {
        if ([] === $lignes) {
            throw new \DomainException('Une écriture comptable doit comporter au moins une ligne.');
        }

        $debit = 0;
        $credit = 0;
        foreach ($lignes as $ligne) {
            $debit += $ligne->debit;
            $credit += $ligne->credit;
        }

        if ($debit !== $credit) {
            throw new \DomainException(\sprintf(
                'Écriture déséquilibrée sur la pièce %s : %d centimes au débit contre %d au crédit.',
                $piece,
                $debit,
                $credit,
            ));
        }

        $this->lignes = array_values($lignes);
    }

    /** Total du débit (égal au crédit), en centimes de FCFA. */
    public function total(): int
    {
        $total = 0;
        foreach ($this->lignes as $ligne) {
            $total += $ligne->debit;
        }

        return $total;
    }
}
