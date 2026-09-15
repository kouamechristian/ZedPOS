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
     * La vente, le ticket qu'elle remplace et le caissier sont chargés dans la
     * même requête : l'écran les affiche sur chaque alerte, vingt alertes
     * feraient sinon soixante requêtes.
     *
     * @return list<Notification>
     */
    public function nonLuesPour(string $role, int $limite = 20): array
    {
        return $this->createQueryBuilder('n')
            ->leftJoin('n.vente', 'v')->addSelect('v')
            ->leftJoin('v.venteRemplacee', 'o')->addSelect('o')
            ->leftJoin('v.sessionCaisse', 's')->addSelect('s')
            ->leftJoin('s.utilisateur', 'u')->addSelect('u')
            ->andWhere('n.roleDestinataire = :role')->setParameter('role', $role)
            ->andWhere('n.luA IS NULL')
            ->orderBy('n.createdAt', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /**
     * Acquitte d'un geste toutes les alertes d'un rôle.
     *
     * @return int nombre de notifications acquittées
     */
    public function marquerToutesLuesPour(string $role): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->update(Notification::class, 'n')
            ->set('n.luA', ':maintenant')->setParameter('maintenant', new \DateTimeImmutable())
            ->andWhere('n.roleDestinataire = :role')->setParameter('role', $role)
            ->andWhere('n.luA IS NULL')
            ->getQuery()
            ->execute();
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
