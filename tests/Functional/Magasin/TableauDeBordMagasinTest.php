<?php

namespace App\Tests\Functional\Magasin;

use App\Entity\Fournisseur;
use App\Entity\Magasin\MagasinProduit;
use App\Entity\Magasin\MagasinReception;
use App\Entity\Utilisateur;
use App\Enum\Magasin\CategorieProduitMagasin;
use App\Enum\Magasin\DestinationSortieMagasin;
use App\Enum\Magasin\MotifRejetMagasin;
use App\Enum\Magasin\MotifSortieMagasin;
use App\Enum\RoleUtilisateur;
use App\Service\Magasin\MagasinStockService;
use App\Service\Magasin\ReceptionMagasinService;
use App\Service\Magasin\SortieMagasinService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Le tableau de bord du magasin : le scénario de recette (20 / 20 / 20 / 20,
 * entrées 20, sorties 0), les rejets, les sorties, les annulations du jour, ce
 * qui attend et les produits sous le seuil.
 */
class TableauDeBordMagasinTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ReceptionMagasinService $receptions;
    private Utilisateur $gerante;
    private Fournisseur $fournisseur;
    private MagasinProduit $farine;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->receptions = static::getContainer()->get(ReceptionMagasinService::class);
        ReceptionMagasinServiceTest::viderTables($this->em);
        $this->em->getConnection()->executeStatement("UPDATE magasin_emplacement SET actif = 1, libelle = 'Réserve principale' WHERE code = 'RESERVE'");

        $this->gerante = (new Utilisateur('mariam@test.ci', 'Mariam'))->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        $this->fournisseur = new Fournisseur('Grands Moulins');
        $this->farine = (new MagasinProduit('Farine de blé', CategorieProduitMagasin::MATIERE, 'kg'))->setUniteAchat('sac')->setContenanceAchat(50000);
        foreach ([$this->gerante, $this->fournisseur, $this->farine] as $entite) {
            $this->em->persist($entite);
        }
        $this->em->flush();
        $this->client->loginUser($this->gerante);
    }

    private function recevoir(int $annonce): MagasinReception
    {
        return $this->receptions->creer($this->fournisseur, new \DateTimeImmutable('today'), 'BL-1', null, [['produit' => $this->farine, 'annoncee' => $annonce]], $this->gerante);
    }

    /** Les quatre étapes jusqu'au stockage ; un rejet facultatif à l'inspection. */
    private function jusquAuStockage(MagasinReception $reception, int $comptee, int $rejetee = 0): void
    {
        $id = (int) $reception->getLignes()->first()->getId();
        $this->receptions->controler($reception, [$id => $comptee], $comptee !== $reception->getLignes()->first()->getQteAnnoncee() ? 'Écart' : null, $this->gerante);
        $this->receptions->inspecter($reception, [$id => ['acceptee' => $comptee - $rejetee, 'rejetee' => $rejetee, 'motif' => $rejetee > 0 ? MotifRejetMagasin::AVARIE : null]], $this->gerante);
        $this->receptions->stocker($reception, [$id => static::getContainer()->get(MagasinStockService::class)->reserveParDefaut()], $this->gerante);
    }

    private function carte(Crawler $page, string $etape): string
    {
        return $page->filter('#plan-magasin [data-etape="'.$etape.'"]')->text();
    }

    /** Recette du 02/10/2026 : 20 / 20 / 20 / 20, entrées 20, sorties 0. */
    public function testLaRecetteVingtSacs(): void
    {
        $this->jusquAuStockage($this->recevoir(20000), 20000);

        $page = $this->client->request('GET', '/magasin');
        $this->assertResponseIsSuccessful();
        $this->assertCount(6, $page->filter('#plan-magasin > article'));

        foreach (['RECUE', 'CONTROLEE', 'INSPECTEE', 'STOCKEE'] as $etape) {
            $this->assertStringContainsString('20 sacs (1 000 kg)', $this->carte($page, $etape), $etape);
        }
        $this->assertStringContainsString('1 réception du jour', $this->carte($page, 'RECUE'));
        $this->assertStringContainsString('20 sacs (1 000 kg)', $page->filter('[data-mouvement="entrees"]')->text());
        $this->assertStringContainsString('Aucune sortie', $page->filter('[data-mouvement="sorties"]')->text());
    }

    /** Variante : 20 annoncés, 19 comptés, 1 rejeté → 18 entrent, le rejet se lit à l'inspection. */
    public function testLEcartEtLeRejetSeLisentEtapeParEtape(): void
    {
        $this->jusquAuStockage($this->recevoir(20000), 19000, 1000);

        $page = $this->client->request('GET', '/magasin');
        $this->assertStringContainsString('20 sacs (1 000 kg)', $this->carte($page, 'RECUE'));
        $this->assertStringContainsString('19 sacs (950 kg)', $this->carte($page, 'CONTROLEE'));
        $this->assertStringContainsString('18 sacs (900 kg)', $this->carte($page, 'INSPECTEE'));
        $this->assertStringContainsString('1 sac (50 kg)', $page->filter('[data-etape="INSPECTEE"] [data-rejete]')->text());
        $this->assertStringContainsString('18 sacs (900 kg)', $page->filter('[data-mouvement="entrees"]')->text());
    }

    public function testLesSortiesDuJourSontComptees(): void
    {
        $this->jusquAuStockage($this->recevoir(20000), 20000);
        $sorties = static::getContainer()->get(SortieMagasinService::class);
        $sortie = $sorties->creer(new \DateTimeImmutable('today'), DestinationSortieMagasin::ATELIER, MotifSortieMagasin::PRODUCTION, 'Yao', null, [
            ['produit' => $this->farine, 'emplacement' => static::getContainer()->get(MagasinStockService::class)->reserveParDefaut(), 'quantite' => 3000, 'enUniteAchat' => true],
        ], $this->gerante);
        $sorties->valider($sortie, $this->gerante);

        $page = $this->client->request('GET', '/magasin');
        $texte = $page->filter('[data-mouvement="sorties"]')->text();
        $this->assertStringContainsString('3 sacs (150 kg)', $texte);
        $this->assertStringContainsString('1 bon', $texte);
    }

    /** Une réception stockée puis annulée dans la journée ne fait rien entrer. */
    public function testLesAnnulationsDuJourSontDeduites(): void
    {
        $reception = $this->recevoir(20000);
        $this->jusquAuStockage($reception, 20000);
        $this->receptions->annuler($reception, 'Livraison en double', $this->gerante);

        $page = $this->client->request('GET', '/magasin');
        $this->assertStringContainsString('Aucune entrée', $page->filter('[data-mouvement="entrees"]')->text());
        $this->assertStringContainsString('Aucune livraison', $this->carte($page, 'RECUE'), 'Une réception annulée sort des étapes.');
    }

    public function testCeQuiAttendEstAnnonceSurLaBonneCarte(): void
    {
        $this->recevoir(5000);
        $this->recevoir(6000);
        $controlee = $this->recevoir(7000);
        $this->receptions->controler($controlee, [(int) $controlee->getLignes()->first()->getId() => 7000], null, $this->gerante);

        $page = $this->client->request('GET', '/magasin');
        $this->assertStringContainsString('2 à contrôler', $page->filter('[data-etape="CONTROLEE"] [data-attente]')->text());
        $this->assertStringContainsString('1 à inspecter', $page->filter('[data-etape="INSPECTEE"] [data-attente]')->text());
        $this->assertStringContainsString('Rien à ranger', $page->filter('[data-etape="STOCKEE"] [data-attente]')->text());
    }

    public function testLesProduitsSousLeSeuilSontSignales(): void
    {
        $this->farine->setSeuilAlerte(500000);
        $this->em->flush();
        $this->jusquAuStockage($this->recevoir(5000), 5000);

        $tableau = $this->client->request('GET', '/magasin')->filter('#sous-seuil')->text();
        $this->assertStringContainsString('Farine de blé', $tableau);
        $this->assertStringContainsString('5 sacs (250 kg)', $tableau);
        $this->assertStringContainsString('10 sacs (500 kg)', $tableau);
    }

    public function testUneAutreJourneeSeRejoueEtUneDateFutureRetombeSurAujourdhui(): void
    {
        $this->jusquAuStockage($this->recevoir(20000), 20000);

        $hier = (new \DateTimeImmutable('yesterday'))->format('Y-m-d');
        $page = $this->client->request('GET', '/magasin?jour='.$hier);
        $this->assertStringContainsString('Aucune livraison', $this->carte($page, 'RECUE'));

        $page = $this->client->request('GET', '/magasin?jour=2999-01-01');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('20 sacs (1 000 kg)', $this->carte($page, 'RECUE'));
    }

    public function testLeMagasinierLitLeTableauSansBoutonDAction(): void
    {
        $magasinier = (new Utilisateur('kouadio@test.ci', 'Kouadio'))->setRoles([RoleUtilisateur::MAGASIN->value])->setMotDePasse('x');
        $this->em->persist($magasinier);
        $this->em->flush();

        $this->client->loginUser($magasinier);
        $page = $this->client->request('GET', '/magasin');
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('Nouvelle réception', $page->filter('main')->text());
        $this->assertStringNotContainsString('Nouvelle sortie', $page->filter('main')->text());

        $this->client->loginUser($this->gerante);
        $this->assertStringContainsString('Nouvelle réception', $this->client->request('GET', '/magasin')->filter('main')->text());
    }
}
