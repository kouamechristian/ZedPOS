<?php

namespace App\Tests\Functional\Magasin;

use App\Entity\JournalAudit;
use App\Entity\Magasin\MagasinEmplacement;
use App\Entity\Magasin\MagasinMouvement;
use App\Entity\Magasin\MagasinProduit;
use App\Entity\Magasin\MagasinSortie;
use App\Entity\Utilisateur;
use App\Enum\ActionAudit;
use App\Enum\Magasin\CategorieProduitMagasin;
use App\Enum\Magasin\DestinationSortieMagasin;
use App\Enum\Magasin\MotifSortieMagasin;
use App\Enum\Magasin\StatutSortieMagasin;
use App\Enum\Magasin\TypeMouvementMagasin;
use App\Service\Magasin\DemandeMouvementMagasin;
use App\Service\Magasin\MagasinStockService;
use App\Service\Magasin\QuantiteMagasin;
use App\Service\Magasin\SortieMagasinService;
use App\Service\Magasin\StockMagasinInsuffisantException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Sorties du magasin : seule la validation fait sortir le stock, jamais au-delà
 * du disponible ; l'annulation contre-passe.
 */
class SortieMagasinServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SortieMagasinService $service;
    private MagasinStockService $stock;
    private Utilisateur $gerante;
    private MagasinProduit $farine;
    private MagasinProduit $sucre;
    private MagasinEmplacement $reserve;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(SortieMagasinService::class);
        $this->stock = static::getContainer()->get(MagasinStockService::class);
        ReceptionMagasinServiceTest::viderTables($this->em);

        $this->gerante = (new Utilisateur('mariam@test.ci', 'Mariam'))->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        $this->farine = (new MagasinProduit('Farine de blé', CategorieProduitMagasin::MATIERE, 'kg'))->setUniteAchat('sac')->setContenanceAchat(50000);
        $this->sucre = new MagasinProduit('Sucre', CategorieProduitMagasin::MATIERE, 'kg');
        foreach ([$this->gerante, $this->farine, $this->sucre] as $entite) {
            $this->em->persist($entite);
        }
        $this->em->flush();
        $this->reserve = $this->stock->reserveParDefaut();

        // 20 sacs reçus, CMP 500 F le kg.
        $this->farine->definirCoutMoyen(50000);
        $this->stock->appliquer([new DemandeMouvementMagasin($this->farine, $this->reserve, 1000000, TypeMouvementMagasin::ENTREE_RECEPTION, 'reception', 1)], $this->gerante);
    }

    /** @param list<array{0: MagasinProduit, 1: int, 2?: bool, 3?: MagasinEmplacement}> $lignes */
    private function sortie(array $lignes, MotifSortieMagasin $motif = MotifSortieMagasin::PRODUCTION): MagasinSortie
    {
        return $this->service->creer(
            new \DateTimeImmutable('2026-10-02'),
            DestinationSortieMagasin::ATELIER,
            $motif,
            'Yao, boulanger',
            null,
            array_map(fn (array $l): array => ['produit' => $l[0], 'quantite' => $l[1], 'enUniteAchat' => $l[2] ?? true, 'emplacement' => $l[3] ?? $this->reserve], $lignes),
            $this->gerante,
        );
    }

    private function stockFarine(): string
    {
        return QuantiteMagasin::formater($this->farine, $this->stock->stockTotal($this->farine));
    }

    /** Recette du 02/10/2026 : 3 sacs vers l'atelier → 17 sacs. */
    public function testLaRecetteTroisSacsVersLAtelier(): void
    {
        $sortie = $this->sortie([[$this->farine, 3000]]);
        $this->assertSame('SOR-2026-0001', $sortie->getNumero());
        $this->assertSame(StatutSortieMagasin::BROUILLON, $sortie->getStatut());
        $this->assertSame('20 sacs (1 000 kg)', $this->stockFarine(), 'Le brouillon ne touche pas au stock.');

        $this->service->valider($sortie, $this->gerante);

        $this->assertSame(StatutSortieMagasin::VALIDEE, $sortie->getStatut());
        $this->assertSame('17 sacs (850 kg)', $this->stockFarine());
        $this->assertSame($this->gerante, $sortie->getServiePar());

        $mouvement = $this->em->getRepository(MagasinMouvement::class)->findOneBy(['type' => TypeMouvementMagasin::SORTIE]);
        $this->assertSame(-150000, $mouvement->getQuantite());
        $this->assertSame('sortie', $mouvement->getDocumentType());
        $this->assertSame($sortie->getId(), $mouvement->getDocumentId());
        $this->assertSame([], $this->stock->verifier());
    }

    /** Recette : une sortie de 30 sacs est refusée, rien ne sort, le bon reste en brouillon. */
    public function testUneSortieAuDelaDuStockEstRefusee(): void
    {
        $sortie = $this->sortie([[$this->farine, 30000]]);

        try {
            $this->service->valider($sortie, $this->gerante);
            $this->fail('La sortie aurait dû être refusée.');
        } catch (StockMagasinInsuffisantException $e) {
            $this->assertSame('Stock insuffisant pour « Farine de blé » (Réserve principale) : 20 sacs (1 000 kg) disponible, 30 sacs (1 500 kg) demandé.', $e->getMessage());
        }

        $this->assertSame(StatutSortieMagasin::BROUILLON, $sortie->getStatut());
        $this->assertNull($sortie->getServiePar());
        $this->assertNull($sortie->getLignes()->first()->getCoutUnitaire());
        $this->assertSame('20 sacs (1 000 kg)', $this->stockFarine());
        $this->assertCount(0, $this->em->getRepository(MagasinMouvement::class)->findBy(['type' => TypeMouvementMagasin::SORTIE]));
        $this->assertTrue($this->em->isOpen());
    }

    public function testLaQuantiteSeSaisitEnUniteDeStock(): void
    {
        $sortie = $this->sortie([[$this->farine, 12500, false]]);
        $ligne = $sortie->getLignes()->first();

        $this->assertSame('kg', $ligne->getUniteSaisie());
        $this->assertSame(12500, $ligne->getQuantite());

        $this->service->valider($sortie, $this->gerante);
        $this->assertSame(987500, $this->stock->stockTotal($this->farine));
    }

    public function testUnProduitSansUniteDAchatSortEnUniteDeStock(): void
    {
        $this->stock->appliquer([new DemandeMouvementMagasin($this->sucre, $this->reserve, 50000, TypeMouvementMagasin::ENTREE_RECEPTION, 'reception', 2)], $this->gerante);

        $sortie = $this->sortie([[$this->sucre, 2000, true]]);
        $this->assertSame('kg', $sortie->getLignes()->first()->getUniteSaisie());
        $this->assertSame(2000, $sortie->getLignes()->first()->getQuantite());
    }

    /** Le contrôle porte sur le cumul : deux produits, l'un manque → rien ne sort, pas même l'autre. */
    public function testLaSortieEstToutOuRien(): void
    {
        $sortie = $this->sortie([[$this->farine, 2000], [$this->sucre, 1000, false]]);

        try {
            $this->service->valider($sortie, $this->gerante);
            $this->fail('Le sucre manque : la sortie aurait dû être refusée.');
        } catch (StockMagasinInsuffisantException) {
        }

        $this->assertSame(1000000, $this->stock->stockTotal($this->farine), 'La farine n\'est pas sortie non plus.');
    }

    public function testLaSortieSeFaitParEmplacement(): void
    {
        $froid = new MagasinEmplacement('FROID', 'Chambre froide');
        $this->em->persist($froid);
        $this->em->flush();

        $sortie = $this->sortie([[$this->farine, 1000, true, $froid]]);
        $this->expectException(StockMagasinInsuffisantException::class);
        $this->expectExceptionMessage('(Chambre froide)');
        $this->service->valider($sortie, $this->gerante);
    }

    public function testLeCoutEstFigeALaValidationEtLeCmpNeBougePas(): void
    {
        $sortie = $this->sortie([[$this->farine, 3000]]);
        $this->service->valider($sortie, $this->gerante);

        $this->assertSame(50000, $sortie->getLignes()->first()->getCoutUnitaire());
        $this->assertSame(7500000, $sortie->getValeur(), '150 kg × 500 F = 75 000 F.');
        $this->assertSame(50000, $this->farine->getCoutMoyen());
    }

    public function testUneSortieValideeNeSeModifiePlus(): void
    {
        $sortie = $this->sortie([[$this->farine, 3000]]);
        $this->service->valider($sortie, $this->gerante);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('ne se modifie plus');
        $this->service->modifier($sortie, new \DateTimeImmutable('2026-10-02'), DestinationSortieMagasin::ATELIER, MotifSortieMagasin::PRODUCTION, null, null, [
            ['produit' => $this->farine, 'emplacement' => $this->reserve, 'quantite' => 1000, 'enUniteAchat' => true],
        ], $this->gerante);
    }

    public function testLeBrouillonSeCorrige(): void
    {
        $sortie = $this->sortie([[$this->farine, 3000]]);
        $this->service->modifier($sortie, new \DateTimeImmutable('2026-10-01'), DestinationSortieMagasin::CUISINE, MotifSortieMagasin::AUTRE, 'Awa', 'Pour la sauce', [
            ['produit' => $this->farine, 'emplacement' => $this->reserve, 'quantite' => 2000, 'enUniteAchat' => true],
        ], $this->gerante);

        $this->em->clear();
        $relue = $this->em->getRepository(MagasinSortie::class)->find($sortie->getId());
        $this->assertSame(DestinationSortieMagasin::CUISINE, $relue->getDestination());
        $this->assertCount(1, $relue->getLignes());
        $this->assertSame(100000, $relue->getLignes()->first()->getQuantite());
        $this->assertSame('Awa', $relue->getDemandePar());
    }

    public function testLAnnulationRemetEnStock(): void
    {
        $sortie = $this->sortie([[$this->farine, 3000]]);
        $this->service->valider($sortie, $this->gerante);
        $this->service->annuler($sortie, 'Sacs rapportés intacts', $this->gerante);

        $this->assertSame(StatutSortieMagasin::ANNULEE, $sortie->getStatut());
        $this->assertSame(StatutSortieMagasin::VALIDEE, $sortie->getStatutAvantAnnulation());
        $this->assertSame('20 sacs (1 000 kg)', $this->stockFarine());

        $types = array_map(static fn (MagasinMouvement $m): TypeMouvementMagasin => $m->getType(), $this->em->getRepository(MagasinMouvement::class)->findBy(['documentType' => 'sortie'], ['id' => 'ASC']));
        $this->assertSame([TypeMouvementMagasin::SORTIE, TypeMouvementMagasin::ANNULATION], $types);
        $this->assertSame([], $this->stock->verifier());
    }

    public function testUnBrouillonSAnnuleSansMouvementEtAvecMotif(): void
    {
        $sortie = $this->sortie([[$this->farine, 3000]]);

        try {
            $this->service->annuler($sortie, ' ', $this->gerante);
            $this->fail('Le motif est obligatoire.');
        } catch (\DomainException) {
        }
        $this->service->annuler($sortie, 'Erreur de saisie', $this->gerante);

        $this->assertSame(StatutSortieMagasin::ANNULEE, $sortie->getStatut());
        $this->assertCount(0, $this->em->getRepository(MagasinMouvement::class)->findBy(['documentType' => 'sortie']));

        $this->expectException(\DomainException::class);
        $this->service->valider($sortie, $this->gerante);
    }

    public function testUneSortieVideOuEnDoubleEstRefusee(): void
    {
        try {
            $this->sortie([]);
            $this->fail('Une sortie vide aurait dû être refusée.');
        } catch (\DomainException) {
        }
        try {
            $this->sortie([[$this->farine, 0]]);
            $this->fail('Une quantité nulle aurait dû être refusée.');
        } catch (\DomainException) {
        }

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('figure deux fois');
        $this->sortie([[$this->farine, 1000], [$this->farine, 2000]]);
    }

    public function testLaNumerotationEtLAudit(): void
    {
        $premiere = $this->sortie([[$this->farine, 1000]]);
        $seconde = $this->sortie([[$this->farine, 1000]]);
        $this->assertSame('SOR-2026-0002', $seconde->getNumero());

        $this->service->valider($premiere, $this->gerante);
        $this->service->annuler($premiere, 'Doublon', $this->gerante);

        $actions = array_map(static fn (JournalAudit $j): string => $j->getAction(), $this->em->getRepository(JournalAudit::class)->findBy(['entite' => 'MagasinSortie', 'entiteId' => $premiere->getId()], ['id' => 'ASC']));
        $this->assertSame([
            ActionAudit::MAGASIN_SORTIE_ENREGISTREE->value,
            ActionAudit::MAGASIN_SORTIE_VALIDEE->value,
            ActionAudit::MAGASIN_SORTIE_ANNULEE->value,
        ], $actions);
        $this->assertTrue(ActionAudit::MAGASIN_SORTIE_ANNULEE->estSensible());
    }

}
