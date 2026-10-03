<?php

namespace App\Repository\Magasin;

use App\Entity\Magasin\MagasinEmplacement;
use App\Repository\Pagination;
use App\Repository\Recherche;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MagasinEmplacement>
 */
class MagasinEmplacementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MagasinEmplacement::class);
    }

    /** @return Pagination<MagasinEmplacement> */
    public function pagines(int $page = 1, ?string $recherche = null): Pagination
    {
        $qb = $this->createQueryBuilder('e')->orderBy('e.libelle', 'ASC');
        Recherche::appliquer($qb, $recherche, 'e.code', 'e.libelle');

        return Pagination::depuis($qb, $page);
    }

    /** @return list<MagasinEmplacement> la réserve par défaut d'abord, puis par libellé */
    public function actifs(): array
    {
        $emplacements = $this->findBy(['actif' => true], ['libelle' => 'ASC']);
        usort($emplacements, static fn (MagasinEmplacement $a, MagasinEmplacement $b): int => (int) $b->estReserveParDefaut() <=> (int) $a->estReserveParDefaut());

        return $emplacements;
    }
}
