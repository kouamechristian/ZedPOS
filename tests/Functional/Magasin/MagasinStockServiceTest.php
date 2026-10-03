<?php

namespace App\Tests\Functional\Magasin;

use App\Entity\Article;
use App\Entity\FicheTechnique;
use App\Entity\LigneFicheTechnique;
use App\Entity\LigneVente;
use App\Entity\Magasin\MagasinEmplacement;
use App\Entity\Magasin\MagasinMouvement;
use App\Entity\Magasin\MagasinProduit;
use App\Entity\MatierePremiere;
use App\Entity\Reglement;
use App\Entity\SessionCaisse;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Enum\Magasin\CategorieProduitMagasin;
use App\Enum\Magasin\TypeMouvementMagasin;
use App\Enum\ModeReglement;
use App\Enum\ModeVente;
use App\Service\Magasin\DemandeMouvementMagasin;
use App\Service\Magasin\MagasinStockService;
use App\Service\Magasin\StockMagasinInsuffisantException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Le service de stock du magasin : seul point d'écriture, refus du stock
 * négatif avant toute écriture, contrepassation, invariant « stock = somme des
 * mouvements » — et indépendance totale vis-à-vis des ventes.
 */
class MagasinStockServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MagasinStockService $stock;
    private Utilisateur $gerante;
    private MagasinProduit $farine;
    private MagasinEmplacement $reserve;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->stock = static::getContainer()->get(MagasinStockService::class);

        $connexion = $this->em->getConnection();
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['magasin_ligne_inventaire', 'magasin_inventaire', 'magasin_ligne_reception', 'magasin_reception', 'magasin_mouvement', 'magasin_stock', 'magasin_produit', 'journal_audit', 'utilisateur'] as $table) {
            $connexion->executeStatement('DELETE FROM '.$table);
        }
        $connexion->executeStatement("DELETE FROM magasin_emplacement WHERE code <> 'RESERVE'");
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $this->gerante = (new Utilisateur('mariam@test.ci', 'Mariam'))->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        $this->farine = (new MagasinProduit('Farine de blé', CategorieProduitMagasin::MATIERE, 'kg'))->setUniteAchat('sac')->setContenanceAchat(50000);
        $this->em->persist($this->gerante);
        $this->em->persist($this->farine);
        $this->em->flush();

        $this->reserve = $this->stock->reserveParDefaut();
    }

    private function entree(int $millimes, ?MagasinEmplacement $emplacement = null, int $document = 1): array
    {
        return $this->stock->appliquer([new DemandeMouvementMagasin($this->farine, $emplacement ?? $this->reserve, $millimes, TypeMouvementMagasin::ENTREE_RECEPTION, 'reception', $document, 'Test')], $this->gerante);
    }

    public function testLaReserveParDefautExisteToujours(): void
    {
        $this->assertSame(MagasinEmplacement::CODE_RESERVE, $this->reserve->getCode());
        $this->assertSame('Réserve principale', $this->reserve->getLibelle());
        $this->assertSame($this->reserve->getId(), $this->stock->reserveParDefaut()->getId());
    }

    public function testUneEntreeEcritLeMouvementEtLeStock(): void
    {
        [$mouvement] = $this->entree(1000000);

        $this->assertSame(1000000, $this->stock->stock($this->farine, $this->reserve), '20 sacs = 1 000 kg.');
        $this->assertSame(1000000, $this->stock->stockTotal($this->farine));
        $this->assertSame(TypeMouvementMagasin::ENTREE_RECEPTION, $mouvement->getType());
        $this->assertSame($this->gerante->getId(), $mouvement->getAuteur()?->getId());
        $this->assertSame([], $this->stock->verifier());
    }

    /** Une sortie au-delà du stock est refusée avant toute écriture, avec un message clair. */
    public function testUnStockNegatifEstRefuseSansRienEcrire(): void
    {
        $this->entree(900000);

        try {
            $this->stock->appliquer([new DemandeMouvementMagasin($this->farine, $this->reserve, -1500000, TypeMouvementMagasin::SORTIE, 'sortie', 1)], $this->gerante);
            $this->fail('La sortie aurait dû être refusée.');
        } catch (StockMagasinInsuffisantException $e) {
            $this->assertSame('Stock insuffisant pour « Farine de blé » (Réserve principale) : 18 sacs (900 kg) disponible, 30 sacs (1 500 kg) demandé.', $e->getMessage());
        }

        $this->assertSame(900000, $this->stock->stock($this->farine, $this->reserve));
        $this->assertSame(1, $this->em->getRepository(MagasinMouvement::class)->count([]), 'Aucun mouvement de la sortie refusée.');
        $this->assertTrue($this->em->isOpen(), 'L\'EntityManager reste utilisable : l\'écran peut se réafficher.');
    }

    /** Le contrôle porte sur le cumul d'un lot : deux sorties qui ensemble dépassent sont refusées. */
    public function testLeControleSeFaitSurLeCumulDuLot(): void
    {
        $this->entree(100000);

        $this->expectException(StockMagasinInsuffisantException::class);
        $this->stock->appliquer([
            new DemandeMouvementMagasin($this->farine, $this->reserve, -60000, TypeMouvementMagasin::SORTIE, 'sortie', 1),
            new DemandeMouvementMagasin($this->farine, $this->reserve, -60000, TypeMouvementMagasin::SORTIE, 'sortie', 1),
        ], $this->gerante);
    }

    public function testLeStockSeTientParEmplacement(): void
    {
        $froid = new MagasinEmplacement('FROID', 'Chambre froide');
        $this->em->persist($froid);
        $this->em->flush();

        $this->entree(500000);
        $this->entree(200000, $froid);

        $this->assertSame(500000, $this->stock->stock($this->farine, $this->reserve));
        $this->assertSame(200000, $this->stock->stock($this->farine, $froid));
        $this->assertSame(700000, $this->stock->stockTotal($this->farine));

        // La chambre froide n'a que 200 kg : en sortir 300 est refusé, même si le total suffit.
        $this->expectException(StockMagasinInsuffisantException::class);
        $this->stock->appliquer([new DemandeMouvementMagasin($this->farine, $froid, -300000, TypeMouvementMagasin::SORTIE, 'sortie', 1)], $this->gerante);
    }

    public function testUneContrepassationAnnuleSansEffacer(): void
    {
        $origine = $this->entree(1000000, null, 7);
        $annulation = $this->stock->contrepasser($origine, 'reception', 7, 'Annulation', $this->gerante);

        $this->assertSame(0, $this->stock->stock($this->farine, $this->reserve));
        $this->assertSame(TypeMouvementMagasin::ANNULATION, $annulation[0]->getType());
        $this->assertSame(-1000000, $annulation[0]->getQuantite());
        $this->assertSame(2, $this->em->getRepository(MagasinMouvement::class)->count([]), 'Le mouvement d\'origine reste.');
    }

    /** Annuler une entrée dont la marchandise est déjà sortie laisserait le stock négatif : refusé. */
    public function testUneContrepassationQuiRendraitLeStockNegatifEstRefusee(): void
    {
        $origine = $this->entree(1000000);
        $this->stock->appliquer([new DemandeMouvementMagasin($this->farine, $this->reserve, -150000, TypeMouvementMagasin::SORTIE, 'sortie', 1)], $this->gerante);

        $this->expectException(StockMagasinInsuffisantException::class);
        $this->stock->contrepasser($origine, 'reception', 1, 'Annulation', $this->gerante);
    }

    public function testUnEmplacementDesactiveNeRecoitPlusRien(): void
    {
        $etagere = (new MagasinEmplacement('ETAGERE-A', 'Étagère A'))->setActif(false);
        $this->em->persist($etagere);
        $this->em->flush();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('désactivé');
        $this->entree(1000, $etagere);
    }

    public function testUnMouvementNulEstRefuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->entree(0);
    }

    /** La commande signale un stock modifié hors du service, et ne corrige rien. */
    public function testLaCommandeDeVerificationDetecteUnEcart(): void
    {
        $this->entree(1000000);
        $commande = new CommandTester((new Application(static::$kernel))->find('magasin:stock:verifier'));

        $this->assertSame(0, $commande->execute([]));
        $this->assertStringContainsString('cohérent', $commande->getDisplay());

        $this->em->getConnection()->executeStatement('UPDATE magasin_stock SET quantite = 999 WHERE produit_id = ?', [$this->farine->getId()]);

        $this->assertSame(1, $commande->execute([]));
        $this->assertStringContainsString('Farine de blé', $commande->getDisplay());
        $this->assertSame([['produit' => 'Farine de blé', 'emplacement' => 'Réserve principale', 'stock' => 999, 'mouvements' => 1000000]], $this->stock->verifier());
    }

    /**
     * Critère d'acceptation : une vente à la caisse, avec ou sans fiche technique,
     * ne change rien au stock du magasin.
     */
    public function testUneVenteNeTouchePasAuMagasin(): void
    {
        $this->entree(1000000);

        $connexion = $this->em->getConnection();
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['ligne_vente', 'reglement', 'vente', 'session_caisse', 'mouvement_stock', 'stock_courant', 'ligne_fiche_technique', 'fiche_technique', 'article', 'matiere_premiere'] as $table) {
            $connexion->executeStatement('DELETE FROM '.$table);
        }
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        // Un article avec fiche technique (farine de la boutique) et un article sans.
        $farineBoutique = (new MatierePremiere('Farine de blé', 'kg'))->setStockActuel(100000);
        $pain = new Article('Pain', 15000, 'pièce');
        $fiche = new FicheTechnique($pain);
        new LigneFicheTechnique($fiche, $farineBoutique, 250, 0);
        $croissant = new Article('Croissant', 30000, 'pièce');
        $session = new SessionCaisse($this->gerante, 0);
        foreach ([$farineBoutique, $pain, $fiche, $croissant, $session] as $entite) {
            $this->em->persist($entite);
        }
        $this->em->flush();

        $vente = new Vente($session, ModeVente::BOULANGERIE, 'VT-00001', 45000, 0, 45000);
        new LigneVente($vente, $pain, 1000, 15000);
        new LigneVente($vente, $croissant, 1000, 30000);
        new Reglement($vente, ModeReglement::ESPECES, 45000);
        $this->em->persist($vente);
        $this->em->flush();

        $this->assertSame(1000000, $this->stock->stock($this->farine, $this->reserve), 'Le magasin n\'a pas bougé.');
        $this->assertSame(1, $this->em->getRepository(MagasinMouvement::class)->count([]));
        $this->assertSame([], $this->stock->verifier());
    }
}
