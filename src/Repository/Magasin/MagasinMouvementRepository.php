<?php

namespace App\Repository\Magasin;

use App\Entity\Magasin\MagasinMouvement;
use App\Entity\Magasin\MagasinProduit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MagasinMouvement>
 */
class MagasinMouvementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MagasinMouvement::class);
    }

    /**
     * Mouvements d'un document, dans l'ordre d'écriture — ce qu'une annulation
     * contre-passe.
     *
     * @return list<MagasinMouvement>
     */
    public function duDocument(string $documentType, int $documentId): array
    {
        return $this->findBy(['documentType' => $documentType, 'documentId' => $documentId], ['id' => 'ASC']);
    }

    public function existePourProduit(MagasinProduit $produit): bool
    {
        return null !== $this->findOneBy(['produit' => $produit]);
    }
}
