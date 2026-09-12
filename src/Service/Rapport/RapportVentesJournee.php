<?php

namespace App\Service\Rapport;

use App\Entity\Utilisateur;
use App\Repository\VenteRepository;

/**
 * Rapport de journée d'une caissière (ou de toute l'équipe), ventilé par famille.
 *
 * Ce que la gérante vient y chercher : ce qui est sorti du comptoir dans la
 * journée, rangé par famille, et le total qu'elle rapproche des espèces en
 * tiroir. Le même objet alimente l'écran et le PDF — ils ne peuvent donc pas
 * afficher deux chiffres différents.
 *
 * **Les ventes annulées sont exclues des montants**, comme partout ailleurs dans
 * le projet, mais **comptées à part** : un rapport qui les passerait sous silence
 * serait muet sur ce qu'on vient précisément y vérifier.
 *
 * Tout le calcul est entier. Aucun montant ne passe par un flottant, pas même le
 * temps d'un tri.
 */
class RapportVentesJournee
{
    /** Libellé des articles rattachés à aucune famille. */
    public const SANS_FAMILLE = 'Sans famille';

    public function __construct(private readonly VenteRepository $ventes)
    {
    }

    public function pour(\DateTimeImmutable $jour, ?Utilisateur $caissier = null): VentesDuJour
    {
        $ventes = $this->ventes->duJourAvecLignes($jour, $caissier);

        /** @var array<string, array{position: int, nom: string, articles: array<string, array{nom: string, quantite: int, prix: ?int, plusieursPrix: bool, montant: int}>}> $familles */
        $familles = [];
        /** @var array<string, array{montant: int, tickets: int}> $reglements */
        $reglements = [];

        $brutTtc = $remises = $totalTtc = $totalHt = $totalTva = $quantite = 0;
        $tickets = $annulations = $montantAnnule = 0;

        foreach ($ventes as $vente) {
            // Une vente annulée n'a rien vendu : elle ne ventile rien, elle se compte.
            if (!$vente->estValidee()) {
                ++$annulations;
                $montantAnnule += $vente->getTotalTtc();
                continue;
            }

            ++$tickets;
            $remises += $vente->getRemise();
            $totalTtc += $vente->getTotalTtc();
            $totalHt += $vente->getTotalHt();
            $totalTva += $vente->getTotalTva();

            foreach ($vente->getLignes() as $ligne) {
                $article = $ligne->getArticle();
                $famille = $article->getFamilleProduit();
                $cleFamille = null !== $famille ? 'f'.$famille->getId() : 'z';

                $familles[$cleFamille] ??= [
                    // Les familles se rangent dans l'ordre de la caisse : c'est
                    // celui que la gérante a sous les yeux tous les jours, et il
                    // ne bouge pas d'une journée à l'autre — deux rapports se
                    // comparent donc ligne à ligne. Les articles sans famille
                    // ferment la marche.
                    'position' => null !== $famille ? $famille->getPosition() : \PHP_INT_MAX,
                    'nom' => $famille?->getNom() ?? self::SANS_FAMILLE,
                    'articles' => [],
                ];

                $cleArticle = (string) $article->getId();
                $prix = $ligne->getPrixUnitaire();
                $entree = $familles[$cleFamille]['articles'][$cleArticle] ?? [
                    'nom' => $article->getNom(),
                    'quantite' => 0,
                    'prix' => $prix,
                    'plusieursPrix' => false,
                    'montant' => 0,
                ];

                $entree['quantite'] += $ligne->getQuantite();
                $entree['montant'] += $ligne->getMontantTtc();
                // Un prix modifié en cours de journée : on ne peut plus afficher
                // « le » prix unitaire, et en choisir un serait faux.
                $entree['plusieursPrix'] = $entree['plusieursPrix'] || $entree['prix'] !== $prix;

                $familles[$cleFamille]['articles'][$cleArticle] = $entree;

                $brutTtc += $ligne->getMontantTtc();
                $quantite += $ligne->getQuantite();
            }

            foreach ($vente->getReglements() as $reglement) {
                $mode = $reglement->getMode()->libelle();
                $reglements[$mode] ??= ['montant' => 0, 'tickets' => 0];
                $reglements[$mode]['montant'] += $reglement->getMontant();
                ++$reglements[$mode]['tickets'];
            }
        }

        return new VentesDuJour(
            jour: $jour,
            caissier: $caissier,
            familles: $this->rangerFamilles($familles),
            reglements: $this->rangerReglements($reglements),
            brutTtc: $brutTtc,
            remises: $remises,
            totalTtc: $totalTtc,
            totalHt: $totalHt,
            totalTva: $totalTva,
            quantite: $quantite,
            tickets: $tickets,
            annulations: $annulations,
            montantAnnule: $montantAnnule,
        );
    }

    /**
     * @param array<string, array{position: int, nom: string, articles: array<string, array{nom: string, quantite: int, prix: ?int, plusieursPrix: bool, montant: int}>}> $familles
     *
     * @return list<FamilleVendue>
     */
    private function rangerFamilles(array $familles): array
    {
        uasort($familles, static fn (array $a, array $b) => [$a['position'], $a['nom']] <=> [$b['position'], $b['nom']]);

        $rangees = [];
        foreach ($familles as $famille) {
            $articles = $famille['articles'];
            // Dans une famille, le plus vendu d'abord : c'est ce qu'on lit en
            // premier, et une famille de trente articles se parcourt autrement.
            uasort($articles, static fn (array $a, array $b) => [$b['montant'], $a['nom']] <=> [$a['montant'], $b['nom']]);

            $quantite = $montant = 0;
            $detail = [];
            foreach ($articles as $article) {
                $quantite += $article['quantite'];
                $montant += $article['montant'];
                $detail[] = new ArticleVendu(
                    $article['nom'],
                    $article['quantite'],
                    $article['plusieursPrix'] ? null : $article['prix'],
                    $article['montant'],
                );
            }

            $rangees[] = new FamilleVendue($famille['nom'], $detail, $quantite, $montant);
        }

        return $rangees;
    }

    /**
     * @param array<string, array{montant: int, tickets: int}> $reglements
     *
     * @return list<ReglementVentile>
     */
    private function rangerReglements(array $reglements): array
    {
        uasort($reglements, static fn (array $a, array $b) => $b['montant'] <=> $a['montant']);

        $rangees = [];
        foreach ($reglements as $mode => $ventile) {
            $rangees[] = new ReglementVentile($mode, $ventile['montant'], $ventile['tickets']);
        }

        return $rangees;
    }
}
