<?php

namespace App\Repository\Magasin;

use App\Entity\Magasin\MagasinSortie;
use App\Enum\Magasin\StatutSortieMagasin;
use App\Repository\Pagination;
use App\Repository\Recherche;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MagasinSortie>
 */
class MagasinSortieRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MagasinSortie::class);
    }

    /**
     * SOR-2026-0001 : séquence par année de sortie, remise à zéro chaque année.
     * Lue sous verrou par l'appelant : deux sorties simultanées ne prennent pas le
     * même numéro — l'index unique trancherait sinon.
     */
    public function prochainNumero(\DateTimeImmutable $date): string
    {
        $prefixe = 'SOR-'.$date->format('Y').'-';

        $dernier = $this->createQueryBuilder('s')
            ->select('MAX(s.numero)')
            ->andWhere('s.numero LIKE :prefixe')->setParameter('prefixe', $prefixe.'%')
            ->getQuery()
            ->getSingleScalarResult();

        $rang = null !== $dernier ? (int) substr((string) $dernier, -4) + 1 : 1;
        if ($rang > 9999) {
            throw new \DomainException('Plus de 9 999 sorties dans l\'année : numérotation épuisée.');
        }

        return $prefixe.\sprintf('%04d', $rang);
    }

    /**
     * Sorties paginées, la plus récente d'abord, filtrables par statut. Cherché sur
     * le numéro, le demandeur et le commentaire.
     *
     * @return Pagination<MagasinSortie>
     */
    public function pagines(int $page = 1, ?string $recherche = null, ?StatutSortieMagasin $statut = null): Pagination
    {
        $qb = $this->createQueryBuilder('s')
            ->join('s.creePar', 'c')->addSelect('c')
            ->leftJoin('s.serviePar', 'v')->addSelect('v')
            ->orderBy('s.dateSortie', 'DESC')->addOrderBy('s.id', 'DESC');

        if (null !== $statut) {
            $qb->andWhere('s.statut = :statut')->setParameter('statut', $statut);
        }
        Recherche::appliquer($qb, $recherche, 's.numero', 's.demandePar', 's.commentaire');

        return Pagination::depuis($qb, $page);
    }

    /** La sortie, ses lignes, produits et emplacements en une requête. */
    public function avecLignes(int $id): ?MagasinSortie
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.lignes', 'l')->addSelect('l')
            ->leftJoin('l.produit', 'p')->addSelect('p')
            ->leftJoin('l.emplacement', 'e')->addSelect('e')
            ->andWhere('s.id = :id')->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Nombre de sorties par statut, en une requête.
     *
     * @return array<string, int> par valeur de statut
     */
    public function compteParStatut(): array
    {
        $comptes = array_fill_keys(array_map(static fn (StatutSortieMagasin $s): string => $s->value, StatutSortieMagasin::cases()), 0);
        foreach ($this->getEntityManager()->getConnection()->fetchAllKeyValue(
            'SELECT statut, COUNT(*) FROM magasin_sortie GROUP BY statut',
        ) as $statut => $nombre) {
            $comptes[(string) $statut] = (int) $nombre;
        }

        return $comptes;
    }
}
