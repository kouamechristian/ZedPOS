<?php

namespace App\Tests\Functional\Magasin;

use App\Entity\Fournisseur;
use App\Entity\Magasin\MagasinEmplacement;
use App\Entity\Magasin\MagasinProduit;
use App\Entity\Magasin\MagasinReception;
use App\Entity\Magasin\MagasinSortie;
use App\Entity\Utilisateur;
use App\Enum\Magasin\CategorieProduitMagasin;
use App\Enum\Magasin\DestinationSortieMagasin;
use App\Enum\Magasin\MotifSortieMagasin;
use App\Enum\RoleUtilisateur;
use App\Service\Magasin\FiltreAnalyseMagasin;
use App\Service\Magasin\MagasinAnalyseService;
use App\Service\Magasin\MagasinStockService;
use App\Service\Magasin\ReceptionMagasinService;
use App\Service\Magasin\SortieMagasinService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Analyse des mouvements et fiche de stock : l'égalité début + entrées −
 * sorties ± ajustements = fin, les annulations déduites, la ventilation par
 * motif, les filtres, les PDF et les droits.
 */
class AnalyseMagasinTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ReceptionMagasinService $receptions;
    private SortieMagasinService $sorties;
    private MagasinStockService $stock;
    private Utilisateur $gerante;
    private Fournisseur $fournisseur;
    private MagasinProduit $farine;
    private MagasinEmplacement $reserve;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->receptions = static::getContainer()->get(ReceptionMagasinService::class);
        $this->sorties = static::getContainer()->get(SortieMagasinService::class);
        $this->stock = static::getContainer()->get(MagasinStockService::class);
        ReceptionMagasinServiceTest::viderTables($this->em);
        $this->em->getConnection()->executeStatement("UPDATE magasin_emplacement SET actif = 1, libelle = 'Réserve principale' WHERE code = 'RESERVE'");

        $this->gerante = (new Utilisateur('mariam@test.ci', 'Mariam'))->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        $this->fournisseur = new Fournisseur('Grands Moulins');
        $this->farine = (new MagasinProduit('Farine de blé', CategorieProduitMagasin::MATIERE, 'kg'))->setUniteAchat('sac')->setContenanceAchat(50000);
        foreach ([$this->gerante, $this->fournisseur, $this->farine] as $entite) {
            $this->em->persist($entite);
        }
        $this->em->flush();
        $this->reserve = $this->stock->reserveParDefaut();
        $this->client->loginUser($this->gerante);
    }

    private function recevoirEtStocker(int $sacs, ?int $prix = null, ?MagasinProduit $produit = null, ?MagasinEmplacement $ou = null): MagasinReception
    {
        $produit ??= $this->farine;
        $r = $this->receptions->creer($this->fournisseur, new \DateTimeImmutable('today'), null, null, [['produit' => $produit, 'annoncee' => $sacs * 1000, 'prix' => $prix]], $this->gerante);
        $id = (int) $r->getLignes()->first()->getId();
        $this->receptions->controler($r, [$id => $sacs * 1000], null, $this->gerante);
        $this->receptions->inspecter($r, [$id => ['acceptee' => $sacs * 1000, 'rejetee' => 0, 'motif' => null]], $this->gerante);
        $this->receptions->stocker($r, [$id => $ou ?? $this->reserve], $this->gerante);

        return $r;
    }

    private function sortir(int $sacs, MotifSortieMagasin $motif = MotifSortieMagasin::PRODUCTION): MagasinSortie
    {
        $s = $this->sorties->creer(new \DateTimeImmutable('today'), DestinationSortieMagasin::ATELIER, $motif, 'Yao', null, [
            ['produit' => $this->farine, 'emplacement' => $this->reserve, 'quantite' => $sacs * 1000, 'enUniteAchat' => true],
        ], $this->gerante);
        $this->sorties->valider($s, $this->gerante);

        return $s;
    }

    private function aujourdhui(): FiltreAnalyseMagasin
    {
        return FiltreAnalyseMagasin::entre(new \DateTimeImmutable('today'), new \DateTimeImmutable('today'));
    }

    /** @return array<string, mixed> la ligne de la farine */
    private function ligneFarine(?FiltreAnalyseMagasin $filtre = null): array
    {
        foreach (static::getContainer()->get(MagasinAnalyseService::class)->analyser($filtre ?? $this->aujourdhui()) as $ligne) {
            if ('Farine de blé' === $ligne['produit']->getNom()) {
                return $ligne;
            }
        }
        $this->fail('Farine absente de l\'analyse.');
    }

    /** Recette du 02/10/2026 : 20 sacs entrés, 3 sortis en production → 17. */
    public function testLaRecetteDuJour(): void
    {
        $this->recevoirEtStocker(20);
        $this->sortir(3);

        $l = $this->ligneFarine();
        $this->assertSame(0, $l['debut']);
        $this->assertSame(1000000, $l['entrees']);
        $this->assertSame(150000, $l['sorties']);
        $this->assertSame(['PRODUCTION' => 150000], $l['sortiesParMotif']);
        $this->assertSame(0, $l['ajustements']);
        $this->assertSame(850000, $l['fin']);

        $page = $this->client->request('GET', '/magasin/analyse?periode=jour');
        $this->assertResponseIsSuccessful();
        $ligne = $page->filter('#analyse-magasin tr[data-produit="Farine de blé"]');
        $this->assertStringContainsString('+20 sacs (1 000 kg)', $ligne->filter('[data-colonne="entrees"]')->text());
        $this->assertStringContainsString('−3 sacs (150 kg)', $ligne->filter('[data-colonne="sorties"]')->text());
        $this->assertStringContainsString('17 sacs (850 kg)', $ligne->filter('[data-colonne="fin"]')->text());
        $this->assertCount(1, $ligne->filter('[data-motif="PRODUCTION"]'));
    }

    /** Ce qui a bougé avant la période fait le stock du début ; l'égalité tient. */
    public function testLeStockDuDebutVientDesJoursPrecedents(): void
    {
        $this->recevoirEtStocker(20);
        $this->em->getConnection()->executeStatement("UPDATE magasin_mouvement SET created_at = DATE_SUB(created_at, INTERVAL 2 DAY)");
        $this->sortir(3);
        $this->sortir(1, MotifSortieMagasin::PERTE_AVARIE);

        $l = $this->ligneFarine();
        $this->assertSame(1000000, $l['debut']);
        $this->assertSame(0, $l['entrees']);
        $this->assertSame(200000, $l['sorties']);
        $this->assertSame(['PRODUCTION' => 150000, 'PERTE_AVARIE' => 50000], $l['sortiesParMotif']);
        $this->assertSame($l['debut'] + $l['entrees'] - $l['sorties'] + $l['ajustements'], $l['fin']);
        $this->assertSame(800000, $l['fin']);
    }

    public function testLesAnnulationsSontDeduitesDeLeurColonne(): void
    {
        $reception = $this->recevoirEtStocker(20);
        $this->recevoirEtStocker(5);
        $sortie = $this->sortir(3);
        $this->sorties->annuler($sortie, 'Sacs rendus', $this->gerante);
        $this->receptions->annuler($reception, 'Doublon', $this->gerante);

        $l = $this->ligneFarine();
        $this->assertSame(250000, $l['entrees'], 'Seule la seconde réception reste.');
        $this->assertSame(0, $l['sorties']);
        $this->assertSame([], $l['sortiesParMotif']);
        $this->assertSame(250000, $l['fin']);
    }

    public function testLesFiltresCategorieEtEmplacement(): void
    {
        $froid = new MagasinEmplacement('FROID', 'Chambre froide');
        $sacs = new MagasinProduit('Sacs kraft', CategorieProduitMagasin::EMBALLAGE, 'pièce');
        $this->em->persist($froid);
        $this->em->persist($sacs);
        $this->em->flush();
        $this->recevoirEtStocker(20);
        $this->recevoirEtStocker(4, null, $this->farine, $froid);
        $this->recevoirEtStocker(100, null, $sacs);

        $analyse = static::getContainer()->get(MagasinAnalyseService::class);
        $jour = new \DateTimeImmutable('today');
        $noms = array_map(static fn (array $l): string => $l['produit']->getNom(), $analyse->analyser(FiltreAnalyseMagasin::entre($jour, $jour, CategorieProduitMagasin::EMBALLAGE)));
        $this->assertSame(['Sacs kraft'], $noms);

        $this->assertSame(200000, $this->ligneFarine(FiltreAnalyseMagasin::entre($jour, $jour, null, $froid))['fin'], 'Seule la chambre froide.');
        $this->assertSame(1200000, $this->ligneFarine()['fin'], 'Tout le magasin.');
    }

    public function testLaValeurNApparaitQuAvecLesPrix(): void
    {
        $this->recevoirEtStocker(20, 2500000); // 25 000 F le sac → 500 F/kg

        $this->assertSame(50000000, $this->ligneFarine()['valeur'], '1 000 kg × 500 F = 500 000 F.');
        $this->assertStringNotContainsString('Valeur', $this->client->request('GET', '/magasin/analyse')->filter('#analyse-magasin')->text());

        $dirigeante = (new Utilisateur('aya@test.ci', 'Aya'))->setRoles(['ROLE_DIRIGEANTE'])->setMotDePasse('x');
        $this->em->persist($dirigeante);
        $this->em->flush();
        $this->client->loginUser($dirigeante);
        $this->assertStringContainsString('500 000 FCFA', $this->client->request('GET', '/magasin/analyse')->filter('#analyse-magasin')->text());
    }

    public function testLaFicheDeStockDonneLeSoldeProgressifEtLesDocuments(): void
    {
        $reception = $this->recevoirEtStocker(20);
        $sortie = $this->sortir(3);

        $fiche = static::getContainer()->get(MagasinAnalyseService::class)->fiche($this->farine, $this->aujourdhui());
        $this->assertSame(0, $fiche['debut']);
        $this->assertSame([1000000, 850000], array_column($fiche['mouvements'], 'solde'));
        $this->assertSame([$reception->getNumero(), $sortie->getNumero()], array_column($fiche['mouvements'], 'numero'));
        $this->assertSame(['Mariam', 'Mariam'], array_column($fiche['mouvements'], 'auteur'));

        $page = $this->client->request('GET', '/magasin/stock/'.$this->farine->getId().'/fiche?periode=jour');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('17 sacs (850 kg)', $page->filter('#stock-fin')->text());
        $this->assertCount(1, $page->filter('#fiche-stock a[href="/magasin/receptions/'.$reception->getId().'"]'));
        $this->assertCount(1, $page->filter('#fiche-stock a[href="/magasin/sorties/'.$sortie->getId().'"]'));
    }

    public function testLesPdfSortentPourLeMagasinierAussi(): void
    {
        $this->recevoirEtStocker(20);
        $magasinier = (new Utilisateur('kouadio@test.ci', 'Kouadio'))->setRoles([RoleUtilisateur::MAGASIN->value])->setMotDePasse('x');
        $this->em->persist($magasinier);
        $this->em->flush();
        $this->client->loginUser($magasinier);

        foreach (['/magasin/analyse.pdf?periode=jour', '/magasin/stock/'.$this->farine->getId().'/fiche.pdf?periode=jour'] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful($url);
            $this->assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'), $url);
            $this->assertStringStartsWith('%PDF', (string) $this->client->getResponse()->getContent());
        }
        $this->assertStringContainsString('fiche-stock_farine-de-ble_', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        $this->client->request('GET', '/magasin/analyse');
        $this->assertResponseIsSuccessful();
    }

    public function testLeCaissierNEntrePasDansLAnalyse(): void
    {
        $caissier = (new Utilisateur('fatou@test.ci', 'Fatou'))->setRoles(['ROLE_CAISSIER'])->setCodePin('x');
        $this->em->persist($caissier);
        $this->em->flush();
        $this->client->loginUser($caissier);

        $this->client->request('GET', '/magasin/analyse');
        $this->assertResponseStatusCodeSame(403);
    }
}
