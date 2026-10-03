<?php

namespace App\Service\Magasin;

use App\Entity\Magasin\MagasinProduit;
use App\Enum\Magasin\MotifSortieMagasin;
use App\Enum\Magasin\TypeMouvementMagasin;
use App\Repository\Magasin\MagasinProduitRepository;
use Doctrine\DBAL\Connection;

/**
 * Analyse des mouvements du magasin sur une période : pour chaque produit,
 *
 *     Stock début + Entrées − Sorties ± Ajustements = Stock fin
 *
 * **Tout est lu dans les mouvements**, la seule vérité du stock : le stock début
 * est la somme de tout ce qui a bougé avant la période, le stock fin celle de
 * tout ce qui a bougé jusqu'à sa fin. L'égalité tient donc par construction —
 * elle ne dépend d'aucun état intermédiaire.
 *
 * Chaque colonne est **nette des annulations** de ses propres documents : une
 * réception annulée dans la période retire ce qu'elle avait fait entrer, une
 * sortie annulée rend ce qu'elle avait fait sortir. Les sorties sont ventilées
 * par motif (production, perte…) — c'est ce qui sépare la consommation normale
 * de ce qui part à la poubelle. « Ajustements » reçoit tout le reste : les
 * corrections d'inventaire, et tout document futur — rien ne peut tomber hors
 * des colonnes et fausser l'égalité.
 *
 * Quantités en millièmes d'unité de stock. Valeur = stock fin × coût moyen
 * **actuel** (le coût moyen n'est pas historisé), réservée à MAGASIN_VOIR_PRIX
 * par les gabarits.
 */
class MagasinAnalyseService
{
    public function __construct(
        private readonly Connection $connexion,
        private readonly MagasinProduitRepository $produits,
    ) {
    }

    /**
     * @return list<array{
     *     produit: MagasinProduit,
     *     debut: int, entrees: int, sorties: int, sortiesParMotif: array<string, int>,
     *     ajustements: int, fin: int, valeur: int
     * }> sorties en positif ; triés par nom de produit
     */
    public function analyser(FiltreAnalyseMagasin $filtre): array
    {
        [$where, $parametres] = $this->filtres($filtre);
        $debut = $this->sql($filtre->debut());
        $fin = $this->sql($filtre->fin());

        $lignes = $this->connexion->fetchAllAssociative(
            "SELECT m.produit_id AS id,
                SUM(CASE WHEN m.created_at < :debut THEN m.quantite ELSE 0 END) AS debut,
                SUM(CASE WHEN m.created_at >= :debut AND m.document_type = 'reception' THEN m.quantite ELSE 0 END) AS entrees,
                SUM(CASE WHEN m.created_at >= :debut AND m.document_type = 'sortie' THEN m.quantite ELSE 0 END) AS sorties,
                SUM(CASE WHEN m.created_at >= :debut AND m.document_type NOT IN ('reception', 'sortie') THEN m.quantite ELSE 0 END) AS ajustements,
                SUM(m.quantite) AS fin
             FROM magasin_mouvement m JOIN magasin_produit p ON p.id = m.produit_id
             WHERE m.created_at < :fin $where
             GROUP BY m.produit_id",
            ['debut' => $debut, 'fin' => $fin] + $parametres,
        );
        $parId = [];
        foreach ($lignes as $ligne) {
            $parId[(int) $ligne['id']] = $ligne;
        }

        $motifs = [];
        foreach ($this->connexion->fetchAllAssociative(
            "SELECT m.produit_id AS id, s.motif, SUM(m.quantite) AS quantite
             FROM magasin_mouvement m
             JOIN magasin_produit p ON p.id = m.produit_id
             JOIN magasin_sortie s ON s.id = m.document_id
             WHERE m.document_type = 'sortie' AND m.created_at >= :debut AND m.created_at < :fin $where
             GROUP BY m.produit_id, s.motif",
            ['debut' => $debut, 'fin' => $fin] + $parametres,
        ) as $ligne) {
            if (0 !== (int) $ligne['quantite']) {
                $motifs[(int) $ligne['id']][(string) $ligne['motif']] = -(int) $ligne['quantite'];
            }
        }

        // Les produits actifs de la catégorie figurent même sans mouvement : un
        // produit à zéro est une information, pas une absence.
        $criteres = ['actif' => true];
        if (null !== $filtre->categorie) {
            $criteres['categorie'] = $filtre->categorie;
        }
        $produits = [];
        foreach ($this->produits->findBy($criteres) as $produit) {
            $produits[(int) $produit->getId()] = $produit;
        }
        $manquants = array_diff(array_keys($parId), array_keys($produits));
        if ([] !== $manquants) {
            foreach ($this->produits->findBy(['id' => array_values($manquants)]) as $produit) {
                $produits[(int) $produit->getId()] = $produit;
            }
        }
        uasort($produits, static fn (MagasinProduit $a, MagasinProduit $b): int => strcasecmp($a->getNom(), $b->getNom()));

        $resultat = [];
        foreach ($produits as $id => $produit) {
            $l = $parId[$id] ?? null;
            $fin = (int) ($l['fin'] ?? 0);
            $parMotif = [];
            foreach (MotifSortieMagasin::cases() as $motif) {
                if (isset($motifs[$id][$motif->value])) {
                    $parMotif[$motif->value] = $motifs[$id][$motif->value];
                }
            }
            $resultat[] = [
                'produit' => $produit,
                'debut' => (int) ($l['debut'] ?? 0),
                'entrees' => (int) ($l['entrees'] ?? 0),
                'sorties' => -(int) ($l['sorties'] ?? 0),
                'sortiesParMotif' => $parMotif,
                'ajustements' => (int) ($l['ajustements'] ?? 0),
                'fin' => $fin,
                'valeur' => self::diviserArrondi($fin * $produit->getCoutMoyen(), 1000),
            ];
        }

        return $resultat;
    }

    /**
     * La fiche de stock d'un produit : ses mouvements de la période, dans l'ordre,
     * avec le solde après chacun — en partant du stock au début de la période.
     *
     * @return array{debut: int, fin: int, mouvements: list<array{
     *     date: \DateTimeImmutable, type: TypeMouvementMagasin, quantite: int, solde: int,
     *     emplacement: string, auteur: ?string, motif: ?string,
     *     documentType: string, documentId: int, numero: ?string
     * }>}
     */
    public function fiche(MagasinProduit $produit, FiltreAnalyseMagasin $filtre): array
    {
        $parametres = ['produit' => $produit->getId(), 'debut' => $this->sql($filtre->debut()), 'fin' => $this->sql($filtre->fin())];
        $lieu = '';
        if (null !== $filtre->emplacement) {
            $lieu = ' AND m.emplacement_id = :emplacement';
            $parametres['emplacement'] = $filtre->emplacement->getId();
        }

        $solde = (int) $this->connexion->fetchOne(
            "SELECT COALESCE(SUM(m.quantite), 0) FROM magasin_mouvement m WHERE m.produit_id = :produit AND m.created_at < :debut$lieu",
            $parametres,
        );
        $debut = $solde;

        // Le numéro du document est joint selon son type : la fiche le rend
        // cliquable sans une requête par ligne.
        $lignes = $this->connexion->fetchAllAssociative(
            "SELECT m.created_at, m.type, m.quantite, m.motif, m.document_type, m.document_id,
                    e.libelle AS emplacement, u.nom AS auteur,
                    COALESCE(r.numero, s.numero, i.numero) AS numero
             FROM magasin_mouvement m
             JOIN magasin_emplacement e ON e.id = m.emplacement_id
             LEFT JOIN utilisateur u ON u.id = m.auteur_id
             LEFT JOIN magasin_reception r ON m.document_type = 'reception' AND r.id = m.document_id
             LEFT JOIN magasin_sortie s ON m.document_type = 'sortie' AND s.id = m.document_id
             LEFT JOIN magasin_inventaire i ON m.document_type = 'inventaire' AND i.id = m.document_id
             WHERE m.produit_id = :produit AND m.created_at >= :debut AND m.created_at < :fin$lieu
             ORDER BY m.created_at, m.id",
            $parametres,
        );

        $mouvements = [];
        foreach ($lignes as $ligne) {
            $solde += (int) $ligne['quantite'];
            $mouvements[] = [
                'date' => new \DateTimeImmutable((string) $ligne['created_at']),
                'type' => TypeMouvementMagasin::from((string) $ligne['type']),
                'quantite' => (int) $ligne['quantite'],
                'solde' => $solde,
                'emplacement' => (string) $ligne['emplacement'],
                'auteur' => null !== $ligne['auteur'] ? (string) $ligne['auteur'] : null,
                'motif' => null !== $ligne['motif'] ? (string) $ligne['motif'] : null,
                'documentType' => (string) $ligne['document_type'],
                'documentId' => (int) $ligne['document_id'],
                'numero' => null !== $ligne['numero'] ? (string) $ligne['numero'] : null,
            ];
        }

        return ['debut' => $debut, 'fin' => $solde, 'mouvements' => $mouvements];
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function filtres(FiltreAnalyseMagasin $filtre): array
    {
        $where = '';
        $parametres = [];
        if (null !== $filtre->categorie) {
            $where .= ' AND p.categorie = :categorie';
            $parametres['categorie'] = $filtre->categorie->value;
        }
        if (null !== $filtre->emplacement) {
            $where .= ' AND m.emplacement_id = :emplacement';
            $parametres['emplacement'] = $filtre->emplacement->getId();
        }

        return [$where, $parametres];
    }

    private function sql(\DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    private static function diviserArrondi(int $numerateur, int $denominateur): int
    {
        $signe = $numerateur < 0 ? -1 : 1;

        return $signe * intdiv(abs($numerateur) * 2 + $denominateur, $denominateur * 2);
    }
}
