<?php

namespace App\Repository\Magasin;

use App\Entity\Magasin\MagasinReception;
use App\Enum\Magasin\StatutReceptionMagasin;
use App\Repository\Pagination;
use App\Repository\Recherche;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MagasinReception>
 */
class MagasinReceptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MagasinReception::class);
    }

    /**
     * REC-2026-0001 : séquence par année de réception, remise à zéro chaque année.
     * Lue sous verrou par l'appelant (transaction) : deux réceptions simultanées
     * ne prennent pas le même numéro — l'index unique trancherait sinon.
     */
    public function prochainNumero(\DateTimeImmutable $date): string
    {
        $prefixe = 'REC-'.$date->format('Y').'-';

        $dernier = $this->createQueryBuilder('r')
            ->select('MAX(r.numero)')
            ->andWhere('r.numero LIKE :prefixe')->setParameter('prefixe', $prefixe.'%')
            ->getQuery()
            ->getSingleScalarResult();

        $rang = null !== $dernier ? (int) substr((string) $dernier, -4) + 1 : 1;
        if ($rang > 9999) {
            throw new \DomainException('Plus de 9 999 réceptions dans l\'année : numérotation épuisée.');
        }

        return $prefixe.\sprintf('%04d', $rang);
    }

    /**
     * Réceptions paginées, la plus récente d'abord, filtrables par statut. Cherché
     * sur le numéro, le fournisseur et le bon de livraison.
     *
     * @return Pagination<MagasinReception>
     */
    public function pagines(int $page = 1, ?string $recherche = null, ?StatutReceptionMagasin $statut = null): Pagination
    {
        $qb = $this->createQueryBuilder('r')
            ->join('r.fournisseur', 'f')->addSelect('f')
            ->orderBy('r.dateReception', 'DESC')->addOrderBy('r.id', 'DESC');

        if (null !== $statut) {
            $qb->andWhere('r.statut = :statut')->setParameter('statut', $statut);
        }
        Recherche::appliquer($qb, $recherche, 'r.numero', 'f.nom', 'r.bonLivraison');

        return Pagination::depuis($qb, $page);
    }

    /** La réception, ses lignes, produits et emplacements en une requête. */
    public function avecLignes(int $id): ?MagasinReception
    {
        return $this->createQueryBuilder('r')
            ->join('r.fournisseur', 'f')->addSelect('f')
            ->leftJoin('r.lignes', 'l')->addSelect('l')
            ->leftJoin('l.produit', 'p')->addSelect('p')
            ->leftJoin('l.emplacement', 'e')->addSelect('e')
            ->andWhere('r.id = :id')->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Nombre de réceptions par statut, en une requête — les onglets de la liste et
     * les cartes du tableau de bord.
     *
     * @return array<string, int> par valeur de statut
     */
    public function compteParStatut(): array
    {
        $comptes = array_fill_keys(array_map(static fn (StatutReceptionMagasin $s): string => $s->value, StatutReceptionMagasin::cases()), 0);
        foreach ($this->getEntityManager()->getConnection()->fetchAllKeyValue(
            'SELECT statut, COUNT(*) FROM magasin_reception GROUP BY statut',
        ) as $statut => $nombre) {
            $comptes[(string) $statut] = (int) $nombre;
        }

        return $comptes;
    }
}
