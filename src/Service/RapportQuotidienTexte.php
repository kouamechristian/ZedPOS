<?php

namespace App\Service;

/**
 * Met en forme une {@see SyntheseJournee} en message court, prêt à être collé
 * dans WhatsApp ou un e-mail.
 *
 * Contraintes assumées : texte brut, pas de tableau ni de Markdown (WhatsApp ne
 * les rend pas), lignes courtes, montants en FCFA entiers.
 */
class RapportQuotidienTexte
{
    private const JOURS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
    private const MOIS = [
        1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
        'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
    ];

    public function __construct(private readonly ParametresTicket $parametres)
    {
    }

    public function construire(SyntheseJournee $synthese): string
    {
        $lignes = [];

        $lignes[] = \sprintf('*%s* — %s', $this->parametres->raisonSociale, $this->dateLisible($synthese->jour));
        $lignes[] = '';
        $lignes[] = \sprintf('CA du jour : *%s FCFA*', $this->fcfa($synthese->caJour));
        $lignes[] = \sprintf('  %s hier (%s FCFA)', $this->variation($synthese->variationVeilleBp), $this->fcfa($synthese->caVeille));
        $lignes[] = \sprintf('  %s même jour sem. dern. (%s FCFA)', $this->variation($synthese->variationSemaineBp), $this->fcfa($synthese->caSemainePrecedente));
        $lignes[] = '';
        $lignes[] = \sprintf('Tickets : %d — panier moyen %s FCFA', $synthese->nombreTickets, $this->fcfa($synthese->panierMoyen));

        if ([] !== $synthese->parReglement) {
            $lignes[] = '';
            $lignes[] = 'Règlements :';
            foreach ($synthese->parReglement as $reglement) {
                $lignes[] = \sprintf('  - %s : %s FCFA', $reglement['libelle'], $this->fcfa($reglement['montant']));
            }
        }

        if ([] !== $synthese->parCaissiere) {
            $lignes[] = '';
            $lignes[] = 'Par caissière :';
            foreach ($synthese->parCaissiere as $caissiere) {
                $lignes[] = \sprintf(
                    '  - %s : %s FCFA (%d ticket%s, %s %%)',
                    $caissiere['nom'],
                    $this->fcfa($caissiere['ca']),
                    $caissiere['tickets'],
                    $caissiere['tickets'] > 1 ? 's' : '',
                    number_format($caissiere['partBp'] / 100, 0, ',', ' '),
                );
            }
        }

        if ([] !== $synthese->topProduits) {
            $lignes[] = '';
            $lignes[] = 'Top 5 :';
            foreach (\array_slice($synthese->topProduits, 0, 5) as $rang => $produit) {
                $lignes[] = \sprintf(
                    '  %d. %s (%s)',
                    $rang + 1,
                    $produit['nom'],
                    rtrim(rtrim(number_format($produit['quantite'] / 1000, 3, ',', ' '), '0'), ','),
                );
            }
        }

        $lignes[] = '';
        $lignes = array_merge($lignes, $this->vigilance($synthese));

        $lignes[] = '';
        $lignes[] = '— ZedPOS';

        return implode("\n", $lignes);
    }

    /**
     * @return list<string>
     */
    private function vigilance(SyntheseJournee $synthese): array
    {
        if (!$synthese->aDesPointsDeVigilance()) {
            return ['Vigilance : RAS, journée propre.'];
        }

        $lignes = ['Vigilance :'];

        if ($synthese->annulationsNombre > 0) {
            $lignes[] = \sprintf(
                '  - %d annulation%s (%s FCFA)',
                $synthese->annulationsNombre,
                $synthese->annulationsNombre > 1 ? 's' : '',
                $this->fcfa($synthese->annulationsMontant),
            );
        }
        if ($synthese->remisesNombre > 0) {
            $lignes[] = \sprintf(
                '  - %d remise%s (%s FCFA)',
                $synthese->remisesNombre,
                $synthese->remisesNombre > 1 ? 's' : '',
                $this->fcfa($synthese->remisesMontant),
            );
        }
        if (null !== $synthese->ecartCaisse && 0 !== $synthese->ecartCaisse) {
            $lignes[] = \sprintf(
                '  - Écart de caisse : %s%s FCFA',
                $synthese->ecartCaisse > 0 ? '+' : '-',
                $this->fcfa(abs($synthese->ecartCaisse)),
            );
        }
        if ($synthese->pertesMontant > 0) {
            $lignes[] = \sprintf('  - Pertes : %s FCFA', $this->fcfa($synthese->pertesMontant));
        }
        if ([] !== $synthese->rupturesStock) {
            $lignes[] = \sprintf(
                '  - Stock bas (%d) : %s',
                \count($synthese->rupturesStock),
                implode(', ', \array_slice($synthese->rupturesStock, 0, 5)),
            );
        }

        if (0 === $synthese->sessionsCloturees) {
            $lignes[] = '  - Aucune caisse clôturée à cette heure.';
        }

        return $lignes;
    }

    private function variation(?int $bp): string
    {
        if (null === $bp) {
            return '(pas de référence)';
        }

        return \sprintf('%s%s %% vs', $bp >= 0 ? '+' : '', number_format($bp / 100, 1, ',', ' '));
    }

    /** Centimes → FCFA lisibles, sans décimale. */
    private function fcfa(int $centimes): string
    {
        return number_format(intdiv($centimes, 100), 0, ',', ' ');
    }

    private function dateLisible(\DateTimeImmutable $jour): string
    {
        return \sprintf(
            '%s %d %s %d',
            self::JOURS[(int) $jour->format('N') - 1],
            (int) $jour->format('j'),
            self::MOIS[(int) $jour->format('n')],
            (int) $jour->format('Y'),
        );
    }
}
