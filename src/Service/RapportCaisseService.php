<?php

namespace App\Service;

use App\Entity\SessionCaisse;
use App\Enum\ModeReglement;
use App\Enum\TypeMouvementCaisse;
use App\Repository\MouvementCaisseRepository;
use Doctrine\DBAL\Connection;

/**
 * Agrège les chiffres d'une session de caisse (ticket X et rapport Z).
 *
 * Les requêtes sont écrites en SQL : ce sont des agrégats de reporting, et
 * l'arithmétique reste entière (DIV, jamais de division flottante).
 */
class RapportCaisseService
{
    public function __construct(
        private readonly Connection $connexion,
        private readonly MouvementCaisseRepository $mouvements,
    ) {
    }

    /**
     * @param bool $definitif true pour un rapport Z (session clôturée), false pour un ticket X
     */
    public function construire(SessionCaisse $session, bool $definitif = false): RapportCaisse
    {
        $id = $session->getId() ?? 0;

        $ventes = $this->connexion->fetchAssociative(
            "SELECT COUNT(*) AS nombre,
                    COALESCE(SUM(total_ttc), 0)  AS ttc,
                    COALESCE(SUM(total_ht), 0)   AS ht,
                    COALESCE(SUM(total_tva), 0)  AS tva,
                    COALESCE(SUM(remise), 0)     AS remise,
                    COALESCE(SUM(rendu), 0)      AS rendu,
                    COALESCE(SUM(CASE WHEN remise > 0 THEN 1 ELSE 0 END), 0) AS nb_remises
             FROM vente WHERE session_caisse_id = ? AND statut = 'VALIDEE'",
            [$id],
        ) ?: [];

        $nombreTickets = (int) ($ventes['nombre'] ?? 0);
        $caTotal = (int) ($ventes['ttc'] ?? 0);
        $renduTotal = (int) ($ventes['rendu'] ?? 0);

        $annulations = $this->connexion->fetchAssociative(
            "SELECT COUNT(*) AS nombre, COALESCE(SUM(total_ttc), 0) AS montant
             FROM vente WHERE session_caisse_id = ? AND statut = 'ANNULEE'",
            [$id],
        ) ?: [];

        [$parReglement, $especes] = $this->ventilerReglements($id, $renduTotal);
        [$depenses, $sorties] = $this->ventilerMouvements($id);

        // Fond théorique attendu dans le tiroir à la clôture.
        $theorique = $session->getTheorique()
            ?? $session->getFondCaisse() + $especes - $depenses - $sorties;

        return new RapportCaisse(
            session: $session,
            definitif: $definitif,
            genereA: new \DateTimeImmutable(),
            caTotal: $caTotal,
            totalHt: (int) ($ventes['ht'] ?? 0),
            totalTva: (int) ($ventes['tva'] ?? 0),
            nombreTickets: $nombreTickets,
            panierMoyen: $nombreTickets > 0 ? intdiv($caTotal, $nombreTickets) : 0,
            parReglement: $parReglement,
            parFamille: $this->ventilerFamilles($id),
            remisesMontant: (int) ($ventes['remise'] ?? 0),
            remisesNombre: (int) ($ventes['nb_remises'] ?? 0),
            annulationsMontant: (int) ($annulations['montant'] ?? 0),
            annulationsNombre: (int) ($annulations['nombre'] ?? 0),
            especesEncaissees: $especes,
            renduTotal: $renduTotal,
            depenses: $depenses,
            sorties: $sorties,
            mouvements: $this->mouvements->pourSession($session),
            fondCaisse: $session->getFondCaisse(),
            theorique: $theorique,
            montantCompte: $session->getMontantCompte(),
            ecart: $session->getEcart(),
        );
    }

    /**
     * Fond théorique attendu dans le tiroir :
     * fond de caisse + espèces encaissées − dépenses − sorties.
     */
    public function theorique(SessionCaisse $session): int
    {
        $id = $session->getId() ?? 0;

        $rendu = (int) $this->connexion->fetchOne(
            "SELECT COALESCE(SUM(rendu), 0) FROM vente WHERE session_caisse_id = ? AND statut = 'VALIDEE'",
            [$id],
        );
        [, $especes] = $this->ventilerReglements($id, $rendu);
        [$depenses, $sorties] = $this->ventilerMouvements($id);

        return $session->getFondCaisse() + $especes - $depenses - $sorties;
    }

    /**
     * Ventilation par moyen de paiement. Le rendu de monnaie (toujours rendu en
     * espèces) est déduit de la ligne ESPECES : la ventilation somme donc
     * exactement au CA, et la ligne espèces vaut ce qui reste réellement en caisse.
     *
     * @return array{0: list<array{mode: string, libelle: string, nombre: int, montant: int}>, 1: int}
     */
    private function ventilerReglements(int $sessionId, int $renduTotal): array
    {
        $lignes = [];
        $especes = 0;

        foreach ($this->connexion->fetchAllAssociative(
            "SELECT r.mode, COUNT(*) AS nombre, COALESCE(SUM(r.montant), 0) AS montant
             FROM reglement r
             JOIN vente v ON v.id = r.vente_id
             WHERE v.session_caisse_id = ? AND v.statut = 'VALIDEE'
             GROUP BY r.mode",
            [$sessionId],
        ) as $ligne) {
            $mode = ModeReglement::from($ligne['mode']);
            $montant = (int) $ligne['montant'];

            if (ModeReglement::ESPECES === $mode) {
                $montant -= $renduTotal;
                $especes = $montant;
            }

            $lignes[] = [
                'mode' => $mode->value,
                'libelle' => $mode->libelle(),
                'nombre' => (int) $ligne['nombre'],
                'montant' => $montant,
            ];
        }

        usort($lignes, static fn (array $a, array $b): int => $b['montant'] <=> $a['montant']);

        return [$lignes, $especes];
    }

    /**
     * @return list<array{famille: string, quantite: int, montant: int}>
     */
    private function ventilerFamilles(int $sessionId): array
    {
        $lignes = [];

        foreach ($this->connexion->fetchAllAssociative(
            "SELECT COALESCE(f.nom, 'Sans famille') AS famille,
                    COALESCE(SUM(lv.quantite), 0) AS quantite,
                    COALESCE(SUM((lv.quantite * lv.prix_unitaire + 500) DIV 1000 - lv.remise), 0) AS montant
             FROM ligne_vente lv
             JOIN vente v ON v.id = lv.vente_id
             JOIN article a ON a.id = lv.article_id
             LEFT JOIN famille_produit f ON f.id = a.famille_produit_id
             WHERE v.session_caisse_id = ? AND v.statut = 'VALIDEE'
             GROUP BY famille ORDER BY montant DESC",
            [$sessionId],
        ) as $ligne) {
            $lignes[] = [
                'famille' => (string) $ligne['famille'],
                'quantite' => (int) $ligne['quantite'],
                'montant' => (int) $ligne['montant'],
            ];
        }

        return $lignes;
    }

    /**
     * @return array{0: int, 1: int} [dépenses, sorties] en centimes
     */
    private function ventilerMouvements(int $sessionId): array
    {
        $totaux = [TypeMouvementCaisse::DEPENSE->value => 0, TypeMouvementCaisse::SORTIE->value => 0];

        foreach ($this->connexion->fetchAllAssociative(
            'SELECT type, COALESCE(SUM(montant), 0) AS montant
             FROM mouvement_caisse WHERE session_caisse_id = ? GROUP BY type',
            [$sessionId],
        ) as $ligne) {
            $totaux[$ligne['type']] = (int) $ligne['montant'];
        }

        return [$totaux[TypeMouvementCaisse::DEPENSE->value], $totaux[TypeMouvementCaisse::SORTIE->value]];
    }
}
