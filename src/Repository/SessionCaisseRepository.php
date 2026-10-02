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

    /**
     * Une session ouverte, quel qu'en soit le caissier (la plus ancienne d'abord) :
     * tant qu'elle existe, aucune autre caisse ne s'ouvre.
     */
    public function ouverteQuelconque(): ?SessionCaisse
    {
        return $this->findOneBy(
            ['statut' => StatutSessionCaisse::OUVERTE],
            ['ouvertureAt' => 'ASC'],
        );
    }

    /**
     * La dernière caisse clôturée : celle dont la suivante reprend les restes.
     * Départagée par l'identifiant, deux clôtures pouvant tomber dans la même seconde.
     */
    public function derniereCloturee(): ?SessionCaisse
    {
        return $this->findOneBy(
            ['statut' => StatutSessionCaisse::CLOTUREE],
            ['clotureAt' => 'DESC', 'id' => 'DESC'],
        );
    }

    /** Une caisse a-t-elle été ouverte après celle-ci ? Elle a alors repris ses restes. */
    public function aUneSuivante(SessionCaisse $session): bool
    {
        return null !== $this->createQueryBuilder('s')
            ->select('s.id')
            ->andWhere('s.id > :id')->setParameter('id', $session->getId())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
