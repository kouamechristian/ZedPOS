<?php

namespace App\Repository;

use App\Entity\MouvementCaisse;
use App\Entity\SessionCaisse;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MouvementCaisse>
 */
class MouvementCaisseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MouvementCaisse::class);
    }

    /**
     * Mouvements d'une session, du plus récent au plus ancien.
     *
     * @return list<MouvementCaisse>
     */
    public function pourSession(SessionCaisse $session): array
    {
        return $this->findBy(['sessionCaisse' => $session], ['createdAt' => 'DESC']);
    }
}
