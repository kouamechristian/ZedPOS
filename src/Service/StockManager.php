<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\Emplacement;
use App\Entity\MatierePremiere;
use App\Entity\MouvementStock;
use App\Entity\Utilisateur;
use App\Enum\TypeEmplacement;
use App\Enum\TypeMouvementStock;
use App\Repository\EmplacementRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * **Seul point d'écriture du stock** : `MouvementStock`, `StockCourant` et, tant
 * qu'ils subsistent, les champs `stockActuel` des articles et matières.
 *
 * Chaque écriture suit le même déroulé, dans une transaction :
 *
 * 1. **verrouiller** les lignes `stock_courant` concernées (`SELECT … FOR UPDATE`),
 *    dans un ordre fixe — deux opérations simultanées ne s'interbloquent pas ;
 * 2. **contrôler** : tout mouvement sortant qui laisserait un stock négatif est
 *    refusé ({@see StockInsuffisantException}), sauf la vente caisse
 *    ({@see TypeMouvementStock::tolereStockNegatif()}). Le refus tombe **avant**
 *    toute écriture dans l'unité de travail : l'EntityManager reste utilisable,
 *    le contrôleur peut réafficher son formulaire ;
 * 3. **écrire** mouvements, stock courant et champ historique, puis valider.
 *
 * Le stock courant n'est jamais lu depuis une entité en mémoire mais depuis la
 * base, sous verrou : c'est ce qui rend le contrôle de négativité sûr quand deux
 * caisses vendent la même matière à la même seconde.
 *
 * **Reprise de l'existant.** Au premier mouvement d'un produit au dépôt principal,
 * la ligne de stock courant reprend la valeur du champ `stockActuel`, et une
 * écriture d'ouverture (AJUSTEMENT, document « reprise ») est ajoutée pour que la
 * somme des mouvements retombe sur ce stock. C'est le calcul de la migration de
 * données, appliqué au fil de l'eau : une base créée sans cette migration (la
 * base de test, des fixtures purgées) reste cohérente.
 */
class StockManager
{
    public const DOCUMENT_REPRISE = 'reprise';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EmplacementRepository $emplacements,
        private readonly Security $security,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Le dépôt principal, créé s'il n'existe pas encore.
     *
     * La migration le crée ; il ne manque que sur une base montée sans elle, ou
     * purgée par les fixtures. Inséré en SQL et non par `flush()` : l'appelant peut
     * avoir des écritures en attente qui ne sont pas prêtes à partir.
     */
    public function depotPrincipal(): Emplacement
    {
        $depot = $this->emplacements->findOneBy(['code' => Emplacement::CODE_DEPOT_PRINCIPAL]);
        if (null !== $depot) {
            return $depot;
        }

        $this->connexion()->executeStatement(
            'INSERT INTO emplacement (code, libelle, type, actif, created_at) VALUES (?, ?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE id = id',
            [Emplacement::CODE_DEPOT_PRINCIPAL, 'Dépôt principal', TypeEmplacement::DEPOT->value, $this->maintenant()],
        );

        return $this->emplacements->findOneBy(['code' => Emplacement::CODE_DEPOT_PRINCIPAL])
            ?? throw new \LogicException('Le dépôt principal n\'a pas pu être créé.');
    }

    /**
     * @param int $quantite millièmes d'unité, signée (+ entrée, − sortie)
     *
     * @throws StockInsuffisantException si le mouvement laisserait un stock négatif
     */
    public function enregistrerMouvement(
        Article|MatierePremiere $produit,
        Emplacement $emplacement,
        int $quantite,
        TypeMouvementStock $type,
        ?string $documentType = null,
        ?int $documentId = null,
        ?string $motif = null,
        ?Utilisateur $utilisateur = null,
    ): MouvementStock {
        return $this->enregistrerMouvements(
            [new DemandeMouvementStock($produit, $emplacement, $quantite, $type, $documentType, $documentId, $motif)],
            $utilisateur,
        )[0];
    }

    /**
     * Un lot de mouvements, tout ou rien : une vente qui déstocke cinq matières,
     * une feuille d'inventaire. Un produit peut y figurer plusieurs fois, les
     * contrôles portent sur le cumul.
     *
     * @param list<DemandeMouvementStock> $demandes
     * @param ?Utilisateur                $utilisateur auteur ; par défaut l'utilisateur connecté
     *
     * @return list<MouvementStock> dans l'ordre des demandes
     *
     * @throws StockInsuffisantException
     */
    public function enregistrerMouvements(array $demandes, ?Utilisateur $utilisateur = null): array
    {
        foreach ($demandes as $demande) {
            if ($demande instanceof DemandeMouvementStock && $demande->type->estTransfert()) {
                throw new \InvalidArgumentException(\sprintf(
                    'Un mouvement « %s » ne s\'écrit que par transferer() : seul, il ferait apparaître ou disparaître de la marchandise.',
                    $demande->type->libelle(),
                ));
            }
        }

        return $this->appliquer($demandes, $utilisateur);
    }

    /**
     * Déplace du stock d'un emplacement à un autre : deux mouvements de même type,
     * l'un négatif à la source, l'autre positif à la destination, dans une seule
     * transaction. La source ne peut pas passer sous zéro.
     *
     * Un côté que l'emplacement ne suit pas ({@see self::suitLeStock()}) n'est pas
     * écrit : une baguette dotée à un stand n'a pas de stock à quitter au dépôt.
     *
     * @param int $quantite millièmes d'unité, strictement positive
     *
     * @return list<MouvementStock> sortie de la source puis entrée à la destination
     *
     * @throws StockInsuffisantException
     */
    public function transferer(
        Article|MatierePremiere $produit,
        Emplacement $source,
        Emplacement $destination,
        int $quantite,
        TypeMouvementStock $type,
        ?string $documentType = null,
        ?int $documentId = null,
        ?string $motif = null,
        ?Utilisateur $utilisateur = null,
    ): array {
        return $this->transfererLot([[$produit, $quantite]], $source, $destination, $type, $documentType, $documentId, $motif, $utilisateur);
    }

    /**
     * Plusieurs produits d'un même trajet, tout ou rien : un bon de dotation part
     * entier, ou pas du tout.
     *
     * @param list<array{0: Article|MatierePremiere, 1: int}> $lignes produit et quantité en millièmes
     *
     * @return list<MouvementStock> dans l'ordre des lignes, sortie puis entrée
     *
     * @throws StockInsuffisantException
     */
    public function transfererLot(
        array $lignes,
        Emplacement $source,
        Emplacement $destination,
        TypeMouvementStock $type,
        ?string $documentType = null,
        ?int $documentId = null,
        ?string $motif = null,
        ?Utilisateur $utilisateur = null,
    ): array {
        if ([] === $lignes) {
            throw new \InvalidArgumentException('Aucun produit à transférer.');
        }
        foreach ($lignes as [, $quantite]) {
            if ($quantite <= 0) {
                throw new \InvalidArgumentException('La quantité transférée doit être strictement positive.');
            }
        }

        $sens = $type->sens() ?? throw new \InvalidArgumentException(\sprintf(
            '« %s » n\'est pas un transfert : utilisez enregistrerMouvement().',
            $type->libelle(),
        ));

        if ($source === $destination || (null !== $source->getId() && $source->getId() === $destination->getId())) {
            throw new \InvalidArgumentException('La source et la destination d\'un transfert doivent être différentes.');
        }

        [$typeSource, $typeDestination] = $sens;
        if ($source->getType() !== $typeSource || $destination->getType() !== $typeDestination) {
            throw new \InvalidArgumentException(\sprintf(
                'Une %s va d\'un %s vers un %s, pas d\'un %s vers un %s.',
                mb_strtolower($type->libelle()),
                mb_strtolower($typeSource->libelle()),
                mb_strtolower($typeDestination->libelle()),
                mb_strtolower($source->getType()->libelle()),
                mb_strtolower($destination->getType()->libelle()),
            ));
        }

        $demandes = [];
        foreach ($lignes as [$produit, $quantite]) {
            if ($this->suitLeStock($source, $produit)) {
                $demandes[] = new DemandeMouvementStock($produit, $source, -$quantite, $type, $documentType, $documentId, $motif);
            }
            if ($this->suitLeStock($destination, $produit)) {
                $demandes[] = new DemandeMouvementStock($produit, $destination, $quantite, $type, $documentType, $documentId, $motif);
            }
        }

        return $this->appliquer($demandes, $utilisateur);
    }

    /**
     * Contre-passe des mouvements déjà écrits : mêmes produits, emplacements et
     * types, quantités opposées, en un seul lot. C'est l'annulation d'un document —
     * le mouvement d'origine reste, l'historique aussi.
     *
     * Soumise aux mêmes contrôles : annuler une dotation dont le stand a déjà vendu
     * une partie laisserait le stand en négatif, et c'est refusé.
     *
     * @param list<MouvementStock> $mouvements
     *
     * @return list<MouvementStock>
     *
     * @throws StockInsuffisantException
     */
    public function contrepasser(array $mouvements, ?string $documentType, ?int $documentId, ?string $motif, ?Utilisateur $utilisateur = null): array
    {
        return $this->appliquer(array_map(
            static fn (MouvementStock $m): DemandeMouvementStock => new DemandeMouvementStock(
                $m->getProduit(),
                $m->getEmplacement(),
                -$m->getQuantite(),
                $m->getType(),
                $documentType,
                $documentId,
                $motif,
            ),
            $mouvements,
        ), $utilisateur);
    }

    /**
     * Un emplacement tient-il un stock de ce produit ?
     *
     * Non pour un **article non suivi dans un dépôt** : le pain et les viennoiseries
     * fabriqués n'ont pas de stock produit fini, la caisse en consomme les matières
     * au moment de la vente. Les doter crédite le stand sans débiter le dépôt. Un
     * stand, lui, suit tout ce qu'on lui confie.
     */
    public function suitLeStock(Emplacement $emplacement, Article|MatierePremiere $produit): bool
    {
        return !($produit instanceof Article && !$produit->isSuiviStock() && TypeEmplacement::DEPOT === $emplacement->getType());
    }

    /**
     * Stock d'un produit dans un emplacement, en millièmes, lu en base.
     *
     * Au dépôt principal, un produit qui n'a encore jamais bougé depuis la bascule
     * rend son champ `stockActuel` : c'est la valeur que la reprise lui donnera.
     */
    public function getStock(Emplacement $emplacement, Article|MatierePremiere $produit): int
    {
        if (null === $emplacement->getId() || null === $produit->getId()) {
            return 0;
        }

        $quantite = $this->connexion()->fetchOne(
            \sprintf('SELECT quantite FROM stock_courant WHERE emplacement_id = ? AND %s = ?', $this->colonne($produit)),
            [$emplacement->getId(), $produit->getId()],
        );

        if (false !== $quantite) {
            return (int) $quantite;
        }

        return $emplacement->estDepotPrincipal() ? $this->champHistorique($produit) : 0;
    }

    /**
     * Stocks de plusieurs articles dans un emplacement, **en une requête** — une
     * grille de quarante produits ne fait pas quarante allers-retours.
     *
     * `null` pour un article que l'emplacement ne suit pas ({@see self::suitLeStock()}) :
     * « non suivi » n'est pas « zéro ».
     *
     * @param list<Article> $articles
     *
     * @return array<int, ?int> millièmes, par id d'article
     */
    public function stocksArticles(Emplacement $emplacement, array $articles): array
    {
        $stocks = [];
        $suivis = [];
        foreach ($articles as $article) {
            $stocks[$article->getId()] = null;
            if ($this->suitLeStock($emplacement, $article)) {
                $suivis[$article->getId()] = $article;
            }
        }

        if ([] === $suivis || null === $emplacement->getId()) {
            return $stocks;
        }

        $lignes = $this->connexion()->fetchAllKeyValue(
            'SELECT article_id, quantite FROM stock_courant WHERE emplacement_id = ? AND article_id IN (?)',
            [$emplacement->getId(), array_keys($suivis)],
            [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ArrayParameterType::INTEGER],
        );

        foreach ($suivis as $id => $article) {
            // Sans ligne au dépôt principal, la reprise est à venir : le champ
            // historique fait foi, comme dans getStock().
            $stocks[$id] = \array_key_exists($id, $lignes)
                ? (int) $lignes[$id]
                : ($emplacement->estDepotPrincipal() ? $article->getStockActuel() : 0);
        }

        return $stocks;
    }

    /**
     * Reconstruit le stock courant d'un emplacement à partir de ses mouvements, et
     * au dépôt principal le champ `stockActuel`. Outil de réparation : en temps
     * normal il ne trouve rien à corriger.
     *
     * Les produits sans mouvement ni ligne de stock ne sont pas touchés — au dépôt
     * principal, leur reprise est simplement encore à venir.
     *
     * Une entrée par produit corrigé ; `champHistoriqueAvant` n'est renseigné que
     * si `stockActuel` divergeait.
     *
     * @return list<array{produit: string, avant: int, apres: int, champHistoriqueAvant: ?int}>
     */
    public function recalculerStock(Emplacement $emplacement): array
    {
        if (null === $emplacement->getId()) {
            throw new \InvalidArgumentException('Emplacement non enregistré.');
        }

        $connexion = $this->connexion();
        $connexion->beginTransaction();

        try {
            $actuels = [];
            foreach ($connexion->fetchAllAssociative(
                'SELECT article_id, matiere_premiere_id, quantite FROM stock_courant WHERE emplacement_id = ? FOR UPDATE',
                [$emplacement->getId()],
            ) as $ligne) {
                $actuels[$this->cleLigne($ligne)] = (int) $ligne['quantite'];
            }

            $attendus = [];
            foreach ($connexion->fetchAllAssociative(
                'SELECT article_id, matiere_premiere_id, COALESCE(SUM(quantite), 0) AS quantite
                 FROM mouvement_stock WHERE emplacement_id = ?
                 GROUP BY article_id, matiere_premiere_id',
                [$emplacement->getId()],
            ) as $ligne) {
                $attendus[$this->cleLigne($ligne)] = (int) $ligne['quantite'];
            }

            $corrections = [];
            $maintenant = $this->maintenant();

            foreach (array_keys($actuels + $attendus) as $cle) {
                [$genre, $id] = explode(':', $cle);
                $estArticle = 'a' === $genre;
                $colonne = $estArticle ? 'article_id' : 'matiere_premiere_id';
                $table = $estArticle ? 'article' : 'matiere_premiere';
                $attendu = $attendus[$cle] ?? 0;
                $actuel = $actuels[$cle] ?? null;

                if (null === $actuel) {
                    $connexion->executeStatement(
                        \sprintf('INSERT INTO stock_courant (emplacement_id, %s, quantite, modifie_a) VALUES (?, ?, ?, ?)', $colonne),
                        [$emplacement->getId(), $id, $attendu, $maintenant],
                    );
                } elseif ($actuel !== $attendu) {
                    $connexion->executeStatement(
                        \sprintf('UPDATE stock_courant SET quantite = ?, modifie_a = ? WHERE emplacement_id = ? AND %s = ?', $colonne),
                        [$attendu, $maintenant, $emplacement->getId(), $id],
                    );
                }

                $historiqueAvant = null;
                if ($emplacement->estDepotPrincipal()) {
                    $historique = (int) $connexion->fetchOne(\sprintf('SELECT stock_actuel FROM %s WHERE id = ?', $table), [$id]);
                    if ($historique !== $attendu) {
                        $historiqueAvant = $historique;
                        $connexion->executeStatement(\sprintf('UPDATE %s SET stock_actuel = ? WHERE id = ?', $table), [$attendu, $id]);
                    }
                    $this->synchroniserEnMemoire($estArticle ? Article::class : MatierePremiere::class, (int) $id, $attendu);
                }

                if (($actuel ?? 0) !== $attendu || null !== $historiqueAvant) {
                    $corrections[] = [
                        'produit' => (string) $connexion->fetchOne(\sprintf('SELECT nom FROM %s WHERE id = ?', $table), [$id]),
                        'avant' => $actuel ?? 0,
                        'apres' => $attendu,
                        'champHistoriqueAvant' => $historiqueAvant,
                    ];
                }
            }

            $connexion->commit();
        } catch (\Throwable $e) {
            $this->annuler($connexion);

            throw $e;
        }

        return $corrections;
    }

    /**
     * @param list<DemandeMouvementStock> $demandes
     *
     * @return list<MouvementStock>
     */
    private function appliquer(array $demandes, ?Utilisateur $utilisateur): array
    {
        if ([] === $demandes) {
            return [];
        }

        foreach ($demandes as $demande) {
            $this->valider($demande);
        }

        $utilisateur ??= $this->utilisateurConnecte();

        // Couples emplacement × produit, triés : l'ordre des verrous est le même
        // pour toutes les opérations, deux ventes simultanées ne s'interbloquent pas.
        $couples = [];
        foreach ($demandes as $demande) {
            $couples[$this->cle($demande->emplacement, $demande->produit)] = [$demande->emplacement, $demande->produit];
        }
        ksort($couples);

        $connexion = $this->connexion();
        $connexion->beginTransaction();

        try {
            // 1. Lecture sous verrou.
            $soldes = [];
            $reprises = [];
            foreach ($couples as $cle => [$emplacement, $produit]) {
                [$soldes[$cle], $reprise] = $this->verrouiller($emplacement, $produit);
                if (0 !== $reprise) {
                    $reprises[$cle] = $reprise;
                }
            }

            // 2. Contrôles, sur le cumul — avant toute écriture dans l'unité de travail.
            $finals = $soldes;
            foreach ($demandes as $demande) {
                $cle = $this->cle($demande->emplacement, $demande->produit);
                $apres = $finals[$cle] + $demande->quantite;

                if ($demande->quantite < 0 && $apres < 0 && !$demande->type->tolereStockNegatif()) {
                    throw StockInsuffisantException::pour($demande->produit, $demande->emplacement, $finals[$cle], -$demande->quantite);
                }

                $finals[$cle] = $apres;
            }

            // 3. Écritures.
            foreach ($reprises as $cle => $quantite) {
                [$emplacement, $produit] = $couples[$cle];
                $this->em->persist(new MouvementStock(
                    $this->gerer($produit),
                    $this->gerer($emplacement),
                    TypeMouvementStock::AJUSTEMENT,
                    $quantite,
                    'Reprise du stock existant (passage au stock par emplacement)',
                    self::DOCUMENT_REPRISE,
                ));
            }

            $mouvements = [];
            foreach ($demandes as $demande) {
                $mouvement = new MouvementStock(
                    $this->gerer($demande->produit),
                    $this->gerer($demande->emplacement),
                    $demande->type,
                    $demande->quantite,
                    $demande->motif,
                    $demande->documentType,
                    $demande->documentId,
                    null !== $utilisateur ? $this->gerer($utilisateur) : null,
                );
                $this->em->persist($mouvement);
                $mouvements[] = $mouvement;
            }

            $maintenant = $this->maintenant();
            foreach ($couples as $cle => [$emplacement, $produit]) {
                $connexion->executeStatement(
                    \sprintf('UPDATE stock_courant SET quantite = ?, modifie_a = ? WHERE emplacement_id = ? AND %s = ?', $this->colonne($produit)),
                    [$finals[$cle], $maintenant, $emplacement->getId(), $produit->getId()],
                );

                if ($emplacement->estDepotPrincipal()) {
                    $this->synchroniserChampHistorique($produit, $finals[$cle]);
                }
            }

            $this->em->flush();
            $connexion->commit();
        } catch (\Throwable $e) {
            $this->annuler($connexion);

            throw $e;
        }

        foreach ($couples as $cle => [$emplacement, $produit]) {
            $this->alerter($emplacement, $produit, $finals[$cle]);
        }

        return $mouvements;
    }

    private function valider(mixed $demande): void
    {
        if (!$demande instanceof DemandeMouvementStock) {
            throw new \InvalidArgumentException('Demande de mouvement de stock attendue.');
        }
        if (0 === $demande->quantite) {
            throw new \InvalidArgumentException('Un mouvement de stock ne peut pas être nul.');
        }
        if (null === $demande->produit->getId() || null === $demande->emplacement->getId()) {
            throw new \InvalidArgumentException('Le produit et l\'emplacement doivent être enregistrés avant tout mouvement de stock.');
        }
        // La caisse ne se bloque pas plus sur un emplacement que sur un stock.
        if (!$demande->emplacement->isActif() && !$demande->type->tolereStockNegatif()) {
            throw new \DomainException(\sprintf('L\'emplacement « %s » est désactivé : il n\'accepte plus de mouvement.', $demande->emplacement->getLibelle()));
        }
    }

    /**
     * Verrouille la ligne de stock courant du couple, en la créant si besoin.
     *
     * @return array{0: int, 1: int} [quantité actuelle, quantité de reprise à écrire (0 si aucune)]
     */
    private function verrouiller(Emplacement $emplacement, Article|MatierePremiere $produit): array
    {
        $connexion = $this->connexion();
        $colonne = $this->colonne($produit);
        $lecture = \sprintf('SELECT quantite FROM stock_courant WHERE emplacement_id = ? AND %s = ? FOR UPDATE', $colonne);
        $cle = [$emplacement->getId(), $produit->getId()];

        $quantite = $connexion->fetchOne($lecture, $cle);
        if (false !== $quantite) {
            return [(int) $quantite, 0];
        }

        // Premier mouvement du couple. Au dépôt principal, on reprend le stock que
        // portait le champ historique, et l'écart avec les mouvements déjà écrits
        // devient l'écriture d'ouverture.
        $base = 0;
        $reprise = 0;
        if ($emplacement->estDepotPrincipal()) {
            $base = $this->champHistorique($produit);
            $dejaEcrit = (int) $connexion->fetchOne(
                \sprintf('SELECT COALESCE(SUM(quantite), 0) FROM mouvement_stock WHERE emplacement_id = ? AND %s = ?', $colonne),
                $cle,
            );
            $reprise = $base - $dejaEcrit;
        }

        $cree = $connexion->executeStatement(
            \sprintf('INSERT INTO stock_courant (emplacement_id, %s, quantite, modifie_a) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id', $colonne),
            [...$cle, $base, $this->maintenant()],
        );

        if (0 === $cree) {
            // Créée entre-temps par une opération concurrente, qui a fait la reprise.
            return [(int) $connexion->fetchOne($lecture, $cle), 0];
        }

        return [$base, $reprise];
    }

    /** Champ `stockActuel` lu en base : l'entité en mémoire peut dater du début de la requête. */
    private function champHistorique(Article|MatierePremiere $produit): int
    {
        return (int) $this->connexion()->fetchOne(
            \sprintf('SELECT stock_actuel FROM %s WHERE id = ?', $this->table($produit)),
            [$produit->getId()],
        );
    }

    /**
     * Recopie le stock du dépôt principal dans `stockActuel`, en base **et** sur
     * l'objet — l'appelant qui relit `getStockActuel()` doit voir la nouvelle
     * valeur, comme avant le passage au stock par emplacement.
     */
    private function synchroniserChampHistorique(Article|MatierePremiere $produit, int $quantite): void
    {
        $this->connexion()->executeStatement(
            \sprintf('UPDATE %s SET stock_actuel = ? WHERE id = ?', $this->table($produit)),
            [$quantite, $produit->getId()],
        );

        $produit->setStockActuel($quantite);
        $gere = $this->gerer($produit);
        if ($gere !== $produit) {
            $gere->setStockActuel($quantite);
        }
    }

    /** @param class-string<Article|MatierePremiere> $classe */
    private function synchroniserEnMemoire(string $classe, int $id, int $quantite): void
    {
        $entite = $this->em->getUnitOfWork()->tryGetById($id, $classe);
        if ($entite instanceof Article || $entite instanceof MatierePremiere) {
            $entite->setStockActuel($quantite);
        }
    }

    private function alerter(Emplacement $emplacement, Article|MatierePremiere $produit, int $stock): void
    {
        $contexte = ['produit' => $produit->getNom(), 'emplacement' => $emplacement->getLibelle(), 'stock' => $stock];

        if ($stock < 0) {
            $this->logger->warning('Stock négatif sur « {produit} » ({emplacement}) : {stock} millièmes.', $contexte);
        } elseif ($emplacement->estDepotPrincipal() && $stock < $produit->getStockMini()) {
            $this->logger->notice('Stock de « {produit} » ({emplacement}) sous le seuil d\'alerte : {stock} millièmes.', $contexte);
        }
    }

    /**
     * Référence gérée par l'EntityManager. Une entité détachée (après un `clear()`)
     * passée telle quelle serait prise pour une nouvelle au `flush()`.
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

        $classe = $this->em->getClassMetadata($entite::class)->getName();

        return $this->em->getReference($classe, $entite->getId());
    }

    private function annuler(Connection $connexion): void
    {
        if ($connexion->isTransactionActive()) {
            $connexion->rollBack();
        }
    }

    private function utilisateurConnecte(): ?Utilisateur
    {
        $utilisateur = $this->security->getUser();

        return $utilisateur instanceof Utilisateur ? $utilisateur : null;
    }

    private function cle(Emplacement $emplacement, Article|MatierePremiere $produit): string
    {
        return \sprintf('%010d:%s:%010d', $emplacement->getId(), $produit instanceof Article ? 'a' : 'm', $produit->getId());
    }

    /** @param array<string, mixed> $ligne */
    private function cleLigne(array $ligne): string
    {
        return null !== $ligne['article_id'] ? 'a:'.$ligne['article_id'] : 'm:'.$ligne['matiere_premiere_id'];
    }

    private function colonne(Article|MatierePremiere $produit): string
    {
        return $produit instanceof Article ? 'article_id' : 'matiere_premiere_id';
    }

    private function table(Article|MatierePremiere $produit): string
    {
        return $produit instanceof Article ? 'article' : 'matiere_premiere';
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
