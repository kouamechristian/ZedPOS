<?php

namespace App\Repository;

use App\Entity\JournalAudit;
use App\Entity\Utilisateur;
use App\Enum\ActionAudit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Le journal est en **lecture seule** : ce dépôt n'expose aucune méthode
 * d'écriture ni de suppression (les entrées sont créées par
 * {@see \App\Service\AuditLogger} et jamais modifiées ensuite).
 *
 * @extends ServiceEntityRepository<JournalAudit>
 */
class JournalAuditRepository extends ServiceEntityRepository
{
    /** Nombre d'entrées par page sur l'écran de consultation. */
    public const PAR_PAGE = 50;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JournalAudit::class);
    }

    /**
     * Recherche paginée, filtrée par période, auteur et type d'action.
     *
     * @param \DateTimeImmutable|null $du  début de période (inclus)
     * @param \DateTimeImmutable|null $au  fin de période (inclus — la journée entière)
     * @param int                     $page numéro de page, à partir de 1
     *
     * @return array{entrees: list<JournalAudit>, total: int, pages: int, page: int}
     */
    public function rechercher(
        ?\DateTimeImmutable $du = null,
        ?\DateTimeImmutable $au = null,
        ?Utilisateur $utilisateur = null,
        ?ActionAudit $action = null,
        int $page = 1,
    ): array {
        $qb = $this->createQueryBuilder('j')
            ->leftJoin('j.utilisateur', 'u')->addSelect('u')
            ->orderBy('j.createdAt', 'DESC')
            ->addOrderBy('j.id', 'DESC');

        if (null !== $du) {
            $qb->andWhere('j.createdAt >= :du')->setParameter('du', $du->setTime(0, 0));
        }
        if (null !== $au) {
            // Borne haute exclusive au lendemain : la journée « au » est incluse.
            $qb->andWhere('j.createdAt < :au')->setParameter('au', $au->setTime(0, 0)->modify('+1 day'));
        }
        if (null !== $utilisateur) {
            $qb->andWhere('j.utilisateur = :utilisateur')->setParameter('utilisateur', $utilisateur);
        }
        if (null !== $action) {
            $qb->andWhere('j.action = :action')->setParameter('action', $action->value);
        }

        $page = max(1, $page);
        $qb->setFirstResult(($page - 1) * self::PAR_PAGE)->setMaxResults(self::PAR_PAGE);

        $paginateur = new Paginator($qb->getQuery(), fetchJoinCollection: false);
        $total = \count($paginateur);

        return [
            'entrees' => iterator_to_array($paginateur),
            'total' => $total,
            'pages' => max(1, (int) ceil($total / self::PAR_PAGE)),
            'page' => $page,
        ];
    }

    /**
     * Auteurs présents dans le journal, pour alimenter le filtre « utilisateur ».
     *
     * @return list<Utilisateur>
     */
    public function auteurs(): array
    {
        // Racine sur Utilisateur : DQL n'autorise pas à sélectionner l'entité d'une
        // jointure comme résultat principal.
        return $this->getEntityManager()->createQuery(
            'SELECT u FROM '.Utilisateur::class.' u
             WHERE EXISTS (SELECT j.id FROM '.JournalAudit::class.' j WHERE j.utilisateur = u)
             ORDER BY u.nom ASC'
        )->getResult();
    }
}
