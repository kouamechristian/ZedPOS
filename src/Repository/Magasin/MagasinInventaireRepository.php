<?php

namespace App\Repository\Magasin;

use App\Entity\Magasin\MagasinEmplacement;
use App\Entity\Magasin\MagasinInventaire;
use App\Enum\Magasin\StatutInventaireMagasin;
use App\Repository\Pagination;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MagasinInventaire>
 */
class MagasinInventaireRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MagasinInventaire::class);
    }

    /** INV-2026-0001 : séquence par année d'ouverture, lue sous verrou par l'appelant. */
    public function prochainNumero(\DateTimeImmutable $date): string
    {
        $prefixe = 'INV-'.$date->format('Y').'-';
        $dernier = $this->createQueryBuilder('i')
            ->select('MAX(i.numero)')
            ->andWhere('i.numero LIKE :prefixe')->setParameter('prefixe', $prefixe.'%')
            ->getQuery()
            ->getSingleScalarResult();

        $rang = null !== $dernier ? (int) substr((string) $dernier, -4) + 1 : 1;
        if ($rang > 9999) {
            throw new \DomainException('Plus de 9 999 inventaires dans l\'année : numérotation épuisée.');
        }

        return $prefixe.\sprintf('%04d', $rang);
    }

    /** @return Pagination<MagasinInventaire> le plus récent d'abord */
    public function pagines(int $page = 1): Pagination
    {
        $qb = $this->createQueryBuilder('i')
            ->join('i.ouvertPar', 'o')->addSelect('o')
            ->leftJoin('i.emplacement', 'e')->addSelect('e')
            ->leftJoin('i.validePar', 'v')->addSelect('v')
            ->orderBy('i.createdAt', 'DESC')->addOrderBy('i.id', 'DESC');

        return Pagination::depuis($qb, $page);
    }

    /** La feuille, ses lignes, produits et emplacements, rangées par emplacement puis produit. */
    public function avecLignes(int $id): ?MagasinInventaire
    {
        return $this->createQueryBuilder('i')
            ->leftJoin('i.lignes', 'l')->addSelect('l')
            ->leftJoin('l.produit', 'p')->addSelect('p')
            ->leftJoin('l.emplacement', 'e')->addSelect('e')
            ->andWhere('i.id = :id')->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<MagasinInventaire> les feuilles en cours */
    public function enCours(): array
    {
        return $this->createQueryBuilder('i')
            ->leftJoin('i.emplacement', 'e')->addSelect('e')
            ->andWhere('i.statut = :statut')->setParameter('statut', StatutInventaireMagasin::EN_COURS)
            ->orderBy('i.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * La feuille en cours qui empêcherait d'en ouvrir une sur cette portée : une
     * feuille de tout le magasin couvre chaque emplacement, et une feuille de tout
     * le magasin ne s'ouvre pas tant qu'un emplacement est en cours de comptage.
     * Deux feuilles sur les mêmes lignes figeraient le même théorique, et la
     * seconde validation reporterait le même écart une seconde fois.
     */
    public function conflit(?MagasinEmplacement $emplacement): ?MagasinInventaire
    {
        foreach ($this->enCours() as $inventaire) {
            if (null === $emplacement || null === $inventaire->getEmplacement() || $inventaire->getEmplacement()->getId() === $emplacement->getId()) {
                return $inventaire;
            }
        }

        return null;
    }

    public function dernierValide(): ?MagasinInventaire
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.statut = :statut')->setParameter('statut', StatutInventaireMagasin::VALIDE)
            ->orderBy('i.valideAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
