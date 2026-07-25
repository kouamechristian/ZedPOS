<?php

namespace App\Repository;

use App\Entity\Notification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * Notifications non lues d'un rôle, de la plus récente à la plus ancienne.
     *
     * @return list<Notification>
     */
    public function nonLuesPour(string $role, int $limite = 20): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.roleDestinataire = :role')->setParameter('role', $role)
            ->andWhere('n.luA IS NULL')
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    public function nombreNonLues(string $role): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.roleDestinataire = :role')->setParameter('role', $role)
            ->andWhere('n.luA IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
