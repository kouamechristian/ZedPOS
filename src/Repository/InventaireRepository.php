<?php

namespace App\Repository;

use App\Entity\Inventaire;
use App\Enum\StatutInventaire;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Inventaire>
 */
class InventaireRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Inventaire::class);
    }

    /**
     * Inventaires paginés, du plus récent au plus ancien, avec leurs auteurs — la
     * liste les nomme sur chaque ligne, à charger donc en une seule requête.
     *
     * @return Pagination<Inventaire>
     */
    public function paginees(int $page = 1, ?string $recherche = null): Pagination
    {
        $qb = $this->createQueryBuilder('i')
            ->join('i.auteur', 'a')->addSelect('a')
            ->leftJoin('i.validePar', 'v')->addSelect('v')
            ->orderBy('i.createdAt', 'DESC')
            ->addOrderBy('i.id', 'DESC');

        // On retrouve un inventaire par qui l'a fait ou par son commentaire —
        // « après la coupure de courant » se relit un mois plus tard.
        Recherche::appliquer($qb, $recherche, 'a.nom', 'v.nom', 'i.commentaire');

        return Pagination::depuis($qb, $page);
    }

    /**
     * Inventaire en cours, s'il y en a un.
     *
     * **Un seul à la fois** : deux feuilles ouvertes en parallèle figeraient le
     * même théorique et la seconde validation écraserait la première.
     */
    public function enCours(): ?Inventaire
    {
        return $this->findOneBy(['statut' => StatutInventaire::EN_COURS], ['id' => 'DESC']);
    }

    /**
     * Feuille complète, lignes et relations chargées d'avance : l'écran de
     * comptage parcourt chaque ligne, sans quoi une feuille de 60 articles
     * déclencherait autant de requêtes.
     */
    public function avecLignes(int $id): ?Inventaire
    {
        return $this->createQueryBuilder('i')
            ->leftJoin('i.lignes', 'l')->addSelect('l')
            ->leftJoin('l.matierePremiere', 'm')->addSelect('m')
            ->leftJoin('l.article', 'ar')->addSelect('ar')
            ->join('i.auteur', 'a')->addSelect('a')
            ->leftJoin('i.validePar', 'v')->addSelect('v')
            ->andWhere('i.id = :id')->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
