<?php

namespace App\Repository;

use App\Entity\BonDotation;
use App\Entity\Emplacement;
use App\Enum\StatutBonDotation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BonDotation>
 */
class BonDotationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BonDotation::class);
    }

    /**
     * Prochain numéro du jour : DOT-AAAAMMJJ-XXX.
     *
     * Compté sur les numéros existants, annulés compris — un bon ne se supprime
     * jamais, la séquence ne revient donc pas en arrière. Trois chiffres : au-delà
     * de 999 bons dans une journée, c'est une erreur, pas un réapprovisionnement.
     */
    public function prochainNumero(\DateTimeImmutable $date): string
    {
        $prefixe = 'DOT-'.$date->format('Ymd').'-';

        $dernier = $this->createQueryBuilder('b')
            ->select('MAX(b.numero)')
            ->andWhere('b.numero LIKE :prefixe')->setParameter('prefixe', $prefixe.'%')
            ->getQuery()
            ->getSingleScalarResult();

        $rang = null !== $dernier ? (int) substr((string) $dernier, -3) + 1 : 1;
        if ($rang > 999) {
            throw new \DomainException('Plus de 999 bons de dotation dans la journée : numérotation épuisée.');
        }

        return $prefixe.\sprintf('%03d', $rang);
    }

    /**
     * Bons paginés, du plus récent au plus ancien, avec stand et vendeur.
     *
     * @return Pagination<BonDotation>
     */
    public function pagines(int $page = 1, ?string $recherche = null): Pagination
    {
        $qb = $this->createQueryBuilder('b')
            ->join('b.stand', 's')->addSelect('s')
            ->join('b.vendeur', 'v')->addSelect('v')
            ->orderBy('b.dateDotation', 'DESC')->addOrderBy('b.id', 'DESC');

        Recherche::appliquer($qb, $recherche, 'b.numero', 's.libelle', 'v.nom');

        return Pagination::depuis($qb, $page);
    }

    /**
     * Dernière dotation **validée** d'un stand, lignes et produits compris — c'est
     * elle que « reprendre la dotation d'hier » recopie. Sur l'identifiant et non
     * la date : deux bons du même jour se départagent par l'ordre de saisie.
     */
    public function derniereValidee(Emplacement $stand): ?BonDotation
    {
        // L'identifiant d'abord, sans jointure : un setMaxResults() sur une
        // collection jointe couperait les lignes du bon.
        $id = $this->createQueryBuilder('b')
            ->select('b.id')
            ->andWhere('b.stand = :stand')->setParameter('stand', $stand)
            ->andWhere('b.statut = :valide')->setParameter('valide', StatutBonDotation::VALIDE)
            ->orderBy('b.dateDotation', 'DESC')->addOrderBy('b.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return null === $id ? null : $this->avecLignes((int) $id['id']);
    }

    /**
     * Dotations **validées**, datées dans la période et pas encore arrêtées : celles
     * qu'un arrêté embarque. Lignes et produits chargés d'un coup.
     *
     * @return list<BonDotation>
     */
    public function aEmbarquer(Emplacement $stand, \DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        return $this->dansPeriode($stand, $debut, $fin)
            ->andWhere('b.statut = :valide')->setParameter('valide', StatutBonDotation::VALIDE)
            ->andWhere('b.arrete IS NULL')
            ->getQuery()
            ->getResult();
    }

    /**
     * Brouillons datés dans la période : tant qu'il en reste, le point ne se valide
     * pas — la marchandise est peut-être partie sans que rien ne le dise.
     *
     * @return list<BonDotation>
     */
    public function brouillonsDansPeriode(Emplacement $stand, \DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        return $this->dansPeriode($stand, $debut, $fin)
            ->andWhere('b.statut = :brouillon')->setParameter('brouillon', StatutBonDotation::BROUILLON)
            ->getQuery()
            ->getResult();
    }

    /** @return list<BonDotation> */
    public function duArrete(\App\Entity\Arrete $arrete): array
    {
        return $this->createQueryBuilder('b')
            ->leftJoin('b.lignes', 'l')->addSelect('l')
            ->leftJoin('l.produit', 'p')->addSelect('p')
            ->andWhere('b.arrete = :arrete')->setParameter('arrete', $arrete)
            ->orderBy('b.dateDotation', 'ASC')->addOrderBy('b.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Date de la toute première dotation du stand, brouillons compris — un brouillon
     * antérieur à la première validée doit tomber dans la période du premier point,
     * sinon il y échapperait. Les bons annulés ne comptent pas.
     */
    public function premiereDate(Emplacement $stand): ?\DateTimeImmutable
    {
        $date = $this->createQueryBuilder('b')
            ->select('MIN(b.dateDotation)')
            ->andWhere('b.stand = :stand')->setParameter('stand', $stand)
            ->andWhere('b.statut <> :annule')->setParameter('annule', StatutBonDotation::ANNULE)
            ->getQuery()
            ->getSingleScalarResult();

        return null !== $date ? new \DateTimeImmutable((string) $date) : null;
    }

    private function dansPeriode(Emplacement $stand, \DateTimeImmutable $debut, \DateTimeImmutable $fin): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('b')
            ->leftJoin('b.lignes', 'l')->addSelect('l')
            ->leftJoin('l.produit', 'p')->addSelect('p')
            ->andWhere('b.stand = :stand')->setParameter('stand', $stand)
            ->andWhere('b.dateDotation BETWEEN :debut AND :fin')
            ->setParameter('debut', $debut->format('Y-m-d'))->setParameter('fin', $fin->format('Y-m-d'))
            ->orderBy('b.dateDotation', 'ASC')->addOrderBy('b.id', 'ASC');
    }

    public function avecLignes(int $id): ?BonDotation
    {
        return $this->createQueryBuilder('b')
            ->join('b.stand', 's')->addSelect('s')
            ->join('b.vendeur', 'v')->addSelect('v')
            ->leftJoin('b.lignes', 'l')->addSelect('l')
            ->leftJoin('l.produit', 'p')->addSelect('p')
            ->andWhere('b.id = :id')->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
