<?php

namespace App\Repository;

use App\Entity\StockCourant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Lecture seule. Pour la quantité à jour au sein d'une opération, passer par
 * `StockManager::getStock()` : une entité déjà chargée dans la requête ne voit pas
 * les écritures SQL faites depuis.
 *
 * @extends ServiceEntityRepository<StockCourant>
 */
class StockCourantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockCourant::class);
    }
}
