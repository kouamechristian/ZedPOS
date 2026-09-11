<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\BonDotation;
use App\Entity\Emplacement;
use App\Entity\JournalAudit;
use App\Entity\MouvementStock;
use App\Entity\Utilisateur;
use App\Entity\Vendeur;
use App\Enum\ActionAudit;
use App\Enum\ModeRemuneration;
use App\Enum\StatutBonDotation;
use App\Enum\TypeEmplacement;
use App\Enum\TypeMouvementStock;
use App\Repository\BonDotationRepository;
use App\Service\ArreteService;
use App\Service\BonDotationService;
use App\Service\StockInsuffisantException;
use App\Service\StockManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Bons de dotation : numérotation, validation (transfert, prix figés, immuabilité),
 * annulation (contre-passation, bornes) et trace d'audit.
 *
 * Tests de service contre la base de test : les garanties portent sur une
 * transaction qui aboutit ou ne laisse rien, ce qui ne se vérifie qu'en base.
 */
class BonDotationServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private BonDotationService $service;
    private StockManager $stock;
    private Utilisateur $gerante;
    private Vendeur $awa;
    private Emplacement $depot;
    private Emplacement $stand;
    /** Boisson suivie en stock : vrai transfert dépôt → stand. */
    private Article $coca;
    /** Pain fabriqué, non suivi : crédite le stand seul. */
    private Article $baguette;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(BonDotationService::class);
        $this->stock = static::getContainer()->get(StockManager::class);

        $connexion = $this->em->getConnection();
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['remboursement_dette', 'dette_vendeur', 'ligne_retour', 'bon_retour', 'ligne_dotation', 'bon_dotation', 'arrete', 'vendeur', 'ligne_inventaire', 'inventaire', 'ligne_fiche_technique', 'fiche_technique', 'ligne_vente', 'reglement', 'vente', 'mouvement_caisse', 'session_caisse', 'mouvement_stock', 'stock_courant', 'perte', 'article', 'matiere_premiere', 'famille_produit', 'journal_audit', 'notification', 'utilisateur'] as $table) {
            $connexion->executeStatement('DELETE FROM '.$table);
        }
        $connexion->executeStatement("DELETE FROM emplacement WHERE code <> 'DEPOT'");
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $this->gerante = (new Utilisateur('mariam@test.ci', 'Mariam'))->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        $this->awa = (new Vendeur('Awa'))->setTelephone('07 00 00 00 01');
        $this->stand = (new Emplacement('STAND-GARE', 'Stand de la gare', TypeEmplacement::STAND))->setVendeurHabituel($this->awa);

        $this->coca = (new Article('Coca', 50000, 'bouteille'))->setSuiviStock(true)->setPrixCession(42000);
        $this->baguette = (new Article('Baguette', 15000, 'pièce'))->setPrixCession(12500);

        foreach ([$this->gerante, $this->awa, $this->stand, $this->coca, $this->baguette] as $entite) {
            $this->em->persist($entite);
        }
        $this->em->flush();

        $this->depot = $this->stock->depotPrincipal();
        $this->stock->enregistrerMouvement($this->coca, $this->depot, 24000, TypeMouvementStock::AJUSTEMENT, motif: 'Livraison');
    }

    /** @param array<int, int> $unites unités par article */
    private function bon(array $unites = [], string $date = '2026-09-11'): BonDotation
    {
        $unites = $unites ?: [$this->coca->getId() => 10, $this->baguette->getId() => 40];

        return $this->service->creer($this->stand, $this->awa, new \DateTimeImmutable($date), $unites, $this->gerante);
    }

    private function mouvementsDuBon(BonDotation $bon): array
    {
        return $this->em->getRepository(MouvementStock::class)->findBy(['documentType' => BonDotationService::DOCUMENT, 'documentId' => $bon->getId()], ['id' => 'ASC']);
    }

    /** @return list<JournalAudit> */
    private function audit(ActionAudit $action): array
    {
        return $this->em->getRepository(JournalAudit::class)->findBy(['action' => $action->value], ['id' => 'ASC']);
    }

    // ------------------------------------------------------------- Création

    public function testUnBonNaitBrouillonNumeroteDuJourSansToucherAuStock(): void
    {
        $bon = $this->bon();

        $this->assertSame('DOT-20260911-001', $bon->getNumero());
        $this->assertSame(StatutBonDotation::BROUILLON, $bon->getStatut());
        $this->assertSame('2026-09-11', $bon->getDateDotation()->format('Y-m-d'));
        $this->assertCount(2, $bon->getLignes());
        $this->assertSame(10000, $bon->getLignes()->first()->getQuantite(), 'Unités saisies, millièmes stockés.');
        $this->assertNull($bon->getLignes()->first()->getPrixCessionUnitaire(), 'Les prix ne sont figés qu\'à la validation.');
        $this->assertSame([], $this->mouvementsDuBon($bon));
    }

    /** Réapprovisionnement : plusieurs bons le même jour sur le même stand. */
    public function testPlusieursBonsLeMemeJourSurLeMemeStand(): void
    {
        $premier = $this->bon();
        $this->service->valider($premier, $this->gerante);

        $second = $this->bon([$this->baguette->getId() => 20]);
        $this->service->valider($second, $this->gerante);

        $this->assertSame('DOT-20260911-002', $second->getNumero());
        $this->assertSame(60000, $this->stock->getStock($this->stand, $this->baguette));
        $this->assertSame('DOT-20260912-001', $this->bon([], '2026-09-12')->getNumero(), 'La séquence repart chaque jour.');
    }

    public function testLaSaisieRefuseLesQuantitesIllisiblesEtIgnoreLesCasesVides(): void
    {
        $lignes = $this->service->lireQuantites([$this->coca->getId() => '', $this->baguette->getId() => '0']);
        $this->assertSame([], $lignes);

        foreach (['1,5', '-3', 'abc', '100000'] as $saisie) {
            try {
                $this->service->lireQuantites([$this->coca->getId() => $saisie]);
                $this->fail('Saisie acceptée : '.$saisie);
            } catch (\DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->baguette->setActif(false);
        $this->em->flush();
        $this->expectException(\DomainException::class);
        $this->service->lireQuantites([$this->baguette->getId() => '5']);
    }

    // ------------------------------------------------------------ Validation

    public function testValiderTransfereDuDepotAuStandEtFigeLesPrix(): void
    {
        $bon = $this->bon();
        $this->service->valider($bon, $this->gerante);

        $this->assertSame(StatutBonDotation::VALIDE, $bon->getStatut());
        $this->assertNotNull($bon->getValideAt());

        // Coca, suivi : sort du dépôt, entre au stand.
        $this->assertSame(14000, $this->stock->getStock($this->depot, $this->coca));
        $this->assertSame(10000, $this->stock->getStock($this->stand, $this->coca));
        // Baguette, fabriquée : crédite le stand, le dépôt n'en tient pas de stock.
        $this->assertSame(40000, $this->stock->getStock($this->stand, $this->baguette));

        $mouvements = $this->mouvementsDuBon($bon);
        $this->assertCount(3, $mouvements, 'Coca : sortie + entrée. Baguette : entrée seule.');
        foreach ($mouvements as $mouvement) {
            $this->assertSame(TypeMouvementStock::DOTATION, $mouvement->getType());
            $this->assertSame($this->gerante->getId(), $mouvement->getUtilisateur()?->getId());
        }
        $this->assertCount(0, array_filter($mouvements, fn (MouvementStock $m): bool => $m->getProduit() === $this->baguette && $m->getEmplacement()->estDepotPrincipal()));

        // Prix copiés à l'instant de la validation…
        [$ligneCoca, $ligneBaguette] = $bon->getLignes()->toArray();
        $this->assertSame([50000, 42000], [$ligneCoca->getPrixVenteUnitaire(), $ligneCoca->getPrixCessionUnitaire()]);
        $this->assertSame(10 * 42000 + 40 * 12500, $bon->totalCession());
        $this->assertSame(10 * 50000 + 40 * 15000, $bon->totalVente());

        // … et plus touchés ensuite.
        $this->coca->setPrixCession(99900)->setPrixVenteTtc(99900);
        $this->em->flush();
        $this->em->clear();
        $relu = static::getContainer()->get(BonDotationRepository::class)->avecLignes($bon->getId());
        $this->assertSame(42000, $relu->getLignes()->first()->getPrixCessionUnitaire());
        $this->assertSame(10 * 42000 + 40 * 12500, $relu->totalCession());
    }

    public function testUnBonValideEstImmuable(): void
    {
        $bon = $this->bon();
        $this->service->valider($bon, $this->gerante);

        foreach ([
            fn () => $bon->definirQuantites([[$this->coca, 1000]]),
            fn () => $bon->changerDate(new \DateTimeImmutable('2026-09-01')),
            fn () => $bon->changerVendeur($this->awa),
            fn () => $this->service->modifier($bon, $this->awa, new \DateTimeImmutable(), [$this->coca->getId() => 1]),
            fn () => $this->service->valider($bon, $this->gerante),
        ] as $geste) {
            try {
                $geste();
                $this->fail('Un bon validé a été modifié.');
            } catch (\DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** Le dépôt n'a que 24 cocas : rien ne part, le bon reste un brouillon, aucune trace. */
    public function testUneValidationAuDelaDuStockDepotNeLaisseRien(): void
    {
        $bon = $this->bon([$this->coca->getId() => 30, $this->baguette->getId() => 40]);

        try {
            $this->service->valider($bon, $this->gerante);
            $this->fail('Validation acceptée au-delà du stock dépôt.');
        } catch (StockInsuffisantException $e) {
            $this->assertSame('Coca', $e->produit);
        }

        $this->assertTrue($bon->estBrouillon());
        $this->assertNull($bon->getLignes()->first()->getPrixCessionUnitaire(), 'Aucun prix figé.');
        $this->assertSame([], $this->mouvementsDuBon($bon), 'Pas même la baguette, qui ne dépend pourtant d\'aucun stock.');
        $this->assertSame(24000, $this->stock->getStock($this->depot, $this->coca));
        $this->assertSame([], $this->audit(ActionAudit::DOTATION_VALIDEE));

        $this->em->clear();
        $this->assertTrue(static::getContainer()->get(BonDotationRepository::class)->find($bon->getId())->estBrouillon());
    }

    public function testUnPrixDeCessionNonFixeEmpecheLaValidation(): void
    {
        // En MARGE, le vendeur garderait toute la vente.
        $this->stand->setModeRemuneration(ModeRemuneration::MARGE);
        $this->baguette->setPrixCession(0);
        $this->em->flush();
        $bon = $this->bon();

        try {
            $this->service->valider($bon, $this->gerante);
            $this->fail('Validation acceptée sans prix de cession.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Baguette', $e->getMessage());
            $this->assertStringContainsString('dirigeante', $e->getMessage());
        }

        $this->assertTrue($bon->estBrouillon());
        $this->assertSame([], $this->mouvementsDuBon($bon));
    }

    /** En COMMISSION, le prix de cession n'entre dans aucun calcul : il n'est pas exigé. */
    public function testUnStandACommissionSePasseDuPrixDeCession(): void
    {
        $this->baguette->setPrixCession(0);
        $this->em->flush();
        $bon = $this->bon([$this->baguette->getId() => 10]);

        $this->service->valider($bon, $this->gerante);

        $this->assertTrue($bon->estValide());
        $this->assertSame(0, $bon->getLignes()->first()->getPrixCessionUnitaire());
    }

    public function testUnBonVideNeSeValidePas(): void
    {
        $bon = $this->service->creer($this->stand, $this->awa, new \DateTimeImmutable(), [], $this->gerante);

        $this->expectException(\DomainException::class);
        $this->service->valider($bon, $this->gerante);
    }

    // ------------------------------------------------------------ Annulation

    public function testAnnulerUnBonValideEcritLesMouvementsInverses(): void
    {
        $bon = $this->bon();
        $this->service->valider($bon, $this->gerante);

        $this->service->annuler($bon, 'Vendeur absent', $this->gerante);

        $this->assertSame(StatutBonDotation::ANNULE, $bon->getStatut());
        $this->assertSame('Vendeur absent', $bon->getMotifAnnulation());
        $this->assertSame(24000, $this->stock->getStock($this->depot, $this->coca));
        $this->assertSame(0, $this->stock->getStock($this->stand, $this->coca));
        $this->assertSame(0, $this->stock->getStock($this->stand, $this->baguette));

        $mouvements = $this->mouvementsDuBon($bon);
        $this->assertCount(6, $mouvements, 'Les mouvements d\'origine restent, leurs inverses s\'ajoutent.');
        $this->assertSame(0, array_sum(array_map(static fn (MouvementStock $m): int => $m->getQuantite(), $mouvements)));
    }

    public function testLeMotifEstObligatoirePourAnnulerUnBonValide(): void
    {
        $bon = $this->bon();
        $this->service->valider($bon, $this->gerante);

        $this->expectException(\DomainException::class);
        $this->service->annuler($bon, '  ', $this->gerante);
    }

    public function testUnBonRattacheAUnArreteValideNeSAnnulePlus(): void
    {
        $bon = $this->bon();
        $this->service->valider($bon, $this->gerante);

        // Point du jour : rien ne revient, les espèces couvrent le net.
        $points = static::getContainer()->get(ArreteService::class);
        $jour = new \DateTimeImmutable('2026-09-11');
        $arrete = $points->enregistrer($this->stand, $jour, $this->awa, [], null, null, null, $this->gerante, $jour);

        // Arrêté encore en brouillon : le bon n'y est pas rattaché, il s'annulerait.
        $this->assertNull($bon->getArrete());
        $bon->verifierAnnulable('Erreur');

        $points->enregistrer($this->stand, $jour, $this->awa, [], $arrete->getNetARemettre(), null, null, $this->gerante, $jour);
        $points->valider($arrete, $this->gerante, $jour);
        $this->assertSame($arrete, $bon->getArrete());

        try {
            $this->service->annuler($bon, 'Erreur', $this->gerante);
            $this->fail('Bon annulé malgré un arrêté validé.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('arrêté validé', $e->getMessage());
        }

        $this->assertTrue($bon->estValide());
        $this->assertSame(0, $this->stock->getStock($this->stand, $this->coca), 'Tout est sorti du stand, en vente.');
        $this->assertSame([], $this->audit(ActionAudit::DOTATION_ANNULEE));

        // Et la période arrêtée est fermée aux dotations.
        try {
            $this->bon([], '2026-09-11');
            $this->fail('Dotation acceptée dans une période arrêtée.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('arrêté jusqu\'au 11/09/2026', $e->getMessage());
        }
    }

    /** Ce qui a été vendu ne revient pas au dépôt : l'annulation est refusée en bloc. */
    public function testUneAnnulationApresDesVentesAuStandEstRefusee(): void
    {
        $bon = $this->bon();
        $this->service->valider($bon, $this->gerante);
        $this->stock->enregistrerMouvement($this->baguette, $this->stand, -5000, TypeMouvementStock::VENTE_STAND);

        try {
            $this->service->annuler($bon, 'Erreur', $this->gerante);
            $this->fail('Annulation acceptée alors que le stand a vendu.');
        } catch (StockInsuffisantException $e) {
            $this->assertSame('Baguette', $e->produit);
        }

        $this->assertTrue($bon->estValide(), 'Le statut en mémoire est relu après le refus.');
        $this->assertSame(14000, $this->stock->getStock($this->depot, $this->coca), 'Le coca n\'est pas revenu seul.');
        $this->assertCount(3, $this->mouvementsDuBon($bon));
    }

    public function testAnnulerUnBrouillonNeTouchePasAuStock(): void
    {
        $bon = $this->bon();

        $this->service->annuler($bon, null, $this->gerante);

        $this->assertTrue($bon->estAnnule());
        $this->assertSame([], $this->mouvementsDuBon($bon));
        $this->assertCount(1, $this->audit(ActionAudit::DOTATION_ANNULEE), 'Toute annulation est tracée, brouillon compris.');

        $this->expectException(\DomainException::class);
        $this->service->annuler($bon, 'Encore', $this->gerante);
    }

    // ----------------------------------------------------------------- Audit

    /**
     * Pas de double validation : la trace est la seule sécurité. Elle dit qui, quand,
     * quel objet, et les montants avant et après.
     */
    public function testValidationEtAnnulationSontTraceesAvecLesMontants(): void
    {
        $bon = $this->bon();
        $this->service->valider($bon, $this->gerante);
        $this->service->annuler($bon, 'Erreur de stand', $this->gerante);

        [$validation] = $this->audit(ActionAudit::DOTATION_VALIDEE);
        [$annulation] = $this->audit(ActionAudit::DOTATION_ANNULEE);
        $du = 10 * 42000 + 40 * 12500;

        foreach ([$validation, $annulation] as $entree) {
            $this->assertSame('BonDotation', $entree->getEntite());
            $this->assertSame($bon->getId(), $entree->getEntiteId());
            $this->assertSame($this->gerante->getId(), $entree->getUtilisateur()?->getId());
            $this->assertInstanceOf(\DateTimeImmutable::class, $entree->getCreatedAt());
        }

        $this->assertSame('BROUILLON', $validation->getAvant()['statut']);
        $this->assertSame($du, $validation->getAvant()['totalCession'], 'Montant estimé avant validation.');
        $this->assertNull($validation->getAvant()['lignes'][0]['prixCessionUnitaire']);
        $this->assertSame('VALIDE', $validation->getApres()['statut']);
        $this->assertSame($du, $validation->getApres()['totalCession']);
        $this->assertSame(42000, $validation->getApres()['lignes'][0]['prixCessionUnitaire']);

        $this->assertSame('VALIDE', $annulation->getAvant()['statut']);
        $this->assertSame('ANNULE', $annulation->getApres()['statut']);
        $this->assertSame('Erreur de stand', $annulation->getApres()['motifAnnulation']);
        $this->assertTrue(ActionAudit::DOTATION_ANNULEE->estSensible());
    }

    // ------------------------------------------------------ Dernière dotation

    public function testLaDerniereDotationValideeIgnoreBrouillonsEtAnnulations(): void
    {
        $hier = $this->bon([$this->baguette->getId() => 30], '2026-09-10');
        $this->service->valider($hier, $this->gerante);

        $annule = $this->bon([$this->baguette->getId() => 99], '2026-09-11');
        $this->service->valider($annule, $this->gerante);
        $this->service->annuler($annule, 'Erreur', $this->gerante);

        $this->bon([$this->baguette->getId() => 77], '2026-09-11'); // brouillon

        $derniere = static::getContainer()->get(BonDotationRepository::class)->derniereValidee($this->stand);
        $this->assertSame($hier->getId(), $derniere?->getId());
        $this->assertSame(30000, $derniere->getLignes()->first()->getQuantite());
    }
}
