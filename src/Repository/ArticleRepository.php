<?php

namespace App\Repository;

use App\Entity\Article;
use App\Entity\FamilleProduit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Article>
 */
class ArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Article::class);
    }

    /**
     * Nombre d'articles actifs, compté en base.
     *
     * Le tableau de bord n'affiche que ce total : hydrater les entités pour les
     * compter ensuite en PHP faisait travailler Doctrine pour rien.
     */
    public function compterActifs(): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.actif = true')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Tous les noms du catalogue, actifs ou non.
     *
     * Sert à l'import en masse ({@see \App\Service\ImportArticles}) pour écarter
     * les doublons : **un seul aller en base**, là où interroger nom par nom ferait
     * une requête par ligne du fichier. Les noms seuls, pas les entités — un
     * catalogue entier hydraté pour n'en lire qu'une colonne serait un contresens.
     *
     * Les articles **inactifs comptent aussi** : un article importé deux fois, puis
     * désactivé, ne doit pas revenir en double au prochain fichier.
     *
     * @return list<string>
     */
    public function tousLesNoms(): array
    {
        return array_column(
            $this->createQueryBuilder('a')->select('a.nom')->getQuery()->getScalarResult(),
            'nom',
        );
    }

    /**
     * Recherche filtrée des articles (famille, texte, statut d'activation).
     *
     * @return Pagination<Article>
     */
    public function rechercher(?FamilleProduit $famille, ?string $recherche, ?bool $actif, int $page = 1): Pagination
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.familleProduit', 'f')
            ->addSelect('f')
            // Chargement de la fiche technique pour le calcul de coût/marge (évite le N+1).
            ->leftJoin('a.ficheTechnique', 'ft')
            ->addSelect('ft')
            ->leftJoin('ft.lignes', 'ftl')
            ->addSelect('ftl')
            ->leftJoin('ftl.matierePremiere', 'mp')
            ->addSelect('mp')
            ->orderBy('f.position', 'ASC')
            ->addOrderBy('a.positionCaisse', 'ASC');

        if (null !== $famille) {
            $qb->andWhere('a.familleProduit = :famille')->setParameter('famille', $famille);
        }

        // Même mécanisme que les autres tableaux du back-office — c'est lui qui
        // porte l'échappement des jokers SQL et le groupement des conditions.
        Recherche::appliquer($qb, $recherche, 'a.nom');

        if (null !== $actif) {
            $qb->andWhere('a.actif = :actif')->setParameter('actif', $actif);
        }

        // `fetchJoinCollection` : la requête joint `ft.lignes`, une collection.
        // Sans lui, Doctrine compterait les lignes du produit cartésien — un
        // article à cinq matières compterait pour cinq — et découperait la page
        // au milieu d'une fiche technique.
        return Pagination::depuis($qb, $page, fetchJoinCollection: true);
    }
}
