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
     * Sessions paginées, avec leur caissier — la liste des clôtures affiche son
     * nom sur chaque ligne, à charger donc en une seule requête.
     *
     * @return Pagination<SessionCaisse>
     */
    public function paginees(int $page = 1, ?string $recherche = null): Pagination
    {
        $qb = $this->createQueryBuilder('s')
            ->join('s.utilisateur', 'u')->addSelect('u')
            ->orderBy('s.ouvertureAt', 'DESC');

        // On revient sur une clôture pour une caissière donnée : c'est par son nom
        // qu'on la cherche, jamais par un identifiant de session.
        Recherche::appliquer($qb, $recherche, 'u.nom');

        return Pagination::depuis($qb, $page);
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
