<?php

namespace App\Repository;

use App\Entity\Arrete;
use App\Entity\BonRetour;
use App\Entity\Emplacement;
use App\Enum\StatutBonRetour;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BonRetour>
 */
class BonRetourRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BonRetour::class);
    }

    public function prochainNumero(\DateTimeImmutable $date): string
    {
        $prefixe = 'RET-'.$date->format('Ymd').'-';

        $dernier = $this->createQueryBuilder('b')
            ->select('MAX(b.numero)')
            ->andWhere('b.numero LIKE :prefixe')->setParameter('prefixe', $prefixe.'%')
            ->getQuery()
            ->getSingleScalarResult();

        $rang = null !== $dernier ? (int) substr((string) $dernier, -3) + 1 : 1;
        if ($rang > 999) {
            throw new \DomainException('Plus de 999 bons de retour dans la journée : numérotation épuisée.');
        }

        return $prefixe.\sprintf('%03d', $rang);
    }

    /**
     * Retours **validés**, datés dans la période et pas encore arrêtés — ceux qu'un
     * arrêté embarque.
     *
     * @return list<BonRetour>
     */
    public function aEmbarquer(Emplacement $stand, \DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        return $this->createQueryBuilder('b')
            ->leftJoin('b.lignes', 'l')->addSelect('l')
            ->leftJoin('l.produit', 'p')->addSelect('p')
            ->andWhere('b.stand = :stand')->setParameter('stand', $stand)
            ->andWhere('b.statut = :valide')->setParameter('valide', StatutBonRetour::VALIDE)
            ->andWhere('b.arrete IS NULL')
            ->andWhere('b.dateRetour BETWEEN :debut AND :fin')
            ->setParameter('debut', $debut->format('Y-m-d'))->setParameter('fin', $fin->format('Y-m-d'))
            ->orderBy('b.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Le bon de retour en brouillon d'un arrêté brouillon : la saisie du point. */
    public function brouillonDe(Arrete $arrete): ?BonRetour
    {
        return $this->findOneBy(['arrete' => $arrete, 'statut' => StatutBonRetour::BROUILLON]);
    }

    /** @return list<BonRetour> */
    public function validesDe(Arrete $arrete): array
    {
        return $this->createQueryBuilder('b')
            ->leftJoin('b.lignes', 'l')->addSelect('l')
            ->leftJoin('l.produit', 'p')->addSelect('p')
            ->andWhere('b.arrete = :arrete')->setParameter('arrete', $arrete)
            ->andWhere('b.statut = :valide')->setParameter('valide', StatutBonRetour::VALIDE)
            ->orderBy('b.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
