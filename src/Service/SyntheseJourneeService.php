<?php

namespace App\Service;

use App\Entity\MatierePremiere;
use App\Enum\ModeReglement;
use Doctrine\DBAL\Connection;

/**
 * Construit la {@see SyntheseJournee} d'une date donnée.
 *
 * Toutes les agrégations excluent les ventes annulées des montants (elles sont
 * comptées à part, dans les points de vigilance).
 */
class SyntheseJourneeService
{
    /** Nombre de jours affichés sur la courbe de tendance. */
    private const JOURS_COURBE = 30;

    public function __construct(
        private readonly Connection $connexion,
        private readonly AlerteStockService $alertes,
    ) {
    }

    public function construire(?\DateTimeImmutable $jour = null): SyntheseJournee
    {
        $jour = ($jour ?? new \DateTimeImmutable('today'))->setTime(0, 0);

        $caJour = $this->caDuJour($jour);
        $caVeille = $this->caDuJour($jour->modify('-1 day'));
        $caSemaine = $this->caDuJour($jour->modify('-7 days'));

        $ventes = $this->ventesDuJour($jour);
        $nombreTickets = (int) ($ventes['nombre'] ?? 0);

        $annulations = $this->connexion->fetchAssociative(
            "SELECT COUNT(*) AS nombre, COALESCE(SUM(total_ttc), 0) AS montant
             FROM vente WHERE DATE(created_at) = ? AND statut = 'ANNULEE'",
            [$jour->format('Y-m-d')],
        ) ?: [];

        $pertes = $this->connexion->fetchAssociative(
            'SELECT COUNT(*) AS nombre, COALESCE(SUM(valorisation), 0) AS montant
             FROM perte WHERE DATE(created_at) = ?',
            [$jour->format('Y-m-d')],
        ) ?: [];

        $caisse = $this->connexion->fetchAssociative(
            "SELECT COUNT(*) AS nombre, COALESCE(SUM(ecart), 0) AS ecart
             FROM session_caisse WHERE statut = 'CLOTUREE' AND DATE(cloture_at) = ?",
            [$jour->format('Y-m-d')],
        ) ?: [];

        $sessionsCloturees = (int) ($caisse['nombre'] ?? 0);

        return new SyntheseJournee(
            jour: $jour,
            caJour: $caJour,
            caVeille: $caVeille,
            caSemainePrecedente: $caSemaine,
            variationVeilleBp: $this->variationBp($caJour, $caVeille),
            variationSemaineBp: $this->variationBp($caJour, $caSemaine),
            nombreTickets: $nombreTickets,
            panierMoyen: $nombreTickets > 0 ? intdiv($caJour, $nombreTickets) : 0,
            parReglement: $this->ventilerReglements($jour, (int) ($ventes['rendu'] ?? 0)),
            annulationsNombre: (int) ($annulations['nombre'] ?? 0),
            annulationsMontant: (int) ($annulations['montant'] ?? 0),
            remisesNombre: (int) ($ventes['nb_remises'] ?? 0),
            remisesMontant: (int) ($ventes['remise'] ?? 0),
            // Null s'il n'y a eu aucune clôture : « 0 » laisserait croire à une caisse juste.
            ecartCaisse: $sessionsCloturees > 0 ? (int) $caisse['ecart'] : null,
            sessionsCloturees: $sessionsCloturees,
            rupturesStock: array_map(
                static fn (MatierePremiere $m): string => $m->getNom(),
                $this->alertes->matieresSousSeuil(),
            ),
            pertesMontant: (int) ($pertes['montant'] ?? 0),
            pertesNombre: (int) ($pertes['nombre'] ?? 0),
            topProduits: $this->topProduits($jour),
            serie30Jours: $this->serie($jour),
            parCaissiere: $this->parCaissiere($jour, $caJour),
        );
    }

    /**
     * Ventes de la journée ventilées par caissière.
     *
     * Quatre requêtes plutôt qu'une seule à rallonge : ventes validées,
     * annulations et sessions de caisse ne se comptent pas sur les mêmes lignes,
     * et les joindre d'un bloc multiplierait les lignes entre elles — une
     * caissière avec deux sessions verrait son chiffre doublé.
     *
     * @param int $caJour chiffre d'affaires total, pour calculer les parts
     *
     * @return list<array{
     *     id: int, nom: string, tickets: int, ca: int, panierMoyen: int, partBp: int,
     *     remisesNombre: int, remisesMontant: int, annulationsNombre: int, annulationsMontant: int,
     *     ecart: ?int, sessionOuverte: bool
     * }>
     */
    private function parCaissiere(\DateTimeImmutable $jour, int $caJour): array
    {
        $date = $jour->format('Y-m-d');
        $caissieres = [];

        foreach ($this->connexion->fetchAllAssociative(
            "SELECT u.id, u.nom,
                    COUNT(*) AS tickets,
                    COALESCE(SUM(v.total_ttc), 0) AS ca,
                    COALESCE(SUM(v.remise), 0) AS remises,
                    COALESCE(SUM(CASE WHEN v.remise > 0 THEN 1 ELSE 0 END), 0) AS nb_remises
             FROM vente v
             JOIN session_caisse s ON s.id = v.session_caisse_id
             JOIN utilisateur u ON u.id = s.utilisateur_id
             WHERE DATE(v.created_at) = ? AND v.statut = 'VALIDEE'
             GROUP BY u.id, u.nom",
            [$date],
        ) as $ligne) {
            $tickets = (int) $ligne['tickets'];
            $ca = (int) $ligne['ca'];

            $caissieres[(int) $ligne['id']] = [
                'id' => (int) $ligne['id'],
                'nom' => (string) $ligne['nom'],
                'tickets' => $tickets,
                'ca' => $ca,
                'panierMoyen' => $tickets > 0 ? intdiv($ca, $tickets) : 0,
                // Part du chiffre d'affaires en points de base, comme les
                // variations : jamais de float pour une grandeur dérivée d'argent.
                'partBp' => $caJour > 0 ? (int) round($ca * 10000 / $caJour) : 0,
                'remisesNombre' => (int) $ligne['nb_remises'],
                'remisesMontant' => (int) $ligne['remises'],
                'annulationsNombre' => 0,
                'annulationsMontant' => 0,
                'ecart' => null,
                'sessionOuverte' => false,
            ];
        }

        foreach ($this->connexion->fetchAllAssociative(
            "SELECT u.id, COUNT(*) AS nombre, COALESCE(SUM(v.total_ttc), 0) AS montant
             FROM vente v
             JOIN session_caisse s ON s.id = v.session_caisse_id
             JOIN utilisateur u ON u.id = s.utilisateur_id
             WHERE DATE(v.created_at) = ? AND v.statut = 'ANNULEE'
             GROUP BY u.id",
            [$date],
        ) as $ligne) {
            $id = (int) $ligne['id'];
            if (!isset($caissieres[$id])) {
                continue; // Que des annulations, aucune vente : rien à ventiler.
            }
            $caissieres[$id]['annulationsNombre'] = (int) $ligne['nombre'];
            $caissieres[$id]['annulationsMontant'] = (int) $ligne['montant'];
        }

        // Écart de caisse : seules les sessions clôturées en ont un. Une session
        // encore ouverte laisse `ecart` à null — afficher « 0 » laisserait croire
        // à une caisse juste, même convention que pour la journée entière.
        foreach ($this->connexion->fetchAllAssociative(
            "SELECT s.utilisateur_id AS id,
                    COALESCE(SUM(CASE WHEN s.statut = 'CLOTUREE' THEN s.ecart ELSE 0 END), 0) AS ecart,
                    COALESCE(SUM(CASE WHEN s.statut = 'CLOTUREE' THEN 1 ELSE 0 END), 0) AS cloturees,
                    COALESCE(SUM(CASE WHEN s.statut = 'OUVERTE' THEN 1 ELSE 0 END), 0) AS ouvertes
             FROM session_caisse s
             WHERE DATE(s.ouverture_at) = ?
             GROUP BY s.utilisateur_id",
            [$date],
        ) as $ligne) {
            $id = (int) $ligne['id'];
            if (!isset($caissieres[$id])) {
                continue;
            }
            $caissieres[$id]['ecart'] = ((int) $ligne['cloturees']) > 0 ? (int) $ligne['ecart'] : null;
            $caissieres[$id]['sessionOuverte'] = ((int) $ligne['ouvertes']) > 0;
        }

        $caissieres = array_values($caissieres);
        usort($caissieres, static fn (array $a, array $b): int => [$b['ca'], $a['nom']] <=> [$a['ca'], $b['nom']]);

        return $caissieres;
    }

    private function caDuJour(\DateTimeImmutable $jour): int
    {
        return (int) $this->connexion->fetchOne(
            "SELECT COALESCE(SUM(total_ttc), 0) FROM vente WHERE DATE(created_at) = ? AND statut = 'VALIDEE'",
            [$jour->format('Y-m-d')],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function ventesDuJour(\DateTimeImmutable $jour): array
    {
        return $this->connexion->fetchAssociative(
            "SELECT COUNT(*) AS nombre,
                    COALESCE(SUM(remise), 0) AS remise,
                    COALESCE(SUM(rendu), 0) AS rendu,
                    COALESCE(SUM(CASE WHEN remise > 0 THEN 1 ELSE 0 END), 0) AS nb_remises
             FROM vente WHERE DATE(created_at) = ? AND statut = 'VALIDEE'",
            [$jour->format('Y-m-d')],
        ) ?: [];
    }

    /**
     * Variation relative en points de base. Null si la référence est nulle
     * (aucun pourcentage n'est calculable à partir de zéro).
     */
    private function variationBp(int $actuel, int $reference): ?int
    {
        if (0 === $reference) {
            return null;
        }

        return (int) round(($actuel - $reference) * 10000 / $reference);
    }

    /**
     * Ventilation par moyen de paiement, rendu de monnaie déduit des espèces
     * (même convention que le rapport Z).
     *
     * @return list<array{mode: string, libelle: string, nombre: int, montant: int}>
     */
    private function ventilerReglements(\DateTimeImmutable $jour, int $renduTotal): array
    {
        $lignes = [];

        foreach ($this->connexion->fetchAllAssociative(
            "SELECT r.mode, COUNT(*) AS nombre, COALESCE(SUM(r.montant), 0) AS montant
             FROM reglement r
             JOIN vente v ON v.id = r.vente_id
             WHERE DATE(v.created_at) = ? AND v.statut = 'VALIDEE'
             GROUP BY r.mode",
            [$jour->format('Y-m-d')],
        ) as $ligne) {
            $mode = ModeReglement::from($ligne['mode']);
            $montant = (int) $ligne['montant'];

            if (ModeReglement::ESPECES === $mode) {
                $montant -= $renduTotal;
            }

            $lignes[] = [
                'mode' => $mode->value,
                'libelle' => $mode->libelle(),
                'nombre' => (int) $ligne['nombre'],
                'montant' => $montant,
            ];
        }

        usort($lignes, static fn (array $a, array $b): int => $b['montant'] <=> $a['montant']);

        return $lignes;
    }

    /**
     * @return list<array{nom: string, quantite: int, montant: int}>
     */
    private function topProduits(\DateTimeImmutable $jour): array
    {
        $lignes = [];

        foreach ($this->connexion->fetchAllAssociative(
            "SELECT a.nom,
                    COALESCE(SUM(lv.quantite), 0) AS quantite,
                    COALESCE(SUM((lv.quantite * lv.prix_unitaire + 500) DIV 1000 - lv.remise), 0) AS montant
             FROM ligne_vente lv
             JOIN vente v ON v.id = lv.vente_id
             JOIN article a ON a.id = lv.article_id
             WHERE DATE(v.created_at) = ? AND v.statut = 'VALIDEE'
             GROUP BY a.id, a.nom
             ORDER BY quantite DESC, montant DESC
             LIMIT 10",
            [$jour->format('Y-m-d')],
        ) as $ligne) {
            $lignes[] = [
                'nom' => (string) $ligne['nom'],
                'quantite' => (int) $ligne['quantite'],
                'montant' => (int) $ligne['montant'],
            ];
        }

        return $lignes;
    }

    /**
     * CA des 30 derniers jours, jours sans vente inclus (à zéro) pour que la
     * courbe reste régulière.
     *
     * @return list<array{jour: string, ca: int}>
     */
    private function serie(\DateTimeImmutable $jour): array
    {
        $debut = $jour->modify(\sprintf('-%d days', self::JOURS_COURBE - 1));

        $parJour = [];
        foreach ($this->connexion->fetchAllAssociative(
            "SELECT DATE(created_at) AS jour, COALESCE(SUM(total_ttc), 0) AS ca
             FROM vente
             WHERE statut = 'VALIDEE' AND DATE(created_at) BETWEEN ? AND ?
             GROUP BY DATE(created_at)",
            [$debut->format('Y-m-d'), $jour->format('Y-m-d')],
        ) as $ligne) {
            $parJour[(string) $ligne['jour']] = (int) $ligne['ca'];
        }

        $serie = [];
        for ($i = 0; $i < self::JOURS_COURBE; ++$i) {
            $date = $debut->modify(\sprintf('+%d days', $i))->format('Y-m-d');
            $serie[] = ['jour' => $date, 'ca' => $parJour[$date] ?? 0];
        }

        return $serie;
    }
}
