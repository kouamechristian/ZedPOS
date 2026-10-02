<?php

namespace App\Repository;

use App\Entity\FicheProduction;
use App\Entity\SessionCaisse;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FicheProduction>
 */
class FicheProductionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FicheProduction::class);
    }

    /** La fiche de la caisse, lignes, articles et familles en une requête. */
    public function deLaSession(SessionCaisse $session): ?FicheProduction
    {
        return $this->createQueryBuilder('f')
            ->leftJoin('f.lignes', 'l')->addSelect('l')
            ->leftJoin('l.article', 'a')->addSelect('a')
            ->leftJoin('a.familleProduit', 'fa')->addSelect('fa')
            ->andWhere('f.sessionCaisse = :session')->setParameter('session', $session)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Ventes de la caisse par article d'atelier : unités en millièmes et montant
     * en centimes, ventes **validées** seulement — une vente annulée ou remplacée
     * n'a rien vendu. Additionné en base.
     *
     * Le montant est celui des lignes, calculé comme `LigneVente::getMontantTtc()`
     * (arrondi au centime, remise de ligne déduite). Une remise accordée sur le
     * ticket entier ne se rattache à aucun article : elle n'y est pas.
     *
     * @return array<int, array{quantite: int, montant: int}>
     */
    public function ventesParArticle(SessionCaisse $session): array
    {
        $lignes = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT lv.article_id, SUM(lv.quantite) AS quantite,
                    SUM((lv.quantite * lv.prix_unitaire + 500) DIV 1000 - lv.remise) AS montant
             FROM ligne_vente lv
             JOIN vente v ON v.id = lv.vente_id
             JOIN article a ON a.id = lv.article_id
             JOIN famille_produit f ON f.id = a.famille_produit_id
             WHERE v.session_caisse_id = ? AND v.statut = ? AND f.atelier IS NOT NULL
             GROUP BY lv.article_id',
            [$session->getId(), 'VALIDEE'],
        );

        $ventes = [];
        foreach ($lignes as $ligne) {
            $ventes[(int) $ligne['article_id']] = ['quantite' => (int) $ligne['quantite'], 'montant' => (int) $ligne['montant']];
        }

        return $ventes;
    }

    /**
     * Remises accordées sur les tickets validés de la caisse, en centimes — tous
     * articles confondus, boissons comprises : elles ne se rattachent à aucun.
     */
    public function remisesTickets(SessionCaisse $session): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT COALESCE(SUM(remise), 0) FROM vente WHERE session_caisse_id = ? AND statut = 'VALIDEE'",
            [$session->getId()],
        );
    }
}
