<?php

namespace App\Tests\Functional\Magasin;

use App\Entity\Fournisseur;
use App\Entity\JournalAudit;
use App\Entity\Magasin\MagasinEmplacement;
use App\Entity\Magasin\MagasinMouvement;
use App\Entity\Magasin\MagasinProduit;
use App\Entity\Magasin\MagasinReception;
use App\Entity\Utilisateur;
use App\Enum\ActionAudit;
use App\Enum\Magasin\CategorieProduitMagasin;
use App\Enum\Magasin\MotifRejetMagasin;
use App\Enum\Magasin\StatutReceptionMagasin;
use App\Enum\Magasin\TypeMouvementMagasin;
use App\Service\Magasin\DemandeMouvementMagasin;
use App\Service\Magasin\MagasinStockService;
use App\Service\Magasin\QuantiteMagasin;
use App\Service\Magasin\ReceptionMagasinService;
use App\Service\Magasin\StockMagasinInsuffisantException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Réception → Contrôle → Inspection → Stockage, et l'annulation : le service
 * contre la vraie base. Le stock ne bouge qu'au stockage, et seulement de
 * l'accepté.
 */
class ReceptionMagasinServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ReceptionMagasinService $service;
    private MagasinStockService $stock;
    private Utilisateur $gerante;
    private Fournisseur $fournisseur;
    private MagasinProduit $farine;
    private MagasinProduit $levure;
    private MagasinEmplacement $reserve;

    public static function viderTables(EntityManagerInterface $em): void
    {
        $connexion = $em->getConnection();
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['magasin_ligne_inventaire', 'magasin_inventaire', 'magasin_ligne_sortie', 'magasin_sortie', 'magasin_ligne_reception', 'magasin_reception', 'magasin_mouvement', 'magasin_stock', 'magasin_produit', 'journal_audit', 'fournisseur', 'utilisateur'] as $table) {
            $connexion->executeStatement('DELETE FROM '.$table);
        }
        $connexion->executeStatement("DELETE FROM magasin_emplacement WHERE code <> 'RESERVE'");
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(ReceptionMagasinService::class);
        $this->stock = static::getContainer()->get(MagasinStockService::class);
        self::viderTables($this->em);

        $this->gerante = (new Utilisateur('mariam@test.ci', 'Mariam'))->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        $this->fournisseur = new Fournisseur('Grands Moulins');
        $this->farine = (new MagasinProduit('Farine de blé', CategorieProduitMagasin::MATIERE, 'kg'))->setUniteAchat('sac')->setContenanceAchat(50000);
        $this->levure = new MagasinProduit('Levure', CategorieProduitMagasin::MATIERE, 'kg');
        foreach ([$this->gerante, $this->fournisseur, $this->farine, $this->levure] as $entite) {
            $this->em->persist($entite);
        }
        $this->em->flush();
        $this->reserve = $this->stock->reserveParDefaut();
    }

    /** @param list<array{0: MagasinProduit, 1: int, 2?: ?int}> $lignes */
    private function recevoir(array $lignes, ?\DateTimeImmutable $date = null): MagasinReception
    {
        return $this->service->creer(
            $this->fournisseur,
            $date ?? new \DateTimeImmutable('2026-10-02'),
            'BL-778',
            null,
            array_map(static fn (array $l): array => ['produit' => $l[0], 'annoncee' => $l[1], 'prix' => $l[2] ?? null], $lignes),
            $this->gerante,
        );
    }

    private function idLigne(MagasinReception $reception, MagasinProduit $produit): int
    {
        foreach ($reception->getLignes() as $ligne) {
            if ($ligne->getProduit() === $produit) {
                return (int) $ligne->getId();
            }
        }
        $this->fail('Ligne introuvable.');
    }

    /** Les quatre étapes sans incident, sur la farine seule. */
    private function toutesLesEtapes(MagasinReception $reception, int $comptee, int $rejetee = 0, ?MotifRejetMagasin $motif = null, ?string $commentaire = null): void
    {
        $id = $this->idLigne($reception, $this->farine);
        $this->service->controler($reception, [$id => $comptee], $commentaire, $this->gerante);
        $this->service->inspecter($reception, [$id => ['acceptee' => $comptee - $rejetee, 'rejetee' => $rejetee, 'motif' => $motif]], $this->gerante);
        $this->service->stocker($reception, [$id => $this->reserve], $this->gerante);
    }

    /** Recette du 02/10/2026 : 20 sacs annoncés, comptés, acceptés, rangés → « 20 sacs (1 000 kg) ». */
    public function testLaRecetteVingtSacsEntrentEnReserve(): void
    {
        $reception = $this->recevoir([[$this->farine, 20000]]);
        $this->assertSame('REC-2026-0001', $reception->getNumero());
        $this->assertSame(StatutReceptionMagasin::RECUE, $reception->getStatut());

        $id = $this->idLigne($reception, $this->farine);
        $this->service->controler($reception, [$id => 20000], null, $this->gerante);
        $this->assertSame(StatutReceptionMagasin::CONTROLEE, $reception->getStatut());
        $this->assertSame(0, $this->stock->stockTotal($this->farine), 'Le contrôle ne touche pas au stock.');

        $this->service->inspecter($reception, [$id => ['acceptee' => 20000, 'rejetee' => 0, 'motif' => null]], $this->gerante);
        $this->assertSame(0, $this->stock->stockTotal($this->farine), 'L\'inspection non plus.');

        $this->service->stocker($reception, [$id => $this->reserve], $this->gerante);
        $this->assertSame(StatutReceptionMagasin::STOCKEE, $reception->getStatut());
        $this->assertSame(1000000, $this->stock->stock($this->farine, $this->reserve));
        $this->assertSame('20 sacs (1 000 kg)', QuantiteMagasin::formater($this->farine, $this->stock->stockTotal($this->farine)));

        $mouvements = $this->em->getRepository(MagasinMouvement::class)->findAll();
        $this->assertCount(1, $mouvements);
        $this->assertSame(TypeMouvementMagasin::ENTREE_RECEPTION, $mouvements[0]->getType());
        $this->assertSame('reception', $mouvements[0]->getDocumentType());
        $this->assertSame($reception->getId(), $mouvements[0]->getDocumentId());
        $this->assertSame([], $this->stock->verifier());
    }

    /** Variante de la recette : 20 annoncés, 19 comptés (commentaire), 1 rejeté avarié → 18 entrent. */
    public function testLaRecetteAvecEcartEtRejet(): void
    {
        $reception = $this->recevoir([[$this->farine, 20000]]);
        $id = $this->idLigne($reception, $this->farine);

        try {
            $this->service->controler($reception, [$id => 19000], '  ', $this->gerante);
            $this->fail('Un écart sans commentaire aurait dû être refusé.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('expliquez l\'écart', $e->getMessage());
        }
        $this->assertSame(StatutReceptionMagasin::RECUE, $reception->getStatut(), 'Refusé : rien n\'a changé.');

        $this->toutesLesEtapes($reception, 19000, 1000, MotifRejetMagasin::AVARIE, 'Un sac manquant, signalé au livreur');

        $ligne = $reception->ligne($id);
        $this->assertTrue($ligne->aUnEcartDeComptage());
        $this->assertSame(18000, $ligne->getQteAcceptee());
        $this->assertSame(MotifRejetMagasin::AVARIE, $ligne->getMotifRejet());
        $this->assertSame('18 sacs (900 kg)', QuantiteMagasin::formater($this->farine, $this->stock->stockTotal($this->farine)));
    }

    public function testAccepteEtRejeteDoiventFaireLeCompte(): void
    {
        $reception = $this->recevoir([[$this->farine, 20000]]);
        $id = $this->idLigne($reception, $this->farine);
        $this->service->controler($reception, [$id => 20000], null, $this->gerante);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('accepté et rejeté doivent faire ensemble la quantité comptée');
        $this->service->inspecter($reception, [$id => ['acceptee' => 18000, 'rejetee' => 1000, 'motif' => MotifRejetMagasin::CASSE]], $this->gerante);
    }

    public function testUnRejetSansMotifEstRefuse(): void
    {
        $reception = $this->recevoir([[$this->farine, 20000]]);
        $id = $this->idLigne($reception, $this->farine);
        $this->service->controler($reception, [$id => 20000], null, $this->gerante);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('indiquez le motif du rejet');
        $this->service->inspecter($reception, [$id => ['acceptee' => 19000, 'rejetee' => 1000, 'motif' => null]], $this->gerante);
    }

    public function testOnNeSautePasDEtape(): void
    {
        $reception = $this->recevoir([[$this->farine, 20000]]);
        $id = $this->idLigne($reception, $this->farine);

        try {
            $this->service->inspecter($reception, [$id => ['acceptee' => 20000, 'rejetee' => 0, 'motif' => null]], $this->gerante);
            $this->fail('L\'inspection avant le contrôle aurait dû être refusée.');
        } catch (\DomainException) {
        }
        try {
            $this->service->stocker($reception, [$id => $this->reserve], $this->gerante);
            $this->fail('Le stockage avant l\'inspection aurait dû être refusé.');
        } catch (\DomainException) {
        }

        $this->assertSame(StatutReceptionMagasin::RECUE, $reception->getStatut());
        $this->assertSame(0, $this->stock->stockTotal($this->farine));
    }

    public function testOnNeRevientPasSurUneEtapeDepassee(): void
    {
        $reception = $this->recevoir([[$this->farine, 20000]]);
        $id = $this->idLigne($reception, $this->farine);
        $this->service->controler($reception, [$id => 20000], null, $this->gerante);
        $this->service->controler($reception, [$id => 20000], null, $this->gerante); // se reprend tant que l'inspection n'a pas eu lieu
        $this->service->inspecter($reception, [$id => ['acceptee' => 20000, 'rejetee' => 0, 'motif' => null]], $this->gerante);

        $this->expectException(\DomainException::class);
        $this->service->controler($reception, [$id => 19000], 'Recompté', $this->gerante);
    }

    public function testUneLigneEntierementRejeteeNEntrePas(): void
    {
        $reception = $this->recevoir([[$this->farine, 20000], [$this->levure, 5000]]);
        $farine = $this->idLigne($reception, $this->farine);
        $levure = $this->idLigne($reception, $this->levure);

        $this->service->controler($reception, [$farine => 20000, $levure => 5000], null, $this->gerante);
        $this->service->inspecter($reception, [
            $farine => ['acceptee' => 20000, 'rejetee' => 0, 'motif' => null],
            $levure => ['acceptee' => 0, 'rejetee' => 5000, 'motif' => MotifRejetMagasin::PERIME],
        ], $this->gerante);
        $this->service->stocker($reception, [$farine => $this->reserve], $this->gerante);

        $this->assertSame(0, $this->stock->stockTotal($this->levure));
        $this->assertNull($reception->ligne($levure)->getEmplacement());
        $this->assertCount(1, $this->em->getRepository(MagasinMouvement::class)->findAll());
    }

    public function testUnEmplacementDesactiveRefuseLeStockage(): void
    {
        $froid = new MagasinEmplacement('FROID', 'Chambre froide');
        $froid->setActif(false);
        $this->em->persist($froid);
        $this->em->flush();

        $reception = $this->recevoir([[$this->farine, 20000]]);
        $id = $this->idLigne($reception, $this->farine);
        $this->service->controler($reception, [$id => 20000], null, $this->gerante);
        $this->service->inspecter($reception, [$id => ['acceptee' => 20000, 'rejetee' => 0, 'motif' => null]], $this->gerante);

        try {
            $this->service->stocker($reception, [$id => $froid], $this->gerante);
            $this->fail('Un emplacement désactivé aurait dû être refusé.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('désactivé', $e->getMessage());
        }
        $this->assertSame(StatutReceptionMagasin::INSPECTEE, $reception->getStatut(), 'Refusé : la réception reste à stocker.');
        $this->assertTrue($this->em->isOpen());
    }

    public function testLeStockageMetAJourLeCoutMoyen(): void
    {
        // 20 sacs à 25 000 F → 500 F le kg.
        $this->toutesLesEtapes($this->recevoir([[$this->farine, 20000, 2500000]]), 20000);
        $this->assertSame(50000, $this->farine->getCoutMoyen());

        // 10 sacs à 28 000 F → (1 000 × 500 + 500 × 560) / 1 500 = 520 F le kg.
        $this->toutesLesEtapes($this->recevoir([[$this->farine, 10000, 2800000]]), 10000);
        $this->em->refresh($this->farine);
        $this->assertSame(52000, $this->farine->getCoutMoyen());
    }

    public function testLeRejeteNeComptePasDansLeCoutMoyen(): void
    {
        $this->toutesLesEtapes($this->recevoir([[$this->farine, 20000, 2500000]]), 20000, 2000, MotifRejetMagasin::CASSE);

        $this->assertSame(900000, $this->stock->stockTotal($this->farine));
        $this->assertSame(50000, $this->farine->getCoutMoyen(), 'Le prix unitaire ne change pas parce que deux sacs sont rendus.');
    }

    public function testSansPrixLeCoutMoyenNeBougePas(): void
    {
        $this->farine->definirCoutMoyen(48000);
        $this->em->flush();

        $this->toutesLesEtapes($this->recevoir([[$this->farine, 20000]]), 20000);
        $this->assertSame(48000, $this->farine->getCoutMoyen());
    }

    public function testLAnnulationContrePasseEtRestaureLeCoutMoyen(): void
    {
        $this->toutesLesEtapes($this->recevoir([[$this->farine, 20000, 2500000]]), 20000);
        $seconde = $this->recevoir([[$this->farine, 10000, 2800000]]);
        $this->toutesLesEtapes($seconde, 10000);

        $this->service->annuler($seconde, 'Livraison en double', $this->gerante);

        $this->assertSame(StatutReceptionMagasin::ANNULEE, $seconde->getStatut());
        $this->assertSame(StatutReceptionMagasin::STOCKEE, $seconde->getStatutAvantAnnulation());
        $this->assertSame(1000000, $this->stock->stockTotal($this->farine));
        $this->assertSame(50000, $this->farine->getCoutMoyen());

        $types = array_map(static fn (MagasinMouvement $m): TypeMouvementMagasin => $m->getType(), $this->em->getRepository(MagasinMouvement::class)->findBy([], ['id' => 'ASC']));
        $this->assertSame([TypeMouvementMagasin::ENTREE_RECEPTION, TypeMouvementMagasin::ENTREE_RECEPTION, TypeMouvementMagasin::ANNULATION], $types, 'Rien n\'est effacé : on contre-passe.');
        $this->assertSame([], $this->stock->verifier());
    }

    /** La marchandise est déjà sortie : défaire la réception rendrait le stock négatif. */
    public function testLAnnulationEstRefuseeSiLeStockPassaitSousZero(): void
    {
        $reception = $this->recevoir([[$this->farine, 20000]]);
        $this->toutesLesEtapes($reception, 20000);
        $this->stock->appliquer([new DemandeMouvementMagasin($this->farine, $this->reserve, -150000, TypeMouvementMagasin::SORTIE, 'sortie', 1)], $this->gerante);

        try {
            $this->service->annuler($reception, 'Erreur', $this->gerante);
            $this->fail('L\'annulation aurait dû être refusée.');
        } catch (StockMagasinInsuffisantException) {
        }

        $this->assertSame(StatutReceptionMagasin::STOCKEE, $reception->getStatut());
        $this->assertSame(850000, $this->stock->stockTotal($this->farine));
        $this->assertTrue($this->em->isOpen());
    }

    public function testUneReceptionNonStockeeSAnnuleSansMouvement(): void
    {
        $reception = $this->recevoir([[$this->farine, 20000]]);

        try {
            $this->service->annuler($reception, '', $this->gerante);
            $this->fail('Le motif est obligatoire.');
        } catch (\DomainException) {
        }
        $this->service->annuler($reception, 'Commande annulée par le fournisseur', $this->gerante);

        $this->assertSame(StatutReceptionMagasin::ANNULEE, $reception->getStatut());
        $this->assertCount(0, $this->em->getRepository(MagasinMouvement::class)->findAll());

        $this->expectException(\DomainException::class);
        $this->service->annuler($reception, 'Encore', $this->gerante);
    }

    public function testLaNumerotationRepartAZeroChaqueAnnee(): void
    {
        $this->assertSame('REC-2026-0001', $this->recevoir([[$this->farine, 1000]])->getNumero());
        $this->assertSame('REC-2026-0002', $this->recevoir([[$this->farine, 1000]])->getNumero());
        $this->assertSame('REC-2027-0001', $this->recevoir([[$this->farine, 1000]], new \DateTimeImmutable('2027-01-03'))->getNumero());
    }

    public function testLAnnonceSeCorrigeAvantLeControleEnGardantLePrix(): void
    {
        $reception = $this->recevoir([[$this->farine, 20000, 2500000]]);
        $id = $this->idLigne($reception, $this->farine);

        // La gérante ne voit pas les prix : sa correction n'a pas de clé « prix ».
        $this->service->corrigerAnnonce($reception, $this->fournisseur, new \DateTimeImmutable('2026-10-02'), 'BL-779', null, [
            ['produit' => $this->farine, 'annoncee' => 22000],
            ['produit' => $this->levure, 'annoncee' => 2000],
        ], $this->gerante);

        $this->assertSame($id, $this->idLigne($reception, $this->farine), 'La ligne garde son identité.');
        $this->assertSame(22000, $reception->ligne($id)->getQteAnnoncee());
        $this->assertSame(2500000, $reception->ligne($id)->getPrixUnitaire());
        $this->assertCount(2, $reception->getLignes());
    }

    public function testUnProduitEnDoubleOuUneReceptionVideSontRefuses(): void
    {
        try {
            $this->recevoir([]);
            $this->fail('Une réception vide aurait dû être refusée.');
        } catch (\DomainException) {
        }

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('figure deux fois');
        $this->recevoir([[$this->farine, 1000], [$this->farine, 2000]]);
    }

    /** Les unités de la ligne sont figées : changer la taille des sacs ne réécrit pas une réception. */
    public function testLesUnitesSontFigeesSurLaLigne(): void
    {
        $reception = $this->recevoir([[$this->farine, 20000]]);
        $this->farine->setContenanceAchat(25000);
        $this->em->flush();

        $this->toutesLesEtapes($reception, 20000);
        $this->assertSame(1000000, $this->stock->stockTotal($this->farine), '20 sacs de 50 kg, comme au jour de la réception.');
    }

    public function testChaqueEtapeEstTracee(): void
    {
        $reception = $this->recevoir([[$this->farine, 20000]]);
        $this->toutesLesEtapes($reception, 20000);
        $this->service->annuler($reception, 'Erreur de fournisseur', $this->gerante);

        $actions = array_map(static fn (JournalAudit $j): string => $j->getAction(), $this->em->getRepository(JournalAudit::class)->findBy(['entite' => 'MagasinReception'], ['id' => 'ASC']));
        $this->assertSame([
            ActionAudit::MAGASIN_RECEPTION_RECUE->value,
            ActionAudit::MAGASIN_RECEPTION_CONTROLEE->value,
            ActionAudit::MAGASIN_RECEPTION_INSPECTEE->value,
            ActionAudit::MAGASIN_RECEPTION_STOCKEE->value,
            ActionAudit::MAGASIN_RECEPTION_ANNULEE->value,
        ], $actions);
        $this->assertTrue(ActionAudit::MAGASIN_RECEPTION_ANNULEE->estSensible());
    }
}
