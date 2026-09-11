<?php

namespace App\Repository;

use App\Entity\RemboursementDette;
use App\Entity\Vendeur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RemboursementDette>
 */
class RemboursementDetteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RemboursementDette::class);
    }

    /** @return Pagination<RemboursementDette> les plus récents d'abord */
    public function paginesDe(Vendeur $vendeur, int $page = 1): Pagination
    {
        $qb = $this->createQueryBuilder('r')
            ->join('r.dette', 'd')->addSelect('d')
            ->join('r.encaissePar', 'u')->addSelect('u')
            ->andWhere('d.vendeur = :vendeur')->setParameter('vendeur', $vendeur)
            ->orderBy('r.date', 'DESC')->addOrderBy('r.id', 'DESC');

        return Pagination::depuis($qb, $page);
    }
}
