<?php

namespace App\Repository;

use App\Entity\SaisieProduction;
use App\Entity\SessionCaisse;
use App\Enum\TypeSaisieProduction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SaisieProduction>
 */
class SaisieProductionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SaisieProduction::class);
    }

    /** PRD-AAAAMMJJ-XXX / VIT-AAAAMMJJ-XXX : séquence par type et par jour, comme les dotations. */
    public function prochainNumero(TypeSaisieProduction $type, \DateTimeImmutable $date): string
    {
        $prefixe = $type->prefixe().'-'.$date->format('Ymd').'-';

        $dernier = $this->createQueryBuilder('s')
            ->select('MAX(s.numero)')
            ->andWhere('s.numero LIKE :prefixe')->setParameter('prefixe', $prefixe.'%')
            ->getQuery()
            ->getSingleScalarResult();

        $rang = null !== $dernier ? (int) substr((string) $dernier, -3) + 1 : 1;
        if ($rang > 999) {
            throw new \DomainException('Plus de 999 déclarations dans la journée : numérotation épuisée.');
        }

        return $prefixe.\sprintf('%03d', $rang);
    }

    /**
     * Toutes les déclarations d'une caisse, annulées comprises, la plus récente
     * d'abord — lignes, articles et auteurs en une requête.
     *
     * @return list<SaisieProduction>
     */
    public function deLaSession(SessionCaisse $session): array
    {
        return $this->createQueryBuilder('s')
            ->join('s.createdBy', 'u')->addSelect('u')
            ->leftJoin('s.lignes', 'l')->addSelect('l')
            ->leftJoin('l.article', 'a')->addSelect('a')
            ->andWhere('s.sessionCaisse = :session')->setParameter('session', $session)
            ->orderBy('s.createdAt', 'DESC')->addOrderBy('s.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function avecLignes(int $id): ?SaisieProduction
    {
        return $this->createQueryBuilder('s')
            ->join('s.sessionCaisse', 'c')->addSelect('c')
            ->leftJoin('s.lignes', 'l')->addSelect('l')
            ->leftJoin('l.article', 'a')->addSelect('a')
            ->andWhere('s.id = :id')->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
