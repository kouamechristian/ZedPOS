<?php

namespace App\Repository\Magasin;

use App\Entity\Magasin\MagasinProduit;
use App\Enum\Magasin\CategorieProduitMagasin;
use App\Repository\Pagination;
use App\Repository\Recherche;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MagasinProduit>
 */
class MagasinProduitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MagasinProduit::class);
    }

    /**
     * Produits paginés par nom, fournisseur habituel compris. Cherché sur le nom et
     * les unités ; filtrable par catégorie.
     *
     * @return Pagination<MagasinProduit>
     */
    public function pagines(int $page = 1, ?string $recherche = null, ?CategorieProduitMagasin $categorie = null): Pagination
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.fournisseurHabituel', 'f')->addSelect('f')
            ->orderBy('p.nom', 'ASC');

        if (null !== $categorie) {
            $qb->andWhere('p.categorie = :categorie')->setParameter('categorie', $categorie);
        }
        Recherche::appliquer($qb, $recherche, 'p.nom', 'p.uniteStock', 'p.uniteAchat');

        return Pagination::depuis($qb, $page);
    }

    /** @return list<MagasinProduit> les produits actifs, par nom — pour les listes de saisie */
    public function actifs(): array
    {
        return $this->findBy(['actif' => true], ['nom' => 'ASC']);
    }
}
