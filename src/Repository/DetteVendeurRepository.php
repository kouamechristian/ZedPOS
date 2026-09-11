<?php

namespace App\Repository;

use App\Entity\Arrete;
use App\Entity\DetteVendeur;
use App\Entity\Vendeur;
use App\Enum\StatutDette;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DetteVendeur>
 */
class DetteVendeurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DetteVendeur::class);
    }

    /**
     * Dettes encore dues, **les plus anciennes d'abord** : c'est l'ordre dans lequel
     * un versement les rembourse.
     *
     * `$verrou` : `SELECT … FOR UPDATE`, à n'employer que dans une transaction — deux
     * encaissements simultanés ne rembourseraient pas deux fois le même reste.
     *
     * @return list<DetteVendeur>
     */
    public function duesDe(Vendeur $vendeur, bool $verrou = false): array
    {
        $requete = $this->createQueryBuilder('d')
            ->andWhere('d.vendeur = :vendeur')->setParameter('vendeur', $vendeur)
            ->andWhere('d.statut IN (:dues)')->setParameter('dues', self::dues())
            ->orderBy('d.createdAt', 'ASC')->addOrderBy('d.id', 'ASC')
            ->getQuery();

        if ($verrou) {
            $requete->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }

        return $requete->getResult();
    }

    /** Solde dû par le vendeur, en centimes. */
    public function soldeDe(Vendeur $vendeur): int
    {
        return $this->soldes([$vendeur])[(int) $vendeur->getId()] ?? 0;
    }

    /**
     * Soldes dus, en **une requête**, indexés par id de vendeur. Un vendeur sans
     * dette due est absent du tableau.
     *
     * @param list<Vendeur>|null $vendeurs null : tous
     *
     * @return array<int, int>
     */
    public function soldes(?array $vendeurs = null): array
    {
        if ([] === $vendeurs) {
            return [];
        }

        $qb = $this->createQueryBuilder('d')
            ->select('IDENTITY(d.vendeur) AS vendeur', 'SUM(d.montant - d.montantRembourse) AS solde')
            ->andWhere('d.statut IN (:dues)')->setParameter('dues', self::dues())
            ->groupBy('d.vendeur');

        if (null !== $vendeurs) {
            $qb->andWhere('d.vendeur IN (:vendeurs)')->setParameter('vendeurs', $vendeurs);
        }

        $soldes = [];
        foreach ($qb->getQuery()->getArrayResult() as $ligne) {
            $soldes[(int) $ligne['vendeur']] = (int) $ligne['solde'];
        }

        return $soldes;
    }

    /** @return list<string> */
    private static function dues(): array
    {
        return array_map(static fn (StatutDette $s): string => $s->value, StatutDette::dues());
    }

    /** La dette ouverte à la validation de ce point, s'il y en a une (annulées comprises). */
    public function deArrete(Arrete $arrete): ?DetteVendeur
    {
        return $this->findOneBy(['arrete' => $arrete], ['id' => 'DESC']);
    }

    /**
     * Toutes les dettes du vendeur : les dues d'abord, puis les plus récentes.
     *
     * @return Pagination<DetteVendeur>
     */
    public function paginesDe(Vendeur $vendeur, int $page = 1): Pagination
    {
        $qb = $this->createQueryBuilder('d')
            ->leftJoin('d.arrete', 'a')->addSelect('a')
            ->addSelect('CASE WHEN d.statut IN (:dues) THEN 0 ELSE 1 END AS HIDDEN rang')
            ->andWhere('d.vendeur = :vendeur')->setParameter('vendeur', $vendeur)
            ->setParameter('dues', self::dues())
            ->orderBy('rang', 'ASC')->addOrderBy('d.createdAt', 'DESC')->addOrderBy('d.id', 'DESC');

        return Pagination::depuis($qb, $page);
    }
}
