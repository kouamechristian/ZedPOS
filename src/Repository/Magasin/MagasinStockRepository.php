<?php

namespace App\Repository\Magasin;

use App\Entity\Magasin\MagasinStock;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Lecture du stock du magasin. Aucune écriture ici : elle appartient à
 * {@see \App\Service\Magasin\MagasinStockService}.
 *
 * @extends ServiceEntityRepository<MagasinStock>
 */
class MagasinStockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MagasinStock::class);
    }

    /**
     * Stock total par produit, tous emplacements confondus, en millièmes — une
     * requête pour toute la liste.
     *
     * @param list<int> $produitIds
     *
     * @return array<int, int> par id de produit
     */
    public function totauxParProduit(array $produitIds): array
    {
        if ([] === $produitIds) {
            return [];
        }

        $totaux = array_fill_keys($produitIds, 0);
        foreach ($this->getEntityManager()->getConnection()->fetchAllKeyValue(
            'SELECT produit_id, SUM(quantite) FROM magasin_stock WHERE produit_id IN (?) GROUP BY produit_id',
            [$produitIds],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER],
        ) as $id => $quantite) {
            $totaux[(int) $id] = (int) $quantite;
        }

        return $totaux;
    }

    /**
     * Le détail par emplacement des produits donnés, emplacements chargés.
     *
     * @param list<int> $produitIds
     *
     * @return array<int, list<MagasinStock>> par id de produit, lignes non nulles seulement
     */
    public function detailParProduit(array $produitIds): array
    {
        if ([] === $produitIds) {
            return [];
        }

        $detail = [];
        foreach ($this->createQueryBuilder('s')
            ->join('s.emplacement', 'e')->addSelect('e')
            ->andWhere('IDENTITY(s.produit) IN (:ids)')->setParameter('ids', $produitIds)
            ->andWhere('s.quantite <> 0')
            ->orderBy('e.libelle', 'ASC')
            ->getQuery()->getResult() as $ligne) {
            $detail[(int) $ligne->getProduit()->getId()][] = $ligne;
        }

        return $detail;
    }
}
