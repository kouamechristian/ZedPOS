<?php

namespace App\Service\Rapport;

use App\Repository\DetteVendeurRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Rapports des stands, **en lecture seule**.
 *
 * **Tout part des lignes de dotation**, à la date de leur dotation — jamais de
 * l'arrêté qui les a soldées. À la validation d'un point, chaque ligne a reçu sa
 * part du vendu, des retours, du chiffre et de la rémunération
 * ({@see \App\Service\Point\RepartitionVentes}) : un arrêté mensuel n'empêche donc
 * pas un rapport journalier d'être juste. Une ligne dont le point n'est pas encore
 * validé compte dans le **confié** et dans « en attente de point », nulle part
 * ailleurs.
 *
 * Deux exceptions, faute de pouvoir faire autrement : les **écarts d'espèces**
 * n'existent qu'au point, ils se lisent à la **fin de période** de l'arrêté ; la
 * **dette** d'un vendeur est celle d'aujourd'hui.
 *
 * **Pas de double comptage.** Une vente de stand ne crée **aucune** `Vente` : le CA
 * caisse se lit dans `vente`, le CA stands dans `ligne_dotation`, et le CA global
 * est leur somme — deux sources disjointes, jamais la même vente deux fois.
 *
 * Montants en centimes, quantités en millièmes, taux en points de base.
 */
class RapportStands
{
    public function __construct(
        private readonly Connection $connexion,
        private readonly DetteVendeurRepository $dettes,
    ) {
    }

    /**
     * @return array{tranches: array<string, string>, groupes: list<array<string, mixed>>, detail: list<array<string, mixed>>, total: array<string, mixed>, series: list<array{libelle: string, valeurs: list<int>}>}
     */
    public function parStand(FiltreRapport $filtre): array
    {
        $lignes = $this->lignesParJour($filtre, 'b.stand_id');
        $ecarts = $this->ecartsParJour($filtre, 'a.stand_id');
        $noms = $this->noms('SELECT id, libelle FROM emplacement WHERE id IN (?)', array_unique([...array_column($lignes, 'cle'), ...array_column($ecarts, 'cle')]));

        return $this->agreger($filtre, $lignes, $ecarts, $noms, 'stand');
    }

    /**
     * @return array{tranches: array<string, string>, groupes: list<array<string, mixed>>, detail: list<array<string, mixed>>, total: array<string, mixed>, series: list<array{libelle: string, valeurs: list<int>}>}
     */
    public function parVendeur(FiltreRapport $filtre): array
    {
        $lignes = $this->lignesParJour($filtre, 'b.vendeur_id');
        $ecarts = $this->ecartsParJour($filtre, 'a.vendeur_id');
        $ids = array_unique([...array_column($lignes, 'cle'), ...array_column($ecarts, 'cle')]);
        $noms = $this->noms('SELECT id, nom FROM vendeur WHERE id IN (?)', $ids);

        $rapport = $this->agreger($filtre, $lignes, $ecarts, $noms, 'vendeur');
        $soldes = $this->dettes->soldes();
        foreach ($rapport['groupes'] as &$groupe) {
            $groupe['dette'] = $soldes[$groupe['id']] ?? 0;
        }
        unset($groupe);
        $rapport['total']['dette'] = array_sum(array_column($rapport['groupes'], 'dette'));

        return $rapport;
    }

    /**
     * CA caisse et CA stands, tranche par tranche. Le CA stands est la valeur de
     * vente de ce que les vendeurs ont écoulé ; le net est ce qui revient à la
     * boutique une fois leur rémunération déduite.
     *
     * @return array{tranches: array<string, string>, lignes: list<array<string, mixed>>, total: array<string, mixed>}
     */
    public function comparatif(FiltreRapport $filtre): array
    {
        $vide = static fn (string $libelle): array => ['tranche' => $libelle, 'caCaisse' => 0, 'tickets' => 0, 'caStands' => 0, 'netStands' => 0, 'enAttente' => 0];
        $tranches = $filtre->tranches();
        $lignes = array_map($vide, $tranches);

        $caisse = $this->connexion->fetchAllAssociative(
            "SELECT DATE(created_at) AS jour, COALESCE(SUM(total_ttc), 0) AS ca, COUNT(*) AS tickets
             FROM vente
             WHERE statut = 'VALIDEE' AND DATE(created_at) BETWEEN ? AND ?
             GROUP BY DATE(created_at)",
            [$filtre->du->format('Y-m-d'), $filtre->au->format('Y-m-d')],
        );
        foreach ($caisse as $jour) {
            $cle = $filtre->cle((string) $jour['jour']);
            $lignes[$cle]['caCaisse'] += (int) $jour['ca'];
            $lignes[$cle]['tickets'] += (int) $jour['tickets'];
        }

        foreach ($this->lignesParJour($filtre, "'tous'") as $jour) {
            $cle = $filtre->cle($jour['jour']);
            $lignes[$cle]['caStands'] += $jour['ca'];
            $lignes[$cle]['netStands'] += $jour['ca'] - $jour['remuneration'];
            $lignes[$cle]['enAttente'] += $jour['enAttente'];
        }

        $total = $vide('Total');
        foreach ($lignes as &$ligne) {
            $ligne['caGlobal'] = $ligne['caCaisse'] + $ligne['caStands'];
            $ligne['partStands'] = self::taux($ligne['caStands'], $ligne['caGlobal']);
            foreach (['caCaisse', 'tickets', 'caStands', 'netStands', 'enAttente'] as $champ) {
                $total[$champ] += $ligne[$champ];
            }
        }
        unset($ligne);
        $total['caGlobal'] = $total['caCaisse'] + $total['caStands'];
        $total['partStands'] = self::taux($total['caStands'], $total['caGlobal']);

        return ['tranches' => $tranches, 'libelles' => array_values($tranches), 'lignes' => array_values($lignes), 'total' => $total];
    }

    /**
     * Les produits les plus vendus de chaque stand sur la plage.
     *
     * @return list<array{stand: string, produits: list<array<string, mixed>>}>
     */
    public function topProduits(FiltreRapport $filtre, int $nombre = 10): array
    {
        $lignes = $this->connexion->fetchAllAssociative(
            "SELECT e.id AS stand_id, e.libelle AS stand, a.nom AS produit, a.unite,
                    COALESCE(SUM(l.qte_vendue), 0) AS vendue, COALESCE(SUM(l.qte_retournee), 0) AS retournee,
                    COALESCE(SUM(l.qte_perdue), 0) AS perdue, COALESCE(SUM(l.montant_vendu), 0) AS ca
             FROM ligne_dotation l
             JOIN bon_dotation b ON b.id = l.bon_id
             JOIN emplacement e ON e.id = b.stand_id
             JOIN article a ON a.id = l.produit_id
             WHERE b.statut = 'VALIDE' AND l.qte_vendue IS NOT NULL AND b.date_dotation BETWEEN ? AND ?
             GROUP BY e.id, e.libelle, a.id, a.nom, a.unite
             ORDER BY e.libelle ASC, vendue DESC, ca DESC, a.nom ASC",
            [$filtre->du->format('Y-m-d'), $filtre->au->format('Y-m-d')],
        );

        $stands = [];
        foreach ($lignes as $ligne) {
            $stand = (int) $ligne['stand_id'];
            $stands[$stand] ??= ['stand' => (string) $ligne['stand'], 'produits' => []];
            if (\count($stands[$stand]['produits']) >= $nombre || 0 === (int) $ligne['vendue']) {
                continue;
            }

            $arretee = (int) $ligne['vendue'] + (int) $ligne['retournee'] + (int) $ligne['perdue'];
            $stands[$stand]['produits'][] = [
                'rang' => \count($stands[$stand]['produits']) + 1,
                'produit' => (string) $ligne['produit'],
                'unite' => (string) $ligne['unite'],
                'vendue' => (int) $ligne['vendue'],
                'retournee' => (int) $ligne['retournee'],
                'perdue' => (int) $ligne['perdue'],
                'ca' => (int) $ligne['ca'],
                'tauxInvendus' => self::taux((int) $ligne['retournee'], $arretee),
            ];
        }

        return array_values($stands);
    }

    /**
     * Suggestion de dotation : moyenne, par produit, du vendu du stand sur les
     * **quatre derniers mêmes jours de semaine** qui ont un vendu connu (point
     * validé), arrondie à l'unité la plus proche. Un produit absent l'un de ces jours
     * y compte pour zéro.
     *
     * @return array{quantites: array<string, int>, jours: list<string>} unités par id de produit, jours retenus (Y-m-d, du plus récent)
     */
    public function suggestion(int $standId, \DateTimeImmutable $pour): array
    {
        $jours = $this->connexion->fetchFirstColumn(
            "SELECT DISTINCT b.date_dotation
             FROM ligne_dotation l JOIN bon_dotation b ON b.id = l.bon_id
             WHERE b.stand_id = ? AND b.statut = 'VALIDE' AND l.qte_vendue IS NOT NULL
               AND b.date_dotation < ? AND DAYOFWEEK(b.date_dotation) = DAYOFWEEK(?)
             ORDER BY b.date_dotation DESC
             LIMIT 4",
            [$standId, $pour->format('Y-m-d'), $pour->format('Y-m-d')],
        );

        if ([] === $jours) {
            return ['quantites' => [], 'jours' => []];
        }

        $ventes = $this->connexion->fetchAllKeyValue(
            "SELECT l.produit_id, SUM(l.qte_vendue)
             FROM ligne_dotation l JOIN bon_dotation b ON b.id = l.bon_id
             WHERE b.stand_id = ? AND b.statut = 'VALIDE' AND l.qte_vendue IS NOT NULL AND b.date_dotation IN (?)
             GROUP BY l.produit_id",
            [$standId, $jours],
            [ParameterType::INTEGER, ArrayParameterType::STRING],
        );

        $n = \count($jours);
        $quantites = [];
        foreach ($ventes as $produit => $millimes) {
            // Moyenne en unités, demi-unité vers le haut, en entiers.
            $unites = intdiv((int) $millimes + $n * 500, $n * 1000);
            if ($unites > 0) {
                $quantites[(string) $produit] = $unites;
            }
        }

        return ['quantites' => $quantites, 'jours' => array_map('strval', $jours)];
    }

    // ------------------------------------------------------------------------

    /**
     * Lignes de dotation agrégées par jour et par `$groupe` (colonne SQL).
     *
     * @return list<array{jour: string, cle: int|string, confiee: int, enAttente: int, vendue: int, retournee: int, perdue: int, valeurPertes: int, ca: int, remuneration: int}>
     */
    private function lignesParJour(FiltreRapport $filtre, string $groupe): array
    {
        $lignes = $this->connexion->fetchAllAssociative(
            "SELECT b.date_dotation AS jour, $groupe AS cle,
                    SUM(l.quantite) AS confiee,
                    SUM(CASE WHEN l.qte_vendue IS NULL THEN l.quantite ELSE 0 END) AS en_attente,
                    COALESCE(SUM(l.qte_vendue), 0) AS vendue,
                    COALESCE(SUM(l.qte_retournee), 0) AS retournee,
                    COALESCE(SUM(l.qte_perdue), 0) AS perdue,
                    COALESCE(SUM(l.qte_perdue * l.prix_vente_unitaire), 0) AS pertes_x1000,
                    COALESCE(SUM(l.montant_vendu), 0) AS ca,
                    COALESCE(SUM(l.remuneration), 0) AS remuneration
             FROM ligne_dotation l
             JOIN bon_dotation b ON b.id = l.bon_id
             WHERE b.statut = 'VALIDE' AND b.date_dotation BETWEEN ? AND ?
             GROUP BY b.date_dotation, $groupe",
            [$filtre->du->format('Y-m-d'), $filtre->au->format('Y-m-d')],
        );

        return array_map(static fn (array $l): array => [
            'jour' => (string) $l['jour'],
            'cle' => is_numeric($l['cle']) ? (int) $l['cle'] : (string) $l['cle'],
            'confiee' => (int) $l['confiee'],
            'enAttente' => (int) $l['en_attente'],
            'vendue' => (int) $l['vendue'],
            'retournee' => (int) $l['retournee'],
            'perdue' => (int) $l['perdue'],
            // Quantités entières d'unités : la division tombe juste.
            'valeurPertes' => intdiv((int) $l['pertes_x1000'], 1000),
            'ca' => (int) $l['ca'],
            'remuneration' => (int) $l['remuneration'],
        ], $lignes);
    }

    /**
     * Écarts d'espèces des points validés, à leur fin de période.
     *
     * @return list<array{jour: string, cle: int, manquants: int, tropPercus: int, points: int}>
     */
    private function ecartsParJour(FiltreRapport $filtre, string $groupe): array
    {
        $lignes = $this->connexion->fetchAllAssociative(
            "SELECT a.date_fin AS jour, $groupe AS cle,
                    COALESCE(SUM(CASE WHEN a.ecart < 0 THEN a.ecart ELSE 0 END), 0) AS manquants,
                    COALESCE(SUM(CASE WHEN a.ecart > 0 THEN a.ecart ELSE 0 END), 0) AS trop_percus,
                    COUNT(*) AS points
             FROM arrete a
             WHERE a.statut = 'VALIDE' AND a.date_fin BETWEEN ? AND ?
             GROUP BY a.date_fin, $groupe",
            [$filtre->du->format('Y-m-d'), $filtre->au->format('Y-m-d')],
        );

        return array_map(static fn (array $l): array => [
            'jour' => (string) $l['jour'],
            'cle' => (int) $l['cle'],
            'manquants' => (int) $l['manquants'],
            'tropPercus' => (int) $l['trop_percus'],
            'points' => (int) $l['points'],
        ], $lignes);
    }

    /**
     * @param list<array<string, mixed>> $lignes
     * @param list<array<string, mixed>> $ecarts
     * @param array<int, string>         $noms
     *
     * @return array{tranches: array<string, string>, groupes: list<array<string, mixed>>, detail: list<array<string, mixed>>, total: array<string, mixed>, series: list<array{libelle: string, valeurs: list<int>}>}
     */
    private function agreger(FiltreRapport $filtre, array $lignes, array $ecarts, array $noms, string $nature): array
    {
        $vide = static fn (): array => ['confiee' => 0, 'enAttente' => 0, 'vendue' => 0, 'retournee' => 0, 'perdue' => 0, 'valeurPertes' => 0, 'ca' => 0, 'remuneration' => 0, 'manquants' => 0, 'tropPercus' => 0, 'points' => 0];
        $tranches = $filtre->tranches();

        $groupes = [];
        $detail = [];
        foreach ([...$lignes, ...$ecarts] as $ligne) {
            $id = (int) $ligne['cle'];
            $tranche = $filtre->cle($ligne['jour']);
            $groupes[$id] ??= $vide();
            $detail[$tranche][$id] ??= $vide();

            foreach ($ligne as $champ => $valeur) {
                if (\is_int($valeur) && 'cle' !== $champ) {
                    $groupes[$id][$champ] += $valeur;
                    $detail[$tranche][$id][$champ] += $valeur;
                }
            }
        }

        $finir = static function (array $chiffres) {
            $arretee = $chiffres['vendue'] + $chiffres['retournee'] + $chiffres['perdue'];
            $chiffres['net'] = $chiffres['ca'] - $chiffres['remuneration'];
            $chiffres['ecart'] = $chiffres['manquants'] + $chiffres['tropPercus'];
            $chiffres['tauxInvendus'] = self::taux($chiffres['retournee'], $arretee);

            return $chiffres;
        };

        uksort($groupes, static fn (int $a, int $b): int => strcmp($noms[$a] ?? '', $noms[$b] ?? ''));

        $sortie = [];
        $total = $vide();
        $series = [];
        foreach ($groupes as $id => $chiffres) {
            $sortie[] = ['id' => $id, $nature => $noms[$id] ?? '#'.$id] + $finir($chiffres);
            foreach ($chiffres as $champ => $valeur) {
                $total[$champ] += $valeur;
            }
            $series[] = [
                'libelle' => $noms[$id] ?? '#'.$id,
                // FCFA entiers : le graphique n'affiche que des francs.
                'valeurs' => array_map(static fn (string $cle): int => intdiv($detail[$cle][$id]['ca'] ?? 0, 100), array_keys($tranches)),
            ];
        }

        $lignesDetail = [];
        foreach ($tranches as $cle => $libelle) {
            foreach (array_keys($groupes) as $id) {
                if (isset($detail[$cle][$id])) {
                    $lignesDetail[] = ['tranche' => $libelle, $nature => $noms[$id] ?? '#'.$id] + $finir($detail[$cle][$id]);
                }
            }
        }

        return [
            'tranches' => $tranches,
            'libelles' => array_values($tranches),
            'groupes' => $sortie,
            'detail' => $lignesDetail,
            'total' => $finir($total),
            'series' => $series,
        ];
    }

    /**
     * @param list<int|string> $ids
     *
     * @return array<int, string>
     */
    private function noms(string $sql, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return array_map('strval', $this->connexion->fetchAllKeyValue($sql, [array_values($ids)], [ArrayParameterType::INTEGER]));
    }

    /** Part en points de base, `null` si la base est nulle. */
    private static function taux(int $part, int $base): ?int
    {
        return $base > 0 ? intdiv($part * 10000, $base) : null;
    }
}
