<?php

namespace App\Service\Magasin;

use App\Entity\Magasin\MagasinProduit;
use App\Repository\Magasin\MagasinProduitRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Produits du magasin **au seuil d'alerte** : stock total (tous emplacements)
 * inférieur ou égal au seuil. « Atteint » compte : à 10 sacs pour un seuil de 10,
 * il est temps de commander, pas à 9.
 *
 * L'alerte n'est **pas stockée** : elle est relue sur le stock à chaque
 * affichage. Elle apparaît donc dès qu'une sortie fait atteindre le seuil et
 * disparaît d'elle-même quand une réception le fait remonter — rien à acquitter,
 * rien qui reste affiché à tort. Un produit inactif ou sans seuil (0) n'alerte
 * jamais.
 *
 * Mémorisé pour la durée de la requête : la bannière de la coquille, le compteur
 * du menu et le tableau de bord lisent la même liste sans requête de plus.
 */
class AlertesStockMagasin
{
    /** @var list<array{produit: MagasinProduit, quantite: int}>|null */
    private ?array $memoire = null;

    public function __construct(
        private readonly Connection $connexion,
        private readonly MagasinProduitRepository $produits,
    ) {
    }

    /**
     * Le plus en dessous d'abord, en proportion du seuil.
     *
     * @return list<array{produit: MagasinProduit, quantite: int}> quantité en millièmes d'unité de stock
     */
    public function produitsAuSeuil(): array
    {
        return $this->memoire ??= $this->lire(null);
    }

    /**
     * Parmi ces produits, ceux qui sont au seuil — l'avertissement qui suit une
     * sortie ne parle que de ce qu'elle a touché. Lu en base, sans mémoire : le
     * stock vient de bouger.
     *
     * @param list<MagasinProduit> $produits
     *
     * @return list<array{produit: MagasinProduit, quantite: int}>
     */
    public function parmi(array $produits): array
    {
        $ids = array_values(array_unique(array_map(static fn (MagasinProduit $p): int => (int) $p->getId(), $produits)));
        $this->memoire = null;

        return [] === $ids ? [] : $this->lire($ids);
    }

    /**
     * @param list<int>|null $ids
     *
     * @return list<array{produit: MagasinProduit, quantite: int}>
     */
    private function lire(?array $ids): array
    {
        $parametres = [];
        $types = [];
        $filtre = '';
        if (null !== $ids) {
            $filtre = ' AND p.id IN (?)';
            $parametres[] = $ids;
            $types[] = ArrayParameterType::INTEGER;
        }

        $totaux = $this->connexion->fetchAllKeyValue(
            'SELECT p.id, COALESCE(SUM(s.quantite), 0)
             FROM magasin_produit p LEFT JOIN magasin_stock s ON s.produit_id = p.id
             WHERE p.actif = 1 AND p.seuil_alerte > 0'.$filtre.'
             GROUP BY p.id, p.seuil_alerte
             HAVING COALESCE(SUM(s.quantite), 0) <= p.seuil_alerte',
            $parametres,
            $types,
        );
        if ([] === $totaux) {
            return [];
        }

        $lignes = [];
        foreach ($this->produits->findBy(['id' => array_keys($totaux)]) as $produit) {
            $lignes[] = ['produit' => $produit, 'quantite' => (int) $totaux[(int) $produit->getId()]];
        }
        // Rapport stock / seuil croissant, comparé en produits croisés : pas de division.
        usort($lignes, static fn (array $a, array $b): int => [$a['quantite'] * $b['produit']->getSeuilAlerte(), $a['produit']->getNom()]
            <=> [$b['quantite'] * $a['produit']->getSeuilAlerte(), $b['produit']->getNom()]);

        return $lignes;
    }
}
