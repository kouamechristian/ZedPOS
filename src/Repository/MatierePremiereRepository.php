<?php

namespace App\Repository;

use App\Entity\MatierePremiere;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MatierePremiere>
 */
class MatierePremiereRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MatierePremiere::class);
    }

    /**
     * @return Pagination<MatierePremiere>
     */
    public function paginees(int $page = 1, ?string $recherche = null): Pagination
    {
        // Le fournisseur est joint pour la recherche **et** pour l'affichage : la
        // colonne le nomme sur chaque ligne, sans jointure c'était une requête
        // supplémentaire par matière.
        $qb = $this->createQueryBuilder('m')
            ->leftJoin('m.fournisseur', 'f')->addSelect('f')
            ->orderBy('m.nom', 'ASC');

        // « Farine » comme « Moulin du Sud » : on cherche une matière autant par
        // ce qu'elle est que par qui la livre.
        Recherche::appliquer($qb, $recherche, 'm.nom', 'm.uniteStock', 'f.nom');

        return Pagination::depuis($qb, $page);
    }
}
