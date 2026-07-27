<?php

namespace App\Repository;

use App\Entity\Vente;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Vente>
 */
class VenteRepository extends ServiceEntityRepository
{
    /** Tickets par page sur la liste de pilotage. */
    public const PAR_PAGE = 30;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Vente::class);
    }

    /**
     * Ventes paginées, toutes journées confondues, avec la session et le caissier.
     *
     * La jointure anticipée est indispensable : la liste du back-office affiche le
     * nom du caissier sur chaque ligne. Sans elle, Doctrine chargeait chaque
     * session puis chaque utilisateur à la demande — jusqu'à deux requêtes
     * supplémentaires par ligne affichée.
     *
     * @return Pagination<Vente>
     */
    public function paginees(int $page = 1, ?string $recherche = null): Pagination
    {
        $qb = $this->createQueryBuilder('v')
            ->join('v.sessionCaisse', 's')->addSelect('s')
            ->join('s.utilisateur', 'u')->addSelect('u')
            ->orderBy('v.createdAt', 'DESC')
            ->addOrderBy('v.id', 'DESC');

        // Numéro de ticket et nom du caissier : les deux entrées par lesquelles on
        // retrouve une vente. Le numéro rend enfin exploitable le code-barres
        // imprimé sur le ticket — un lecteur USB tape dans ce champ comme un
        // clavier, et la vente sort.
        Recherche::appliquer($qb, $recherche, 'v.numero', 'u.nom');

        // Seules des relations ToOne sont jointes : le comptage par sous-requête
        // serait du travail en pure perte.
        return Pagination::depuis($qb, $page);
    }

    /**
     * Toutes les ventes d'une journée, **sans pagination**, du plus ancien au plus
     * récent — l'ordre d'un journal de caisse, celui qu'on attend dans un export.
     *
     * Les règlements sont chargés d'avance : sans cela, exporter 300 tickets
     * déclencherait 300 requêtes supplémentaires pour lire leurs règlements.
     * La collection de lignes, elle, n'est pas jointe : l'export travaille au
     * ticket, pas à la ligne d'article.
     *
     * @return list<Vente>
     */
    public function toutesDuJour(\DateTimeImmutable $jour): array
    {
        $debut = $jour->setTime(0, 0);

        return $this->createQueryBuilder('v')
            ->join('v.sessionCaisse', 's')->addSelect('s')
            ->join('s.utilisateur', 'u')->addSelect('u')
            ->leftJoin('v.reglements', 'r')->addSelect('r')
            ->andWhere('v.createdAt >= :debut')->setParameter('debut', $debut)
            ->andWhere('v.createdAt < :fin')->setParameter('fin', $debut->modify('+1 day'))
            ->orderBy('v.createdAt', 'ASC')
            ->addOrderBy('v.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Tickets d'une journée, du plus récent au plus ancien, avec le caissier
     * (jointure anticipée : la liste affiche son nom sur chaque ligne).
     *
     * @return Pagination<Vente>
     */
    public function journee(\DateTimeImmutable $jour, int $page = 1): Pagination
    {
        $debut = $jour->setTime(0, 0);

        $qb = $this->createQueryBuilder('v')
            ->join('v.sessionCaisse', 's')->addSelect('s')
            ->join('s.utilisateur', 'u')->addSelect('u')
            ->andWhere('v.createdAt >= :debut')->setParameter('debut', $debut)
            ->andWhere('v.createdAt < :fin')->setParameter('fin', $debut->modify('+1 day'))
            ->orderBy('v.createdAt', 'DESC')
            ->addOrderBy('v.id', 'DESC');

        return Pagination::depuis($qb, $page, self::PAR_PAGE);
    }
}
