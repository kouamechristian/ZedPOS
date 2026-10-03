<?php

namespace App\Service\Magasin;

use App\Entity\Magasin\MagasinProduit;
use App\Enum\Magasin\TypeMouvementMagasin;
use App\Repository\Magasin\MagasinInventaireRepository;
use App\Repository\Magasin\MagasinProduitRepository;
use App\Repository\Magasin\MagasinReceptionRepository;
use App\Repository\Magasin\MagasinSortieRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Chiffres du tableau de bord du magasin, pour une journée : ce qui est passé à
 * chaque étape du plan de traitement, ce qui est entré et sorti, ce qui attend.
 *
 * **Tout est compté en unité de stock** (millièmes) et rendu par produit : on
 * n'additionne pas des sacs de farine et des cartons d'œufs. La quantité d'une
 * ligne de réception, saisie en unité d'achat, est ramenée en unité de stock
 * avec la contenance **figée sur la ligne**.
 *
 * - **Étapes** : chaque étape est datée par son propre horodatage — réception à
 *   sa date de livraison, contrôle, inspection et stockage au moment où ils ont
 *   été faits. Une réception reçue hier et stockée aujourd'hui compte donc au
 *   stockage du jour. Les réceptions annulées sont exclues : elles ne se lisent
 *   que dans leur liste.
 * - **Entrées / sorties** : lues dans les mouvements, **nettes des
 *   annulations** du jour — une réception stockée puis annulée dans la journée
 *   ne fait rien entrer. Les mouvements sont la seule vérité du stock ; les bons
 *   ne servent qu'à dire d'où ils viennent.
 *
 * SQL agrégé en base, une requête par bloc : rien n'est hydraté pour être compté.
 * Les produits au seuil viennent de {@see AlertesStockMagasin}, la même liste que
 * la bannière d'alerte : le tableau et l'alerte ne peuvent pas se contredire.
 */
class TableauDeBordMagasin
{
    public function __construct(
        private readonly Connection $connexion,
        private readonly MagasinProduitRepository $produits,
        private readonly MagasinReceptionRepository $receptions,
        private readonly MagasinSortieRepository $sorties,
        private readonly AlertesStockMagasin $alertes,
        private readonly MagasinInventaireRepository $inventaires,
    ) {
    }

    /**
     * @return array{
     *     etapes: array<string, array{nombre: int, lignes: list<array{produit: MagasinProduit, quantite: int}>, rejete?: list<array{produit: MagasinProduit, quantite: int}>}>,
     *     entrees: list<array{produit: MagasinProduit, quantite: int}>,
     *     sorties: list<array{produit: MagasinProduit, quantite: int}>,
     *     nombreSorties: int,
     *     receptionsParStatut: array<string, int>,
     *     sortiesParStatut: array<string, int>,
     *     sousSeuil: list<array{produit: MagasinProduit, quantite: int}>,
     * }
     */
    public function journee(\DateTimeImmutable $jour): array
    {
        $debut = $jour->setTime(0, 0);
        $fin = $debut->modify('+1 day');

        $inspection = $this->etape('inspectee_at', 'qte_acceptee', $debut, $fin);
        $inspection['rejete'] = $this->etape('inspectee_at', 'qte_rejetee', $debut, $fin)['lignes'];

        return [
            'etapes' => [
                'RECUE' => $this->etape('date_reception', 'qte_annoncee', $debut, $fin),
                'CONTROLEE' => $this->etape('controlee_at', 'qte_comptee', $debut, $fin),
                'INSPECTEE' => $inspection,
                'STOCKEE' => $this->etape('stockee_at', 'qte_acceptee', $debut, $fin),
            ],
            'entrees' => $this->mouvements([TypeMouvementMagasin::ENTREE_RECEPTION->value], 'reception', 1, $debut, $fin),
            'sorties' => $this->mouvements([TypeMouvementMagasin::SORTIE->value], 'sortie', -1, $debut, $fin),
            'nombreSorties' => (int) $this->connexion->fetchOne(
                "SELECT COUNT(*) FROM magasin_sortie WHERE statut = 'VALIDEE' AND validee_at >= ? AND validee_at < ?",
                [$this->sql($debut), $this->sql($fin)],
            ),
            'receptionsParStatut' => $this->receptions->compteParStatut(),
            'sortiesParStatut' => $this->sorties->compteParStatut(),
            'sousSeuil' => $this->alertes->produitsAuSeuil(),
            'inventairesEnCours' => $this->inventaires->enCours(),
            'dernierInventaire' => $this->inventaires->dernierValide(),
        ];
    }

    /**
     * Ce qui est passé par une étape dans la journée : nombre de réceptions et
     * quantité par produit, en unité de stock.
     *
     * @return array{nombre: int, lignes: list<array{produit: MagasinProduit, quantite: int}>}
     */
    private function etape(string $colonneDate, string $colonneQuantite, \DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        // Colonnes choisies dans une liste fermée par l'appelant : jamais une saisie.
        $filtre = "r.$colonneDate >= ? AND r.$colonneDate < ? AND r.statut <> 'ANNULEE'";
        $parametres = [$this->sql($debut), $this->sql($fin)];

        $nombre = (int) $this->connexion->fetchOne("SELECT COUNT(*) FROM magasin_reception r WHERE $filtre", $parametres);
        $sommes = $this->connexion->fetchAllKeyValue(
            "SELECT l.produit_id, SUM(l.$colonneQuantite * l.contenance)
             FROM magasin_ligne_reception l JOIN magasin_reception r ON r.id = l.reception_id
             WHERE $filtre AND l.$colonneQuantite IS NOT NULL
             GROUP BY l.produit_id",
            $parametres,
        );

        // Somme de (millièmes d'unité de saisie × millièmes de contenance) : on
        // revient en millièmes d'unité de stock en divisant une seule fois.
        return ['nombre' => $nombre, 'lignes' => $this->parProduit(array_map(static fn ($s): int => self::diviserArrondi((int) $s, 1000), $sommes))];
    }

    /**
     * Mouvements du jour d'un sens donné, nets des annulations de leurs documents.
     *
     * @param list<string> $types
     * @param int          $signe 1 pour les entrées, −1 pour les sorties (rendues positives)
     *
     * @return list<array{produit: MagasinProduit, quantite: int}>
     */
    private function mouvements(array $types, string $documentType, int $signe, \DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $sommes = $this->connexion->fetchAllKeyValue(
            'SELECT produit_id, SUM(quantite) FROM magasin_mouvement
             WHERE created_at >= ? AND created_at < ?
               AND (type IN (?) OR (type = ? AND document_type = ?))
             GROUP BY produit_id',
            [$this->sql($debut), $this->sql($fin), $types, TypeMouvementMagasin::ANNULATION->value, $documentType],
            [2 => ArrayParameterType::STRING],
        );

        return $this->parProduit(array_map(static fn ($s): int => $signe * (int) $s, $sommes));
    }

    /**
     * @param array<int|string, int> $quantites par id de produit
     *
     * @return list<array{produit: MagasinProduit, quantite: int}> triés par nom de produit
     */
    private function parProduit(array $quantites): array
    {
        $quantites = array_filter($quantites, static fn (int $q): bool => 0 !== $q);
        if ([] === $quantites) {
            return [];
        }

        $lignes = [];
        foreach ($this->produits->findBy(['id' => array_keys($quantites)], ['nom' => 'ASC']) as $produit) {
            $lignes[] = ['produit' => $produit, 'quantite' => $quantites[(int) $produit->getId()]];
        }

        return $lignes;
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
