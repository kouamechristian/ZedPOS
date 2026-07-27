<?php

namespace App\Service;

use App\Entity\Vente;

/**
 * Construit un {@see TicketData} à partir d'une vente, en calculant la ventilation
 * de TVA par taux (remise répartie au prorata).
 */
class TicketBuilder
{
    public function __construct(
        private readonly ParametresTicket $parametres,
        private readonly CodeBarres128 $codeBarres,
    ) {
    }

    public function construire(Vente $vente): TicketData
    {
        $lignes = [];
        $brutTtc = 0;
        $parTaux = [];

        foreach ($vente->getLignes() as $ligne) {
            $montant = $ligne->getMontantTtc();
            $brutTtc += $montant;
            $taux = $ligne->getArticle()->getTauxTva();
            $parTaux[$taux] = ($parTaux[$taux] ?? 0) + $montant;

            $lignes[] = [
                'nom' => $ligne->getArticle()->getNom(),
                'quantiteMillimes' => $ligne->getQuantite(),
                'prixUnitaire' => $ligne->getPrixUnitaire(),
                'montant' => $montant,
                'commentaire' => $ligne->getCommentaire(),
            ];
        }

        $remise = $vente->getRemise();
        ksort($parTaux);
        $ventilation = [];
        foreach ($parTaux as $taux => $ttcBrut) {
            // Remise répartie proportionnellement au poids TTC de chaque taux.
            $netTtc = $brutTtc > 0 ? $ttcBrut - intdiv($remise * $ttcBrut, $brutTtc) : $ttcBrut;
            $base = intdiv($netTtc * 10000, 10000 + $taux);
            $ventilation[] = [
                'tauxBp' => $taux,
                'base' => $base,
                'montant' => $netTtc - $base,
            ];
        }

        $reglements = [];
        foreach ($vente->getReglements() as $reglement) {
            $reglements[] = [
                'libelle' => $reglement->getMode()->libelle(),
                'montant' => $reglement->getMontant(),
            ];
        }

        $numero = $vente->getNumero();

        return new TicketData(
            raisonSociale: $this->parametres->raisonSociale,
            adresse: $this->parametres->adresse,
            ncc: $this->parametres->ncc,
            telephone: $this->parametres->telephone,
            pied: $this->parametres->pied,
            numero: $numero,
            dateHeure: $vente->getCreatedAt(),
            caissier: $vente->getSessionCaisse()->getUtilisateur()->getNom(),
            lignes: $lignes,
            ventilationTva: $ventilation,
            reglements: $reglements,
            totalHt: $vente->getTotalHt(),
            totalTva: $vente->getTotalTva(),
            totalTtc: $vente->getTotalTtc(),
            remise: $remise,
            rendu: $vente->getRendu(),
            rccm: $this->parametres->rccm,
            email: $this->parametres->email,
            // Le numéro de ticket suit un gabarit maîtrisé (`Vaammjj-00001`), donc
            // toujours encodable ; la garde couvre les numéros d'un import ou d'un
            // futur format, pour lesquels mieux vaut un ticket sans code-barres
            // qu'une impression qui échoue.
            codeBarres: $this->codeBarres->supporte($numero) ? $this->codeBarres->encoder($numero) : null,
        );
    }
}
