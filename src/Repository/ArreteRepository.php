<?php

namespace App\Repository;

use App\Entity\Arrete;
use App\Entity\Emplacement;
use App\Entity\Vendeur;
use App\Enum\StatutArrete;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Arrete>
 */
class ArreteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Arrete::class);
    }

    /**
     * Le dernier arrêté **validé** du stand : celui dont la fin est la plus tardive.
     * Son lendemain est le début obligé du suivant ; lui seul peut être annulé.
     */
    public function dernierValide(Emplacement $stand): ?Arrete
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.stand = :stand')->setParameter('stand', $stand)
            ->andWhere('a.statut = :valide')->setParameter('valide', StatutArrete::VALIDE)
            ->orderBy('a.dateFin', 'DESC')->addOrderBy('a.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Le point en cours de saisie sur ce stand, s'il y en a un : il n'y en a jamais deux. */
    public function brouillonDe(Emplacement $stand): ?Arrete
    {
        return $this->findOneBy(['stand' => $stand, 'statut' => StatutArrete::BROUILLON], ['id' => 'DESC']);
    }

    public function prochainNumero(\DateTimeImmutable $date): string
    {
        $prefixe = 'PTS-'.$date->format('Ymd').'-';

        $dernier = $this->createQueryBuilder('a')
            ->select('MAX(a.numero)')
            ->andWhere('a.numero LIKE :prefixe')->setParameter('prefixe', $prefixe.'%')
            ->getQuery()
            ->getSingleScalarResult();

        $rang = null !== $dernier ? (int) substr((string) $dernier, -3) + 1 : 1;
        if ($rang > 999) {
            throw new \DomainException('Plus de 999 arrêtés dans la journée : numérotation épuisée.');
        }

        return $prefixe.\sprintf('%03d', $rang);
    }

    /** @return Pagination<Arrete> */
    public function pagines(int $page = 1, ?string $recherche = null): Pagination
    {
        $qb = $this->createQueryBuilder('a')
            ->join('a.stand', 's')->addSelect('s')
            ->join('a.vendeur', 'v')->addSelect('v')
            ->orderBy('a.dateFin', 'DESC')->addOrderBy('a.id', 'DESC');

        Recherche::appliquer($qb, $recherche, 'a.numero', 's.libelle', 'v.nom');

        return Pagination::depuis($qb, $page);
    }

    /**
     * Les points validés du vendeur, les plus récents d'abord — l'historique de ses
     * écarts sur sa fiche.
     *
     * @return Pagination<Arrete>
     */
    public function valideesDuVendeur(Vendeur $vendeur, int $page = 1): Pagination
    {
        $qb = $this->createQueryBuilder('a')
            ->join('a.stand', 's')->addSelect('s')
            ->andWhere('a.vendeur = :vendeur')->setParameter('vendeur', $vendeur)
            ->andWhere('a.statut = :valide')->setParameter('valide', StatutArrete::VALIDE)
            ->orderBy('a.dateFin', 'DESC')->addOrderBy('a.id', 'DESC');

        return Pagination::depuis($qb, $page);
    }

    /**
     * Cumul des écarts des points validés du vendeur, en centimes : manquants et
     * trop-perçus séparés, un trop-perçu ne rembourse pas un manquant.
     *
     * @return array{manquants: int, tropPercus: int, points: int}
     */
    public function cumulEcartsDuVendeur(Vendeur $vendeur): array
    {
        $ligne = $this->createQueryBuilder('a')
            ->select(
                'COALESCE(SUM(CASE WHEN a.ecart < 0 THEN a.ecart ELSE 0 END), 0) AS manquants',
                'COALESCE(SUM(CASE WHEN a.ecart > 0 THEN a.ecart ELSE 0 END), 0) AS tropPercus',
                'COUNT(a.id) AS points',
            )
            ->andWhere('a.vendeur = :vendeur')->setParameter('vendeur', $vendeur)
            ->andWhere('a.statut = :valide')->setParameter('valide', StatutArrete::VALIDE)
            ->getQuery()
            ->getSingleResult();

        return ['manquants' => (int) $ligne['manquants'], 'tropPercus' => (int) $ligne['tropPercus'], 'points' => (int) $ligne['points']];
    }

    /**
     * Points validés dont une ligne de dotation n'a pas encore sa part — ceux validés
     * avant les rapports des stands.
     *
     * @return list<Arrete>
     */
    public function validesSansVentesFigees(): array
    {
        return $this->createQueryBuilder('a')
            ->distinct()
            ->join('a.bonsDotation', 'b')
            ->join('b.lignes', 'l')
            ->andWhere('a.statut = :valide')->setParameter('valide', StatutArrete::VALIDE)
            ->andWhere('l.qteVendue IS NULL')
            ->orderBy('a.dateFin', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function avecDetails(int $id): ?Arrete
    {
        return $this->createQueryBuilder('a')
            ->join('a.stand', 's')->addSelect('s')
            ->join('a.vendeur', 'v')->addSelect('v')
            ->andWhere('a.id = :id')->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
