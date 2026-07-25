<?php

namespace App\Repository;

use App\Entity\SessionCaisse;
use App\Entity\Utilisateur;
use App\Enum\StatutSessionCaisse;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SessionCaisse>
 */
class SessionCaisseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SessionCaisse::class);
    }

    /**
     * Session ouverte d'un caissier — il ne peut y en avoir qu'une à la fois.
     */
    public function ouvertePour(Utilisateur $utilisateur): ?SessionCaisse
    {
        return $this->findOneBy(
            ['utilisateur' => $utilisateur, 'statut' => StatutSessionCaisse::OUVERTE],
            ['ouvertureAt' => 'DESC'],
        );
    }
}
