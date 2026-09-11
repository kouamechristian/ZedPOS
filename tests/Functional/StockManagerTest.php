<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\Emplacement;
use App\Entity\MatierePremiere;
use App\Entity\MouvementStock;
use App\Enum\TypeEmplacement;
use App\Enum\TypeMouvementStock;
use App\Service\DemandeMouvementStock;
use App\Service\StockInsuffisantException;
use App\Service\StockManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * StockManager, seul point d'écriture du stock par emplacement.
 *
 * Tests de service contre la base de test, et non avec des doublures : ce qu'ils
 * doivent prouver — verrou de ligne, contrainte d'unicité, annulation d'une
 * transaction à moitié faite — n'existe qu'en base. Un EntityManager simulé
 * laisserait passer exactement les défauts qu'on cherche.
 *
 * Invariant vérifié à chaque fois qu'il a un sens : pour un couple
 * emplacement × produit, stock courant = somme des mouvements, et au dépôt
 * principal, champ `stockActuel` = stock courant.
 */
class StockManagerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private StockManager $stock;
    private Emplacement $depot;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->stock = static::getContainer()->get(StockManager::class);

        $connexion = $this->em->getConnection();
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['ligne_inventaire', 'inventaire', 'ligne_fiche_technique', 'fiche_technique', 'ligne_vente', 'reglement', 'vente', 'mouvement_caisse', 'session_caisse', 'mouvement_stock', 'stock_courant', 'perte', 'article', 'matiere_premiere', 'fournisseur', 'famille_produit', 'journal_audit', 'notification', 'utilisateur'] as $table) {
            $connexion->executeStatement('DELETE FROM '.$table);
        }
        // Le dépôt principal reste : c'est celui de la migration.
        $connexion->executeStatement("DELETE FROM emplacement WHERE code <> 'DEPOT'");
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $this->depot = $this->stock->depotPrincipal();
    }

    // ------------------------------------------------------------- Fabriques

    private function matiere(string $nom = 'Farine', int $stockHistorique = 0): MatierePremiere
    {
        $matiere = (new MatierePremiere($nom, 'kg'))->setStockActuel($stockHistorique);
        $this->em->persist($matiere);
        $this->em->flush();

        return $matiere;
    }

    private function article(string $nom = 'Coca'): Article
    {
        $article = (new Article($nom, 50000, 'bouteille'))->setSuiviStock(true);
        $this->em->persist($article);
        $this->em->flush();

        return $article;
    }

    private function stand(string $code = 'STAND-1', bool $actif = true): Emplacement
    {
        $stand = (new Emplacement($code, 'Stand '.$code, TypeEmplacement::STAND))->setActif($actif);
        $this->em->persist($stand);
        $this->em->flush();

        return $stand;
    }

    /** Approvisionne par un mouvement, comme en exploitation. */
    private function approvisionner(Article|MatierePremiere $produit, int $quantite, ?Emplacement $emplacement = null): void
    {
        $this->stock->enregistrerMouvement($produit, $emplacement ?? $this->depot, $quantite, TypeMouvementStock::AJUSTEMENT, motif: 'Approvisionnement');
    }

    private function nombreMouvements(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM mouvement_stock');
    }

    private function sommeMouvements(Emplacement $emplacement, Article|MatierePremiere $produit): int
    {
        $colonne = $produit instanceof Article ? 'article_id' : 'matiere_premiere_id';

        return (int) $this->em->getConnection()->fetchOne(
            "SELECT COALESCE(SUM(quantite), 0) FROM mouvement_stock WHERE emplacement_id = ? AND {$colonne} = ?",
            [$emplacement->getId(), $produit->getId()],
        );
    }

    private function champHistoriqueEnBase(Article|MatierePremiere $produit): int
    {
        $table = $produit instanceof Article ? 'article' : 'matiere_premiere';

        return (int) $this->em->getConnection()->fetchOne("SELECT stock_actuel FROM {$table} WHERE id = ?", [$produit->getId()]);
    }

    private function assertInvariant(Emplacement $emplacement, Article|MatierePremiere $produit, int $attendu): void
    {
        $this->assertSame($attendu, $this->stock->getStock($emplacement, $produit), 'Stock courant.');
        $this->assertSame($attendu, $this->sommeMouvements($emplacement, $produit), 'Somme des mouvements.');

        if ($emplacement->estDepotPrincipal()) {
            $this->assertSame($attendu, $this->champHistoriqueEnBase($produit), 'Champ stockActuel en base.');
            $this->assertSame($attendu, $produit->getStockActuel(), 'Champ stockActuel sur l\'objet.');
        }
    }

    // ------------------------------------------------------ enregistrerMouvement

    public function testUnMouvementMetAJourLeStockCourantEtLeChampHistorique(): void
    {
        $farine = $this->matiere();

        $mouvement = $this->stock->enregistrerMouvement(
            $farine,
            $this->depot,
            25000,
            TypeMouvementStock::AJUSTEMENT,
            'creation',
            $farine->getId(),
            'Stock de départ',
        );

        $this->assertInvariant($this->depot, $farine, 25000);

        $this->em->clear();
        $relu = $this->em->find(MouvementStock::class, $mouvement->getId());
        $this->assertSame(TypeMouvementStock::AJUSTEMENT, $relu->getType());
        $this->assertSame(25000, $relu->getQuantite());
        $this->assertSame('creation', $relu->getDocumentType());
        $this->assertSame($farine->getId(), $relu->getDocumentId());
        $this->assertSame('Stock de départ', $relu->getMotif());
        $this->assertTrue($relu->getEmplacement()->estDepotPrincipal());
        $this->assertNull($relu->getUtilisateur(), 'Personne de connecté : pas d\'auteur.');
    }

    /**
     * Un produit dont le stock a été posé avant la bascule, sans mouvement : son
     * premier mouvement reprend l'existant par une écriture d'ouverture, sans quoi
     * le stock courant partirait de zéro et la somme des mouvements mentirait.
     */
    public function testLePremierMouvementReprendLeStockExistant(): void
    {
        $farine = $this->matiere('Farine', 100000);
        $this->assertSame(100000, $this->stock->getStock($this->depot, $farine), 'Avant tout mouvement, le stock est celui du champ historique.');

        $this->stock->enregistrerMouvement($farine, $this->depot, -2000, TypeMouvementStock::PERTE, 'perte', 1);

        $this->assertInvariant($this->depot, $farine, 98000);

        $reprise = $this->em->getRepository(MouvementStock::class)->findOneBy(['documentType' => StockManager::DOCUMENT_REPRISE]);
        $this->assertInstanceOf(MouvementStock::class, $reprise);
        $this->assertSame(TypeMouvementStock::AJUSTEMENT, $reprise->getType());
        $this->assertSame(100000, $reprise->getQuantite());
        $this->assertSame(2, $this->nombreMouvements(), 'La reprise et la perte, rien d\'autre.');
    }

    /** Hors du dépôt principal, il n'y a pas d'existant à reprendre. */
    public function testUnStandPartDeZeroSansReprise(): void
    {
        $coca = $this->article();
        $stand = $this->stand();

        $this->assertSame(0, $this->stock->getStock($stand, $coca));

        $this->expectException(StockInsuffisantException::class);
        $this->stock->enregistrerMouvement($coca, $stand, -1000, TypeMouvementStock::VENTE_STAND);
    }

    // ------------------------------------------------------------ Stock négatif

    public function testUnMouvementQuiRendraitLeStockNegatifEstRefuseSansRienEcrire(): void
    {
        $farine = $this->matiere();
        $this->approvisionner($farine, 1000);
        $avant = $this->nombreMouvements();

        try {
            $this->stock->enregistrerMouvement($farine, $this->depot, -1500, TypeMouvementStock::PERTE);
            $this->fail('Une perte au-delà du stock aurait dû être refusée.');
        } catch (StockInsuffisantException $e) {
            $this->assertSame(1000, $e->disponible);
            $this->assertSame(1500, $e->demande);
            $this->assertSame('Stock insuffisant pour « Farine » (Dépôt principal) : 1 kg disponible, 1,5 kg demandé.', $e->getMessage());
            $this->assertInstanceOf(\DomainException::class, $e, 'Les contrôleurs attrapent déjà les refus métier en DomainException.');
        }

        $this->assertSame($avant, $this->nombreMouvements(), 'Aucun mouvement écrit.');
        $this->assertInvariant($this->depot, $farine, 1000);

        // Le refus tombe avant l'unité de travail : l'EntityManager reste utilisable.
        $this->assertTrue($this->em->isOpen());
        $this->stock->enregistrerMouvement($farine, $this->depot, -1000, TypeMouvementStock::PERTE);
        $this->assertInvariant($this->depot, $farine, 0);
    }

    /**
     * Toute la sortie est refusée, pas seulement le premier type : dotation, perte,
     * ajustement, vente de stand. Seule la caisse passe.
     */
    public function testTousLesTypesSortantsSontRefusesSaufLaVenteCaisse(): void
    {
        $farine = $this->matiere();

        foreach ([TypeMouvementStock::PERTE, TypeMouvementStock::AJUSTEMENT, TypeMouvementStock::VENTE_STAND] as $type) {
            try {
                $this->stock->enregistrerMouvement($farine, $this->depot, -1, $type);
                $this->fail($type->value.' aurait dû être refusé sur un stock vide.');
            } catch (StockInsuffisantException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, $this->nombreMouvements());
    }

    /** « Ne jamais bloquer une vente » : la caisse laisse le stock passer sous zéro. */
    public function testLaVenteCaissePeutRendreLeStockNegatif(): void
    {
        $farine = $this->matiere();
        $this->approvisionner($farine, 1000);

        $this->stock->enregistrerMouvement($farine, $this->depot, -1500, TypeMouvementStock::VENTE_CAISSE, 'vente', 42);

        $this->assertInvariant($this->depot, $farine, -500);
    }

    /**
     * La règle refuse un mouvement qui **rend** le stock négatif. Une entrée qui le
     * laisse négatif — mais moins — le corrige, elle ne doit pas être bloquée.
     */
    public function testUneEntreeSurUnStockDejaNegatifEstAcceptee(): void
    {
        $farine = $this->matiere();
        $this->stock->enregistrerMouvement($farine, $this->depot, -1500, TypeMouvementStock::VENTE_CAISSE);

        $this->approvisionner($farine, 200);

        $this->assertInvariant($this->depot, $farine, -1300);
    }

    // ------------------------------------------------------------------- Lots

    public function testUnLotEstToutOuRien(): void
    {
        $farine = $this->matiere('Farine');
        $sucre = $this->matiere('Sucre');
        $this->approvisionner($farine, 5000);
        $this->approvisionner($sucre, 1000);
        $avant = $this->nombreMouvements();

        try {
            $this->stock->enregistrerMouvements([
                new DemandeMouvementStock($farine, $this->depot, -1000, TypeMouvementStock::AJUSTEMENT, 'inventaire', 1),
                new DemandeMouvementStock($sucre, $this->depot, -2000, TypeMouvementStock::AJUSTEMENT, 'inventaire', 1),
            ]);
            $this->fail('Le sucre manque : tout le lot aurait dû être refusé.');
        } catch (StockInsuffisantException $e) {
            $this->assertSame('Sucre', $e->produit);
        }

        $this->assertSame($avant, $this->nombreMouvements());
        $this->assertInvariant($this->depot, $farine, 5000);
        $this->assertInvariant($this->depot, $sucre, 1000);
    }

    /** Deux sorties du même produit dans un lot : c'est leur cumul qui compte. */
    public function testUnLotControleLeCumulDUnMemeProduit(): void
    {
        $farine = $this->matiere();
        $this->approvisionner($farine, 1000);

        $this->expectException(StockInsuffisantException::class);
        $this->stock->enregistrerMouvements([
            new DemandeMouvementStock($farine, $this->depot, -600, TypeMouvementStock::PERTE),
            new DemandeMouvementStock($farine, $this->depot, -600, TypeMouvementStock::PERTE),
        ]);
    }

    public function testUnLotAccepteAppliqueChaqueMouvementDansLOrdre(): void
    {
        $farine = $this->matiere('Farine');
        $sucre = $this->matiere('Sucre');
        $this->approvisionner($farine, 1000);

        $mouvements = $this->stock->enregistrerMouvements([
            new DemandeMouvementStock($farine, $this->depot, -600, TypeMouvementStock::VENTE_CAISSE, 'vente', 7),
            new DemandeMouvementStock($sucre, $this->depot, 300, TypeMouvementStock::AJUSTEMENT),
            new DemandeMouvementStock($farine, $this->depot, -400, TypeMouvementStock::PERTE),
        ]);

        $this->assertCount(3, $mouvements);
        $this->assertSame([-600, 300, -400], array_map(static fn (MouvementStock $m): int => $m->getQuantite(), $mouvements));
        $this->assertInvariant($this->depot, $farine, 0);
        $this->assertInvariant($this->depot, $sucre, 300);
    }

    // -------------------------------------------------------------- transferer

    public function testUneDotationEcritDeuxMouvementsEtDeplaceLeStock(): void
    {
        $coca = $this->article();
        $stand = $this->stand();
        $this->approvisionner($coca, 24000);

        [$sortie, $entree] = $this->stock->transferer($coca, $this->depot, $stand, 10000, TypeMouvementStock::DOTATION, 'dotation', 3, 'Dotation du matin');

        $this->assertSame(-10000, $sortie->getQuantite());
        $this->assertSame($this->depot->getId(), $sortie->getEmplacement()->getId());
        $this->assertSame(10000, $entree->getQuantite());
        $this->assertSame($stand->getId(), $entree->getEmplacement()->getId());
        foreach ([$sortie, $entree] as $mouvement) {
            $this->assertSame(TypeMouvementStock::DOTATION, $mouvement->getType());
            $this->assertSame('dotation', $mouvement->getDocumentType());
            $this->assertSame(3, $mouvement->getDocumentId());
        }

        $this->assertInvariant($this->depot, $coca, 14000);
        $this->assertInvariant($stand, $coca, 10000);
        $this->assertSame(14000, $coca->getStockActuel(), 'Le champ historique suit le dépôt, pas le stand.');
    }

    /** Le retour d'invendus refait le chemin inverse. */
    public function testUnRetourRameneLesInvendusAuDepot(): void
    {
        $coca = $this->article();
        $stand = $this->stand();
        $this->approvisionner($coca, 10000);
        $this->stock->transferer($coca, $this->depot, $stand, 10000, TypeMouvementStock::DOTATION);

        $this->stock->transferer($coca, $stand, $this->depot, 4000, TypeMouvementStock::RETOUR);

        $this->assertInvariant($this->depot, $coca, 4000);
        $this->assertInvariant($stand, $coca, 6000);
    }

    /**
     * La source ne peut pas passer sous zéro, et le refus ne laisse rien derrière
     * lui : ni la sortie, ni l'entrée, ni la ligne de stock créée pour le stand.
     */
    public function testUnTransfertAuDelaDuStockDeLaSourceNeLaisseAucuneTrace(): void
    {
        $coca = $this->article();
        $stand = $this->stand();
        $this->approvisionner($coca, 24000);
        $avant = $this->nombreMouvements();

        try {
            $this->stock->transferer($coca, $this->depot, $stand, 30000, TypeMouvementStock::DOTATION);
            $this->fail('On ne dote pas un stand de ce qu\'on n\'a pas.');
        } catch (StockInsuffisantException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame($avant, $this->nombreMouvements());
        $this->assertInvariant($this->depot, $coca, 24000);
        $this->assertSame(0, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM stock_courant WHERE emplacement_id = ?',
            [$stand->getId()],
        ), 'La ligne de stock du stand a été annulée avec le reste.');
    }

    public function testLeSensDuTransfertEstImpose(): void
    {
        $coca = $this->article();
        $stand = $this->stand();
        $this->approvisionner($coca, 5000);

        foreach ([
            [$stand, $this->depot, TypeMouvementStock::DOTATION],   // une dotation part du dépôt
            [$this->depot, $stand, TypeMouvementStock::RETOUR],     // un retour y revient
            [$this->depot, $this->depot, TypeMouvementStock::REAPPRO],
        ] as [$source, $destination, $type]) {
            try {
                $this->stock->transferer($coca, $source, $destination, 1000, $type);
                $this->fail($type->value.' : sens invalide accepté.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertInvariant($this->depot, $coca, 5000);
    }

    public function testUnTransfertExigeUneQuantitePositiveEtUnTypeDeTransfert(): void
    {
        $coca = $this->article();
        $stand = $this->stand();

        foreach ([[0, TypeMouvementStock::DOTATION], [-5, TypeMouvementStock::DOTATION], [1000, TypeMouvementStock::PERTE]] as [$quantite, $type]) {
            try {
                $this->stock->transferer($coca, $this->depot, $stand, $quantite, $type);
                $this->fail('Transfert invalide accepté.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** Seul, un mouvement de transfert ferait apparaître de la marchandise. */
    public function testUnTypeDeTransfertNeSEcritPasEnMouvementIsole(): void
    {
        $coca = $this->article();

        $this->expectException(\InvalidArgumentException::class);
        $this->stock->enregistrerMouvement($coca, $this->stand(), 1000, TypeMouvementStock::DOTATION);
    }

    /**
     * Le dépôt ne tient pas de stock d'un article non suivi (pain fabriqué) : la
     * dotation crédite le stand sans rien retirer au dépôt, et sans le refuser.
     */
    public function testUnArticleNonSuiviCrediteLeStandSansDebiterLeDepot(): void
    {
        $baguette = (new Article('Baguette', 15000, 'pièce'))->setSuiviStock(false);
        $this->em->persist($baguette);
        $this->em->flush();
        $stand = $this->stand();

        $this->assertFalse($this->stock->suitLeStock($this->depot, $baguette));
        $this->assertTrue($this->stock->suitLeStock($stand, $baguette));

        $mouvements = $this->stock->transferer($baguette, $this->depot, $stand, 40000, TypeMouvementStock::DOTATION);

        $this->assertCount(1, $mouvements, 'Une entrée au stand, pas de sortie du dépôt.');
        $this->assertSame($stand->getId(), $mouvements[0]->getEmplacement()->getId());
        $this->assertInvariant($stand, $baguette, 40000);
        $this->assertSame(0, $this->sommeMouvements($this->depot, $baguette));
        $this->assertSame([$baguette->getId() => null], $this->stock->stocksArticles($this->depot, [$baguette]), '« Non suivi » n\'est pas « zéro ».');
    }

    public function testContrepasserInverseDesMouvementsEtResteSoumisAuxControles(): void
    {
        $coca = $this->article();
        $stand = $this->stand();
        $this->approvisionner($coca, 10000);
        $origines = $this->stock->transferer($coca, $this->depot, $stand, 6000, TypeMouvementStock::DOTATION, 'bon_dotation', 1);

        // Le stand a vendu 2 : contre-passer la dotation le mettrait à −2.
        $this->stock->enregistrerMouvement($coca, $stand, -2000, TypeMouvementStock::VENTE_STAND);
        try {
            $this->stock->contrepasser($origines, 'bon_dotation', 1, 'Annulation');
            $this->fail('Contre-passation acceptée malgré le stock vendu.');
        } catch (StockInsuffisantException) {
            $this->addToAssertionCount(1);
        }
        $this->assertInvariant($this->depot, $coca, 4000);

        // Le vendeur rapporte les 2 : la contre-passation passe, même type, signes opposés.
        $this->approvisionner($coca, 2000, $stand);
        $inverses = $this->stock->contrepasser($origines, 'bon_dotation', 1, 'Annulation');

        $this->assertSame([6000, -6000], array_map(static fn (MouvementStock $m): int => $m->getQuantite(), $inverses));
        $this->assertSame([TypeMouvementStock::DOTATION, TypeMouvementStock::DOTATION], array_map(static fn (MouvementStock $m) => $m->getType(), $inverses));
        $this->assertInvariant($this->depot, $coca, 10000);
        $this->assertInvariant($stand, $coca, 0);
        $this->assertStockCourantBatch($coca);
    }

    private function assertStockCourantBatch(Article $coca): void
    {
        $this->assertSame([$coca->getId() => 10000], $this->stock->stocksArticles($this->depot, [$coca]), 'Lecture groupée alignée sur getStock().');
    }

    // ----------------------------------------------------------- Validations

    public function testUnMouvementNulOuSurUnProduitNonEnregistreEstRefuse(): void
    {
        $farine = $this->matiere();

        try {
            $this->stock->enregistrerMouvement($farine, $this->depot, 0, TypeMouvementStock::AJUSTEMENT);
            $this->fail('Mouvement nul accepté.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->stock->enregistrerMouvement(new MatierePremiere('Fantôme', 'kg'), $this->depot, 1000, TypeMouvementStock::AJUSTEMENT);
    }

    /** Un stand fermé ne reçoit plus rien ; la caisse, elle, ne se bloque sur rien. */
    public function testUnEmplacementDesactiveRefuseLesMouvementsSaufLaCaisse(): void
    {
        $farine = $this->matiere();
        $stand = $this->stand('STAND-X', actif: false);

        try {
            $this->stock->enregistrerMouvement($farine, $stand, 1000, TypeMouvementStock::AJUSTEMENT);
            $this->fail('Mouvement accepté sur un emplacement désactivé.');
        } catch (\DomainException $e) {
            $this->assertNotInstanceOf(StockInsuffisantException::class, $e);
        }

        $this->stock->enregistrerMouvement($farine, $stand, -1000, TypeMouvementStock::VENTE_CAISSE);
        $this->assertInvariant($stand, $farine, -1000);
    }

    // --------------------------------------------------------- recalculerStock

    public function testRecalculerStockRetablitLeStockCourantEtLeChampHistorique(): void
    {
        $farine = $this->matiere('Farine');
        $sucre = $this->matiere('Sucre');
        $this->approvisionner($farine, 5000);
        $this->approvisionner($sucre, 2000);

        // Corruption volontaire, hors StockManager : ce que l'outil doit réparer.
        $connexion = $this->em->getConnection();
        $connexion->executeStatement('UPDATE stock_courant SET quantite = 9999 WHERE matiere_premiere_id = ?', [$farine->getId()]);
        $connexion->executeStatement('UPDATE matiere_premiere SET stock_actuel = 123 WHERE id = ?', [$sucre->getId()]);

        $corrections = $this->stock->recalculerStock($this->depot);

        $parProduit = array_column($corrections, null, 'produit');
        $this->assertCount(2, $corrections);
        // Farine : seul le stock courant était faux. Sucre : seul le champ historique.
        $this->assertSame(['produit' => 'Farine', 'avant' => 9999, 'apres' => 5000, 'champHistoriqueAvant' => null], $parProduit['Farine']);
        $this->assertSame(['produit' => 'Sucre', 'avant' => 2000, 'apres' => 2000, 'champHistoriqueAvant' => 123], $parProduit['Sucre']);

        $this->assertInvariant($this->depot, $farine, 5000);
        $this->assertInvariant($this->depot, $sucre, 2000);

        $this->assertSame([], $this->stock->recalculerStock($this->depot), 'Rien à corriger une seconde fois.');
    }

    public function testRecalculerStockDUnStandNeTouchePasAuChampHistorique(): void
    {
        $coca = $this->article();
        $stand = $this->stand();
        $this->approvisionner($coca, 8000);
        $this->stock->transferer($coca, $this->depot, $stand, 3000, TypeMouvementStock::DOTATION);

        $this->em->getConnection()->executeStatement('UPDATE stock_courant SET quantite = 0 WHERE emplacement_id = ?', [$stand->getId()]);

        $corrections = $this->stock->recalculerStock($stand);

        $this->assertSame([['produit' => 'Coca', 'avant' => 0, 'apres' => 3000, 'champHistoriqueAvant' => null]], $corrections);
        $this->assertInvariant($stand, $coca, 3000);
        $this->assertSame(5000, $this->champHistoriqueEnBase($coca), 'Le champ historique reste celui du dépôt.');
    }
}
