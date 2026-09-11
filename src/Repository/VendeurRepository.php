<?php

namespace App\Repository;

use App\Entity\Vendeur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Vendeur>
 */
class VendeurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Vendeur::class);
    }

    /** @return Pagination<Vendeur> */
    public function paginees(int $page = 1, ?string $recherche = null): Pagination
    {
        $qb = $this->createQueryBuilder('v')->orderBy('v.actif', 'DESC')->addOrderBy('v.nom', 'ASC');

        Recherche::appliquer($qb, $recherche, 'v.nom', 'v.telephone');

        return Pagination::depuis($qb, $page);
    }

    /** @return list<Vendeur> */
    public function actifs(): array
    {
        return $this->findBy(['actif' => true], ['nom' => 'ASC']);
    }
}
