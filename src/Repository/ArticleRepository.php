<?php

namespace App\Repository;

use App\Entity\Article;
use App\Entity\FamilleProduit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Article>
 */
class ArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Article::class);
    }

    /**
     * Recherche filtrée des articles (famille, texte, statut d'activation).
     *
     * @return Article[]
     */
    public function rechercher(?FamilleProduit $famille, ?string $recherche, ?bool $actif): array
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.familleProduit', 'f')
            ->addSelect('f')
            ->orderBy('f.position', 'ASC')
            ->addOrderBy('a.positionCaisse', 'ASC');

        if (null !== $famille) {
            $qb->andWhere('a.familleProduit = :famille')->setParameter('famille', $famille);
        }

        if (null !== $recherche && '' !== trim($recherche)) {
            $qb->andWhere('a.nom LIKE :recherche')->setParameter('recherche', '%'.trim($recherche).'%');
        }

        if (null !== $actif) {
            $qb->andWhere('a.actif = :actif')->setParameter('actif', $actif);
        }

        return $qb->getQuery()->getResult();
    }
}
