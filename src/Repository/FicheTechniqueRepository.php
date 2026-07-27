<?php

namespace App\Repository;

use App\Entity\FicheTechnique;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FicheTechnique>
 */
class FicheTechniqueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FicheTechnique::class);
    }

    /**
     * Toutes les fiches avec leur article, leurs lignes et les matières associées.
     *
     * L'écran Production parcourt chaque ligne pour cumuler le coût matière : sans
     * cette jointure, Doctrine émettait une requête par fiche puis une par matière.
     *
     * @return Pagination<FicheTechnique>
     */
    public function avecMatieres(int $page = 1, ?string $recherche = null): Pagination
    {
        $qb = $this->createQueryBuilder('ft')
            ->join('ft.article', 'a')->addSelect('a')
            ->leftJoin('ft.lignes', 'l')->addSelect('l')
            ->leftJoin('l.matierePremiere', 'mp')->addSelect('mp')
            ->orderBy('a.nom', 'ASC');

        // Chercher par matière autant que par produit : « quelles fiches
        // utilisent du beurre ? » est la question qu'on se pose avant un
        // changement de prix fournisseur.
        //
        // ⚠ `mp.nom` est joint par une **collection** (`ft.lignes`) : c'est
        // précisément pourquoi le comptage ci-dessous exige `fetchJoinCollection`.
        // Sans lui, une fiche à trois matières correspondantes compterait trois
        // fois dans le total des résultats.
        Recherche::appliquer($qb, $recherche, 'a.nom', 'mp.nom');

        // `ft.lignes` est une collection : sans `fetchJoinCollection`, une fiche à
        // cinq matières compterait pour cinq lignes et la page serait tronquée au
        // milieu d'une fiche.
        return Pagination::depuis($qb, $page, fetchJoinCollection: true);
    }
}
