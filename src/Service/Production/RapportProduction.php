<?php

namespace App\Service\Production;

use App\Entity\SessionCaisse;

/**
 * Le rapport de production d'une caisse : sa fiche lue ligne par ligne, les
 * totaux et les remises de ticket. Quantités en millièmes, montants en centimes.
 *
 * Un seul objet pour le pilotage, l'atelier et leurs PDF : un document imprimé
 * qui ne correspondrait pas à l'écran ferait douter des deux.
 */
final readonly class RapportProduction
{
    /** @var array{produite: int, vitrine: int, reprise: int, vendue: int, reste: int, montant: int} */
    public array $totaux;

    /**
     * @param list<LigneFiche> $lignes
     * @param int              $remises remises accordées sur les tickets de la caisse, tous articles confondus
     */
    public function __construct(
        public SessionCaisse $session,
        public array $lignes,
        public int $remises,
    ) {
        $totaux = ['produite' => 0, 'vitrine' => 0, 'reprise' => 0, 'vendue' => 0, 'reste' => 0, 'montant' => 0];
        foreach ($lignes as $ligne) {
            $totaux['produite'] += $ligne->produite;
            $totaux['vitrine'] += $ligne->vitrine;
            $totaux['reprise'] += $ligne->reprise;
            $totaux['vendue'] += $ligne->vendue;
            $totaux['reste'] += $ligne->reste();
            $totaux['montant'] += $ligne->montant;
        }
        $this->totaux = $totaux;
    }

    /** `production_2026-09-30_Fatou-Traoré.pdf` : un dossier de rapports se trie par jour et par caisse. */
    public function nomFichier(): string
    {
        $caissier = preg_replace('/\s+/u', '-', trim($this->session->getUtilisateur()->getNom())) ?? '';

        return 'production_'.$this->session->getOuvertureAt()->format('Y-m-d').('' !== $caissier ? '_'.$caissier : '').'.pdf';
    }
}
