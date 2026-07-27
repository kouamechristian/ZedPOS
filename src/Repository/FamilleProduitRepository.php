<?php

namespace App\Repository;

use App\Entity\FamilleProduit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FamilleProduit>
 */
class FamilleProduitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FamilleProduit::class);
    }

    /**
     * Familles paginées, dans leur ordre d'affichage en caisse.
     *
     * @return Pagination<FamilleProduit>
     */
    public function paginees(int $page = 1, ?string $recherche = null): Pagination
    {
        $qb = $this->createQueryBuilder('f')->orderBy('f.position', 'ASC')->addOrderBy('f.nom', 'ASC');

        Recherche::appliquer($qb, $recherche, 'f.nom');

        return Pagination::depuis($qb, $page);
    }
}
