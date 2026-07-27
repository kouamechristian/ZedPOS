<?php

namespace App\Service;

use App\Entity\Vente;
use App\Enum\StatutVente;

/**
 * Ventes d'une journée en CSV, une ligne par ticket.
 *
 * Destiné au tableur, pas à un import comptable : pour transmettre des écritures
 * au cabinet, c'est `/comptabilite` qu'il faut (plan SYSCOHADA, écritures
 * équilibrées, contrôles). Ici on veut simplement pouvoir trier, filtrer et
 * additionner à la main ce qui s'est vendu dans la journée.
 *
 * Les **ventes annulées y figurent**, avec leur statut et leur motif — c'est
 * précisément ce qu'on vient vérifier dans un export de contrôle. Elles sont en
 * revanche exclues de la ligne de total, comme partout ailleurs.
 */
class RapportVentesCsv
{
    /** Sans marque d'ordre, Excel sous Windows lit le fichier en ANSI. */
    private const BOM_UTF8 = "\u{FEFF}";

    private const FIN_LIGNE = "\r\n";

    private const COLONNES = [
        'Numéro', 'Date', 'Heure', 'Caissière', 'Mode', 'Statut',
        'Total HT', 'TVA', 'Total TTC', 'Remise', 'Motif remise',
        'Règlements', 'Rendu', 'Motif annulation',
    ];

    /**
     * @param list<Vente> $ventes
     */
    public function construire(\DateTimeImmutable $jour, array $ventes): string
    {
        $lignes = [$this->csv(self::COLONNES)];

        $totalTtc = 0;
        $tickets = 0;

        foreach ($ventes as $vente) {
            $annulee = StatutVente::ANNULEE === $vente->getStatut();
            if (!$annulee) {
                $totalTtc += $vente->getTotalTtc();
                ++$tickets;
            }

            $lignes[] = $this->csv([
                $vente->getNumero(),
                $vente->getCreatedAt()->format('d/m/Y'),
                $vente->getCreatedAt()->format('H:i'),
                $vente->getSessionCaisse()->getUtilisateur()->getNom(),
                $vente->getMode()->value,
                $annulee ? 'Annulée' : 'Validée',
                $this->montant($vente->getTotalHt()),
                $this->montant($vente->getTotalTva()),
                $this->montant($vente->getTotalTtc()),
                $this->montant($vente->getRemise()),
                (string) $vente->getMotifRemise(),
                $this->reglements($vente),
                $this->montant($vente->getRendu()),
                (string) $vente->getMotifAnnulation(),
            ]);
        }

        // Ligne de total : le tableur la recalculerait, mais elle évite d'avoir à
        // le faire pour un simple contrôle de cohérence à l'écran.
        $lignes[] = '';
        $lignes[] = $this->csv([
            \sprintf('%d ticket%s encaissé%s', $tickets, $tickets > 1 ? 's' : '', $tickets > 1 ? 's' : ''),
            $jour->format('d/m/Y'), '', '', '', '', '', '',
            $this->montant($totalTtc),
        ]);

        return self::BOM_UTF8.implode(self::FIN_LIGNE, $lignes).self::FIN_LIGNE;
    }

    public function nomFichier(\DateTimeImmutable $jour): string
    {
        return 'zedpos-ventes-'.$jour->format('Y-m-d').'.csv';
    }

    private function reglements(Vente $vente): string
    {
        $parts = [];
        foreach ($vente->getReglements() as $reglement) {
            $parts[] = $reglement->getMode()->libelle().' '.$this->montant($reglement->getMontant());
        }

        return implode(' + ', $parts);
    }

    /**
     * Centimes → FCFA, séparateur décimal virgule. Division entière : la
     * conversion se fait à la présentation, jamais sur les données.
     */
    private function montant(int $centimes): string
    {
        $signe = $centimes < 0 ? '-' : '';
        $absolu = abs($centimes);

        return \sprintf('%s%d,%02d', $signe, intdiv($absolu, 100), $absolu % 100);
    }

    /** @param list<string> $champs */
    private function csv(array $champs): string
    {
        return implode(';', array_map(
            static fn (string $valeur): string => preg_match('/[";\r\n]/', $valeur)
                ? '"'.str_replace('"', '""', $valeur).'"'
                : $valeur,
            $champs,
        ));
    }
}
