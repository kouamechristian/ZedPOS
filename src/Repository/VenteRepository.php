<?php

namespace App\Repository;

use App\Entity\SessionCaisse;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
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
     * Ventes paginées, toutes journées confondues, avec la session, le caissier
     * et les articles de chaque ligne.
     *
     * Les jointures anticipées sont indispensables : la liste du back-office
     * affiche le nom du caissier et le détail des articles sur chaque ligne.
     * Sans elles, Doctrine chargerait chaque session puis chaque utilisateur à
     * la demande, et une requête de plus par ligne pour son article.
     *
     * @return Pagination<Vente>
     */
    public function paginees(int $page = 1, ?string $recherche = null): Pagination
    {
        $qb = $this->createQueryBuilder('v')
            ->join('v.sessionCaisse', 's')->addSelect('s')
            ->join('s.utilisateur', 'u')->addSelect('u')
            ->leftJoin('v.lignes', 'l')->addSelect('l')
            ->leftJoin('l.article', 'a')->addSelect('a')
            ->orderBy('v.createdAt', 'DESC')
            ->addOrderBy('v.id', 'DESC');

        // Numéro de ticket et nom du caissier : les deux entrées par lesquelles on
        // retrouve une vente. Le numéro rend enfin exploitable le code-barres
        // imprimé sur le ticket — un lecteur USB tape dans ce champ comme un
        // clavier, et la vente sort.
        Recherche::appliquer($qb, $recherche, 'v.numero', 'u.nom');

        // `v.lignes` est désormais jointe : sans `fetchJoinCollection`, Doctrine
        // compterait les lignes du produit cartésien et non les tickets, et la
        // dernière page serait fausse.
        return Pagination::depuis($qb, $page, fetchJoinCollection: true);
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
     * Tickets d'une journée, du plus récent au plus ancien, avec le caissier et
     * les articles de chaque ligne (jointures anticipées : la liste affiche le
     * nom du caissier et le détail des articles sur chaque carte — sans elles,
     * chaque ticket affiché déclencherait une requête supplémentaire pour ses
     * lignes, puis une autre par ligne pour son article).
     *
     * `fetchJoinCollection: true` est indispensable dès qu'une collection
     * (`v.lignes`) est jointe : sans lui, Doctrine compterait les lignes du
     * produit cartésien et non les tickets, et la dernière page serait fausse.
     *
     * @return Pagination<Vente>
     */
    public function journee(\DateTimeImmutable $jour, int $page = 1): Pagination
    {
        $debut = $jour->setTime(0, 0);

        $qb = $this->createQueryBuilder('v')
            ->join('v.sessionCaisse', 's')->addSelect('s')
            ->join('s.utilisateur', 'u')->addSelect('u')
            ->leftJoin('v.lignes', 'l')->addSelect('l')
            ->leftJoin('l.article', 'a')->addSelect('a')
            ->andWhere('v.createdAt >= :debut')->setParameter('debut', $debut)
            ->andWhere('v.createdAt < :fin')->setParameter('fin', $debut->modify('+1 day'))
            ->orderBy('v.createdAt', 'DESC')
            ->addOrderBy('v.id', 'DESC');

        return Pagination::depuis($qb, $page, self::PAR_PAGE, fetchJoinCollection: true);
    }

    /**
     * Ventes d'une journée — toutes, ou celles d'une seule caissière —, **avec
     * leurs lignes, leurs articles et la famille de chaque article**.
     *
     * Sert au rapport de journée ventilé par famille. La ventilation se fait sur
     * les lignes : sans ces trois jointures anticipées, ventiler trois cents
     * tickets déclencherait une requête par ligne pour lire l'article, puis une
     * autre pour sa famille. Les règlements suivent, pour la même raison.
     *
     * Les ventes **annulées sont renvoyées** : c'est le rapport qui décide de les
     * écarter des totaux, et il les compte à part — un rapport muet sur les
     * annulations de la journée le serait sur ce qu'on vient précisément y
     * vérifier.
     *
     * @return list<Vente>
     */
    public function duJourAvecLignes(\DateTimeImmutable $jour, ?Utilisateur $caissier = null): array
    {
        $debut = $jour->setTime(0, 0);

        $qb = $this->createQueryBuilder('v')
            ->join('v.sessionCaisse', 's')->addSelect('s')
            ->join('s.utilisateur', 'u')->addSelect('u')
            ->leftJoin('v.lignes', 'l')->addSelect('l')
            ->leftJoin('l.article', 'a')->addSelect('a')
            ->leftJoin('a.familleProduit', 'f')->addSelect('f')
            ->leftJoin('v.reglements', 'r')->addSelect('r')
            ->andWhere('v.createdAt >= :debut')->setParameter('debut', $debut)
            ->andWhere('v.createdAt < :fin')->setParameter('fin', $debut->modify('+1 day'))
            ->orderBy('v.createdAt', 'ASC')
            ->addOrderBy('v.id', 'ASC');

        if (null !== $caissier) {
            $qb->andWhere('u = :caissier')->setParameter('caissier', $caissier);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Caissières ayant encaissé au moins un ticket dans la journée, par ordre
     * alphabétique.
     *
     * Alimente le sélecteur du rapport : proposer toute l'équipe, y compris ceux
     * qui n'étaient pas de service, reviendrait à proposer des rapports vides.
     *
     * @return list<Utilisateur>
     */
    public function caissiersDuJour(\DateTimeImmutable $jour): array
    {
        $debut = $jour->setTime(0, 0);

        // La requête part de l'utilisateur et non de la vente : Doctrine refuse
        // de sélectionner une entité jointe sans que sa propre racine soit dans
        // le SELECT (« Cannot select entity through identification variables »).
        return $this->getEntityManager()->createQueryBuilder()
            ->select('u')
            ->distinct()
            ->from(Utilisateur::class, 'u')
            ->join(SessionCaisse::class, 's', Join::WITH, 's.utilisateur = u')
            ->join(Vente::class, 'v', Join::WITH, 'v.sessionCaisse = s')
            ->andWhere('v.createdAt >= :debut')->setParameter('debut', $debut)
            ->andWhere('v.createdAt < :fin')->setParameter('fin', $debut->modify('+1 day'))
            ->orderBy('u.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Ventes d'une session de caisse, du plus récent au plus ancien —
     * l'historique personnel consultable depuis `/caisse/tickets`, borné à ce
     * qui a été encaissé **depuis l'ouverture** : une session antérieure déjà
     * clôturée a son propre rapport Z, ce n'est pas le rôle de cet écran de le
     * rejouer.
     *
     * Tri sur l'**identifiant**, pas sur `createdAt`, même raison que
     * {@see self::derniereDe()} : deux ventes tombent couramment dans la même
     * seconde en boulangerie rapide.
     *
     * Articles de chaque ligne joints d'avance (la carte affiche le détail du
     * ticket), avec `fetchJoinCollection: true` — voir {@see self::journee()}.
     *
     * @return Pagination<Vente>
     */
    public function pourSession(SessionCaisse $session, int $page = 1): Pagination
    {
        $qb = $this->createQueryBuilder('v')
            ->leftJoin('v.lignes', 'l')->addSelect('l')
            ->leftJoin('l.article', 'a')->addSelect('a')
            ->andWhere('v.sessionCaisse = :session')->setParameter('session', $session)
            ->orderBy('v.id', 'DESC');

        return Pagination::depuis($qb, $page, fetchJoinCollection: true);
    }

    /**
     * Dernière vente enregistrée dans une session, annulée ou non.
     *
     * Sert à borner l'annulation ouverte au caissier : il n'annule que le ticket
     * qu'il vient d'encaisser (voir {@see \App\Security\Voter\VenteVoter}).
     *
     * Le tri se fait sur l'**identifiant**, pas sur `createdAt` : en boulangerie
     * rapide deux ventes tombent couramment dans la même seconde, et « la
     * dernière » ne doit pas dépendre de laquelle l'horodatage a départagée.
     */
    public function derniereDe(SessionCaisse $session): ?Vente
    {
        return $this->createQueryBuilder('v')
            ->andWhere('v.sessionCaisse = :session')->setParameter('session', $session)
            ->orderBy('v.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
