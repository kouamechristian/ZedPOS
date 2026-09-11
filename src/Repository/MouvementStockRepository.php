<?php

namespace App\Repository;

use App\Entity\MatierePremiere;
use App\Entity\MouvementStock;
use App\Enum\TypeMouvementStock;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MouvementStock>
 */
class MouvementStockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MouvementStock::class);
    }

    /**
     * Sorties de stock d'une vente caisse, à inverser lors de son annulation.
     *
     * Les **négatives** seulement : l'annulation écrit elle aussi des mouvements
     * VENTE_CAISSE rattachés à la vente, positifs, et les reprendre les inverserait
     * à leur tour.
     *
     * @return list<MouvementStock>
     */
    public function sortiesDeVente(int $venteId): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.documentType = :document')
            ->andWhere('m.documentId = :id')
            ->andWhere('m.type = :type')
            ->andWhere('m.quantite < 0')
            ->setParameter('document', 'vente')
            ->setParameter('id', $venteId)
            ->setParameter('type', TypeMouvementStock::VENTE_CAISSE)
            ->orderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Mouvements d'un document, dans l'ordre d'écriture.
     *
     * @return list<MouvementStock>
     */
    public function duDocument(string $documentType, int $documentId): array
    {
        return $this->findBy(['documentType' => $documentType, 'documentId' => $documentId], ['id' => 'ASC']);
    }

    public function existePourMatiere(MatierePremiere $matiere): bool
    {
        return null !== $this->findOneBy(['matierePremiere' => $matiere]);
    }
}
