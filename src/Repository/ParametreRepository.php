<?php

namespace App\Repository;

use App\Entity\Parametre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Parametre>
 */
class ParametreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Parametre::class);
    }

    /**
     * Tous les paramètres enregistrés, indexés par clé.
     *
     * @return array<string, Parametre>
     */
    public function parCle(): array
    {
        $indexes = [];
        foreach ($this->findAll() as $parametre) {
            $indexes[$parametre->getCle()] = $parametre;
        }

        return $indexes;
    }
}
