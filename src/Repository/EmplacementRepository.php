<?php

namespace App\Repository;

use App\Entity\Emplacement;
use App\Enum\TypeEmplacement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Emplacement>
 */
class EmplacementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Emplacement::class);
    }

    /**
     * Stands paginés, vendeur habituel chargé dans la même requête.
     *
     * @return Pagination<Emplacement>
     */
    public function standsPagines(int $page = 1, ?string $recherche = null): Pagination
    {
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.vendeurHabituel', 'v')->addSelect('v')
            ->andWhere('e.type = :stand')->setParameter('stand', TypeEmplacement::STAND)
            ->orderBy('e.actif', 'DESC')->addOrderBy('e.libelle', 'ASC');

        Recherche::appliquer($qb, $recherche, 'e.code', 'e.libelle', 'v.nom');

        return Pagination::depuis($qb, $page);
    }

    /** @return list<Emplacement> */
    public function standsActifs(): array
    {
        return $this->createQueryBuilder('e')
            ->leftJoin('e.vendeurHabituel', 'v')->addSelect('v')
            ->andWhere('e.type = :stand')->setParameter('stand', TypeEmplacement::STAND)
            ->andWhere('e.actif = true')
            ->orderBy('e.libelle', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function standActif(int $id): ?Emplacement
    {
        $emplacement = $this->find($id);

        return null !== $emplacement && $emplacement->estStand() && $emplacement->isActif() ? $emplacement : null;
    }
}
