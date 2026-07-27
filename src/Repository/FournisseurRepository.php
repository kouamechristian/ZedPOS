<?php

namespace App\Repository;

use App\Entity\Fournisseur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Fournisseur>
 */
class FournisseurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Fournisseur::class);
    }

    /**
     * @return Pagination<Fournisseur>
     */
    public function paginees(int $page = 1, ?string $recherche = null): Pagination
    {
        $qb = $this->createQueryBuilder('f')->orderBy('f.nom', 'ASC');

        // On cherche un fournisseur aussi souvent par son numéro que par son nom :
        // le carnet d'adresses du magasin tient dans cette table.
        Recherche::appliquer($qb, $recherche, 'f.nom', 'f.telephone', 'f.email');

        return Pagination::depuis($qb, $page);
    }
}
