<?php

namespace App\Repository;

use App\Entity\Vente;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Vente>
 */
class VenteRepository extends ServiceEntityRepository
{
    /** Tickets par page sur la liste de pilotage. */
    public const PAR_PAGE = 30;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Vente::class);
    }

    /**
     * Tickets d'une journée, du plus récent au plus ancien, avec le caissier
     * (jointure anticipée : la liste affiche son nom sur chaque ligne).
     *
     * @return array{ventes: list<Vente>, total: int, pages: int, page: int}
     */
    public function journee(\DateTimeImmutable $jour, int $page = 1): array
    {
        $debut = $jour->setTime(0, 0);

        $qb = $this->createQueryBuilder('v')
            ->join('v.sessionCaisse', 's')->addSelect('s')
            ->join('s.utilisateur', 'u')->addSelect('u')
            ->andWhere('v.createdAt >= :debut')->setParameter('debut', $debut)
            ->andWhere('v.createdAt < :fin')->setParameter('fin', $debut->modify('+1 day'))
            ->orderBy('v.createdAt', 'DESC')
            ->addOrderBy('v.id', 'DESC');

        $page = max(1, $page);
        $qb->setFirstResult(($page - 1) * self::PAR_PAGE)->setMaxResults(self::PAR_PAGE);

        $paginateur = new Paginator($qb->getQuery(), fetchJoinCollection: false);
        $total = \count($paginateur);

        return [
            'ventes' => iterator_to_array($paginateur),
            'total' => $total,
            'pages' => max(1, (int) ceil($total / self::PAR_PAGE)),
            'page' => $page,
        ];
    }
}
