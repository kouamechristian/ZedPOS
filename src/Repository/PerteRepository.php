<?php

namespace App\Repository;

use App\Entity\Perte;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Perte>
 */
class PerteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Perte::class);
    }

    /**
     * Pertes d'une période, de la plus récente à la plus ancienne.
     *
     * Matière et article sont joints d'avance : le détail affiche le libellé de
     * l'un ou de l'autre sur chaque ligne.
     *
     * @return Pagination<Perte>
     */
    public function surPeriode(
        \DateTimeImmutable $debut,
        \DateTimeImmutable $fin,
        int $page = 1,
        ?string $recherche = null,
    ): Pagination {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.matierePremiere', 'm')->addSelect('m')
            ->leftJoin('p.article', 'a')->addSelect('a')
            ->andWhere('p.createdAt >= :debut')->setParameter('debut', $debut)
            ->andWhere('p.createdAt < :fin')->setParameter('fin', $fin)
            ->orderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'DESC');

        // Une perte porte sur une matière **ou** sur un article, jamais les deux :
        // les deux libellés sont donc cherchés ensemble.
        //
        // Le commentaire saisi à la déclaration n'est pas cherchable : `Perte` ne
        // le stocke pas — `PerteService` le concatène au motif du `MouvementStock`
        // (« Casse — panne du frigo »). Il faudrait un champ sur l'entité pour
        // pouvoir y revenir un mois plus tard.
        Recherche::appliquer($qb, $recherche, 'm.nom', 'a.nom');

        return Pagination::depuis($qb, $page);
    }
}
