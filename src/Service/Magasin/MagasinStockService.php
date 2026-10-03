<?php

namespace App\Service\Magasin;

use App\Entity\Magasin\MagasinEmplacement;
use App\Entity\Magasin\MagasinMouvement;
use App\Entity\Magasin\MagasinProduit;
use App\Entity\Utilisateur;
use App\Enum\Magasin\TypeMouvementMagasin;
use App\Repository\Magasin\MagasinEmplacementRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * **Seul point d'écriture du stock du magasin** : `magasin_mouvement` et
 * `magasin_stock`. Les bons (réception, sortie, inventaire) lui demandent des
 * mouvements ; ils n'écrivent jamais le stock eux-mêmes.
 *
 * Même discipline que le stock de la boutique, réécrite ici pour que le module
 * reste indépendant. Chaque écriture, dans une transaction :
 *
 * 1. **verrouiller** les lignes `magasin_stock` concernées (`SELECT … FOR
 *    UPDATE`), dans un ordre fixe — deux bons simultanés ne s'interbloquent pas ;
 *    une ligne absente est créée à zéro ;
 * 2. **contrôler**, sur le cumul : tout mouvement sortant qui laisserait un stock
 *    **négatif** est refusé ({@see StockMagasinInsuffisantException}) **avant**
 *    toute écriture — l'EntityManager reste utilisable, l'écran se réaffiche en
 *    422 avec son message ;
 * 3. **écrire** les mouvements (immuables) et le nouveau stock.
 *
 * Invariant : pour chaque couple produit × emplacement, le stock égale la somme
 * des mouvements. {@see self::verifier()} le contrôle (`magasin:stock:verifier`).
 *
 * Aucune vente, aucune fiche technique ne passe par ici : le stock du magasin ne
 * bouge que par ses propres bons.
 */
class MagasinStockService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MagasinEmplacementRepository $emplacements,
        private readonly Security $security,
    ) {
    }

    /**
     * « Réserve principale », créée si elle manque — la migration la crée, une base
     * purgée par les fixtures ou les tests non. Insérée en SQL : l'appelant peut
     * avoir des écritures en attente qui ne sont pas prêtes à partir.
     */
    public function reserveParDefaut(): MagasinEmplacement
    {
        $reserve = $this->emplacements->findOneBy(['code' => MagasinEmplacement::CODE_RESERVE]);
        if (null !== $reserve) {
            return $reserve;
        }

        $this->connexion()->executeStatement(
            'INSERT INTO magasin_emplacement (code, libelle, actif, created_at) VALUES (?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE id = id',
            [MagasinEmplacement::CODE_RESERVE, 'Réserve principale', $this->maintenant()],
        );

        return $this->emplacements->findOneBy(['code' => MagasinEmplacement::CODE_RESERVE])
            ?? throw new \LogicException('La réserve principale n\'a pas pu être créée.');
    }

    /**
     * Applique un lot de mouvements, tout ou rien.
     *
     * @param list<DemandeMouvementMagasin> $demandes
     * @param ?Utilisateur                  $auteur   par défaut l'utilisateur connecté
     *
     * @return list<MagasinMouvement> dans l'ordre des demandes
     *
     * @throws StockMagasinInsuffisantException si un stock passait sous zéro
     */
    public function appliquer(array $demandes, ?Utilisateur $auteur = null): array
    {
        if ([] === $demandes) {
            return [];
        }
        foreach ($demandes as $demande) {
            $this->valider($demande);
        }
        $auteur ??= $this->utilisateurConnecte();

        // Couples triés : toutes les opérations verrouillent dans le même ordre.
        $couples = [];
        foreach ($demandes as $demande) {
            $couples[self::cle($demande->produit, $demande->emplacement)] = [$demande->produit, $demande->emplacement];
        }
        ksort($couples);

        $connexion = $this->connexion();
        $connexion->beginTransaction();

        try {
            // 1. Lecture sous verrou.
            $soldes = [];
            foreach ($couples as $cle => [$produit, $emplacement]) {
                $soldes[$cle] = $this->verrouiller($produit, $emplacement);
            }

            // 2. Contrôles, sur le cumul — avant toute écriture dans l'unité de travail.
            $finals = $soldes;
            foreach ($demandes as $demande) {
                $cle = self::cle($demande->produit, $demande->emplacement);
                $apres = $finals[$cle] + $demande->quantite;
                if ($demande->quantite < 0 && $apres < 0) {
                    throw new StockMagasinInsuffisantException(
                        $demande->produit->getNom(),
                        $demande->emplacement->getLibelle(),
                        $finals[$cle],
                        -$demande->quantite,
                        QuantiteMagasin::formater($demande->produit, $finals[$cle]),
                        QuantiteMagasin::formater($demande->produit, -$demande->quantite),
                    );
                }
                $finals[$cle] = $apres;
            }

            // 3. Écritures.
            $mouvements = [];
            foreach ($demandes as $demande) {
                $mouvement = new MagasinMouvement(
                    $this->gerer($demande->produit),
                    $this->gerer($demande->emplacement),
                    $demande->type,
                    $demande->quantite,
                    $demande->documentType,
                    $demande->documentId,
                    $demande->motif,
                    null !== $auteur ? $this->gerer($auteur) : null,
                );
                $this->em->persist($mouvement);
                $mouvements[] = $mouvement;
            }

            $maintenant = $this->maintenant();
            foreach ($couples as $cle => [$produit, $emplacement]) {
                $connexion->executeStatement(
                    'UPDATE magasin_stock SET quantite = ?, modifie_a = ? WHERE produit_id = ? AND emplacement_id = ?',
                    [$finals[$cle], $maintenant, $produit->getId(), $emplacement->getId()],
                );
            }

            $this->em->flush();
            $connexion->commit();
        } catch (\Throwable $e) {
            if ($connexion->isTransactionActive()) {
                $connexion->rollBack();
            }

            throw $e;
        }

        return $mouvements;
    }

    /**
     * Contre-passe des mouvements déjà écrits : mêmes produits et emplacements,
     * quantités opposées, type ANNULATION. C'est l'annulation d'un document — les
     * mouvements d'origine restent. Soumise au même contrôle : annuler une
     * réception dont la marchandise est déjà sortie laisserait un stock négatif, et
     * c'est refusé.
     *
     * @param list<MagasinMouvement> $mouvements
     *
     * @return list<MagasinMouvement>
     *
     * @throws StockMagasinInsuffisantException
     */
    public function contrepasser(array $mouvements, string $documentType, int $documentId, ?string $motif, ?Utilisateur $auteur = null): array
    {
        return $this->appliquer(array_map(
            static fn (MagasinMouvement $m): DemandeMouvementMagasin => new DemandeMouvementMagasin(
                $m->getProduit(),
                $m->getEmplacement(),
                -$m->getQuantite(),
                TypeMouvementMagasin::ANNULATION,
                $documentType,
                $documentId,
                $motif,
            ),
            $mouvements,
        ), $auteur);
    }

    /** Stock d'un produit dans un emplacement, en millièmes, lu en base. */
    public function stock(MagasinProduit $produit, MagasinEmplacement $emplacement): int
    {
        if (null === $produit->getId() || null === $emplacement->getId()) {
            return 0;
        }

        return (int) $this->connexion()->fetchOne(
            'SELECT COALESCE(SUM(quantite), 0) FROM magasin_stock WHERE produit_id = ? AND emplacement_id = ?',
            [$produit->getId(), $emplacement->getId()],
        );
    }

    /** Stock d'un produit, tous emplacements confondus, en millièmes. */
    public function stockTotal(MagasinProduit $produit): int
    {
        if (null === $produit->getId()) {
            return 0;
        }

        return (int) $this->connexion()->fetchOne(
            'SELECT COALESCE(SUM(quantite), 0) FROM magasin_stock WHERE produit_id = ?',
            [$produit->getId()],
        );
    }

    /**
     * Contrôle de l'invariant : chaque ligne de stock égale la somme de ses
     * mouvements. Ne corrige rien — un écart est un défaut à comprendre, pas à
     * effacer.
     *
     * @return list<array{produit: string, emplacement: string, stock: int, mouvements: int}> les couples en écart
     */
    public function verifier(): array
    {
        $lignes = $this->connexion()->fetchAllAssociative(
            'SELECT p.nom AS produit, e.libelle AS emplacement,
                    COALESCE(s.quantite, 0) AS stock, COALESCE(m.total, 0) AS mouvements
             FROM (
                 SELECT produit_id, emplacement_id FROM magasin_stock
                 UNION
                 SELECT produit_id, emplacement_id FROM magasin_mouvement
             ) c
             JOIN magasin_produit p ON p.id = c.produit_id
             JOIN magasin_emplacement e ON e.id = c.emplacement_id
             LEFT JOIN magasin_stock s ON s.produit_id = c.produit_id AND s.emplacement_id = c.emplacement_id
             LEFT JOIN (
                 SELECT produit_id, emplacement_id, SUM(quantite) AS total
                 FROM magasin_mouvement GROUP BY produit_id, emplacement_id
             ) m ON m.produit_id = c.produit_id AND m.emplacement_id = c.emplacement_id
             ORDER BY p.nom, e.libelle',
        );

        $ecarts = [];
        foreach ($lignes as $ligne) {
            if ((int) $ligne['stock'] !== (int) $ligne['mouvements']) {
                $ecarts[] = [
                    'produit' => (string) $ligne['produit'],
                    'emplacement' => (string) $ligne['emplacement'],
                    'stock' => (int) $ligne['stock'],
                    'mouvements' => (int) $ligne['mouvements'],
                ];
            }
        }

        return $ecarts;
    }

    private function valider(mixed $demande): void
    {
        if (!$demande instanceof DemandeMouvementMagasin) {
            throw new \InvalidArgumentException('Demande de mouvement du magasin attendue.');
        }
        if (0 === $demande->quantite) {
            throw new \InvalidArgumentException('Un mouvement du magasin ne peut pas être nul.');
        }
        if (null === $demande->produit->getId() || null === $demande->emplacement->getId()) {
            throw new \InvalidArgumentException('Le produit et l\'emplacement doivent être enregistrés avant tout mouvement.');
        }
        // Une zone fermée ne reçoit plus rien ; on peut encore la vider ou contre-passer.
        if (!$demande->emplacement->isActif() && $demande->quantite > 0 && TypeMouvementMagasin::ANNULATION !== $demande->type) {
            throw new \DomainException(\sprintf('L\'emplacement « %s » est désactivé : il ne reçoit plus de marchandise.', $demande->emplacement->getLibelle()));
        }
    }

    /** Verrouille la ligne de stock du couple, créée à zéro si besoin, et rend sa quantité. */
    private function verrouiller(MagasinProduit $produit, MagasinEmplacement $emplacement): int
    {
        $connexion = $this->connexion();
        $lecture = 'SELECT quantite FROM magasin_stock WHERE produit_id = ? AND emplacement_id = ? FOR UPDATE';
        $cle = [$produit->getId(), $emplacement->getId()];

        $quantite = $connexion->fetchOne($lecture, $cle);
        if (false !== $quantite) {
            return (int) $quantite;
        }

        $connexion->executeStatement(
            'INSERT INTO magasin_stock (produit_id, emplacement_id, quantite, modifie_a) VALUES (?, ?, 0, ?)
             ON DUPLICATE KEY UPDATE id = id',
            [...$cle, $this->maintenant()],
        );

        return (int) $connexion->fetchOne($lecture, $cle);
    }

    /**
     * Référence gérée par l'EntityManager : une entité détachée passée telle quelle
     * serait prise pour une nouvelle au `flush()`.
     *
     * @template T of object
     *
     * @param T $entite
     *
     * @return T
     */
    private function gerer(object $entite): object
    {
        if ($this->em->contains($entite)) {
            return $entite;
        }

        return $this->em->getReference($this->em->getClassMetadata($entite::class)->getName(), $entite->getId());
    }

    private function utilisateurConnecte(): ?Utilisateur
    {
        $utilisateur = $this->security->getUser();

        return $utilisateur instanceof Utilisateur ? $utilisateur : null;
    }

    private static function cle(MagasinProduit $produit, MagasinEmplacement $emplacement): string
    {
        return \sprintf('%010d:%010d', $produit->getId(), $emplacement->getId());
    }

    private function connexion(): Connection
    {
        return $this->em->getConnection();
    }

    private function maintenant(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
