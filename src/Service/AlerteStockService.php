<?php

namespace App\Service;

use App\Entity\MatierePremiere;
use App\Repository\MatierePremiereRepository;

/**
 * Liste les matières premières dont le stock est passé sous le seuil d'alerte.
 * Le résultat est mémorisé pour n'exécuter qu'une requête par requête HTTP
 * (le service est utilisé comme variable globale Twig dans les bandeaux d'alerte).
 */
class AlerteStockService
{
    /** @var MatierePremiere[]|null */
    private ?array $cache = null;

    public function __construct(private readonly MatierePremiereRepository $matieres)
    {
    }

    /**
     * @return MatierePremiere[]
     */
    public function matieresSousSeuil(): array
    {
        return $this->cache ??= $this->matieres->createQueryBuilder('m')
            ->andWhere('m.stockActuel < m.stockMini')
            ->orderBy('m.stockActuel', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function nombre(): int
    {
        return \count($this->matieresSousSeuil());
    }
}
