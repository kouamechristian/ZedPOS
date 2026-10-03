<?php

namespace App\Tests\Functional\Magasin;

use App\Entity\Magasin\MagasinEmplacement;
use App\Entity\Magasin\MagasinProduit;
use App\Entity\Magasin\MagasinSortie;
use App\Entity\Utilisateur;
use App\Enum\Magasin\CategorieProduitMagasin;
use App\Enum\Magasin\StatutSortieMagasin;
use App\Enum\Magasin\TypeMouvementMagasin;
use App\Service\Magasin\DemandeMouvementMagasin;
use App\Service\Magasin\MagasinStockService;
use App\Service\Magasin\QuantiteMagasin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * L'écran de sortie au doigt : brouillon ou validation d'un geste, refus au-delà
 * du stock avec la saisie gardée en brouillon, impression, annulation.
 */
class SortieEcranTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Utilisateur $gerante;
    private MagasinProduit $farine;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ReceptionMagasinServiceTest::viderTables($this->em);
        $this->em->getConnection()->executeStatement("UPDATE magasin_emplacement SET actif = 1, libelle = 'Réserve principale' WHERE code = 'RESERVE'");

        $this->gerante = (new Utilisateur('mariam@test.ci', 'Mariam'))->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        $this->farine = (new MagasinProduit('Farine de blé', CategorieProduitMagasin::MATIERE, 'kg'))->setUniteAchat('sac')->setContenanceAchat(50000);
        $this->em->persist($this->gerante);
        $this->em->persist($this->farine);
        $this->em->flush();

        $stock = static::getContainer()->get(MagasinStockService::class);
        $stock->appliquer([new DemandeMouvementMagasin($this->farine, $stock->reserveParDefaut(), 1000000, TypeMouvementMagasin::ENTREE_RECEPTION, 'reception', 1)], $this->gerante);
        $this->client->loginUser($this->gerante);
    }

    private function jeton(string $url): string
    {
        $crawler = $this->client->request('GET', $url);
        $this->assertResponseIsSuccessful($url);

        return (string) $crawler->filter('input[name="_token"]')->attr('value');
    }

    private function saisir(string $quantite, string $action = 'valider', string $unite = 'achat'): void
    {
        $this->client->request('POST', '/magasin/sorties/nouvelle', [
            '_token' => $this->jeton('/magasin/sorties/nouvelle'),
            'destination' => 'ATELIER',
            'motif' => 'PRODUCTION',
            'demande_par' => 'Yao',
            'date' => '2026-10-02',
            'action' => $action,
            'lignes' => [0 => ['produit' => (string) $this->farine->getId(), 'quantite' => $quantite, 'unite' => $unite]],
        ]);
    }

    private function sortie(): MagasinSortie
    {
        $this->em->clear();

        return $this->em->getRepository(MagasinSortie::class)->findOneBy([]) ?? $this->fail('Aucune sortie.');
    }

    private function stockFarine(): string
    {
        $farine = $this->em->getRepository(MagasinProduit::class)->find($this->farine->getId());

        return QuantiteMagasin::formater($farine, static::getContainer()->get(MagasinStockService::class)->stockTotal($farine));
    }

    public function testLEcranAnnonceLeDisponibleEtValideDUnGeste(): void
    {
        $crawler = $this->client->request('GET', '/magasin/sorties/nouvelle');
        $this->assertCount(4, $crawler->filter('input[name="destination"]'));
        $this->assertCount(0, $crawler->filter('select[name$="[emplacement]"]'), 'Une seule zone : pas de question.');
        $this->assertStringContainsString('20 sacs (1 000 kg)', (string) $crawler->filter('form')->attr('data-sortie-lignes-infos-value'));

        $this->saisir('3');
        $sortie = $this->sortie();
        $this->assertResponseRedirects('/magasin/sorties/'.$sortie->getId(), 303);
        $this->assertSame(StatutSortieMagasin::VALIDEE, $sortie->getStatut());
        $this->assertSame('17 sacs (850 kg)', $this->stockFarine());

        $crawler = $this->client->followRedirect();
        $this->assertStringContainsString('3 sacs', $crawler->filter('#lignes-sortie')->text());
        $this->assertStringContainsString('150 kg', $crawler->filter('#lignes-sortie')->text());
    }

    public function testUneSortieAuDelaDuStockResteEnBrouillonAvecLeMessage(): void
    {
        $this->saisir('30');
        $sortie = $this->sortie();

        $this->assertResponseRedirects('/magasin/sorties/'.$sortie->getId().'/modifier', 303);
        $this->assertSame(StatutSortieMagasin::BROUILLON, $sortie->getStatut());
        $this->assertSame('20 sacs (1 000 kg)', $this->stockFarine());

        $crawler = $this->client->followRedirect();
        $this->assertStringContainsString('Stock insuffisant', $crawler->text());
        $this->assertSame('30', $crawler->filter('input[name="lignes[0][quantite]"]')->attr('value'), 'Ce qui a été tapé est gardé.');
    }

    public function testLeBrouillonPuisLaValidationDepuisLaFiche(): void
    {
        $this->saisir('12,5', 'brouillon', 'stock');
        $sortie = $this->sortie();
        $this->assertSame(StatutSortieMagasin::BROUILLON, $sortie->getStatut());
        $this->assertSame(12500, $sortie->getLignes()->first()->getQuantite());
        $this->assertSame('20 sacs (1 000 kg)', $this->stockFarine());

        $id = $sortie->getId();
        $this->client->request('POST', "/magasin/sorties/$id/valider", ['_token' => $this->jeton("/magasin/sorties/$id")]);
        $this->assertResponseRedirects("/magasin/sorties/$id", 303);
        $this->assertSame(987500, static::getContainer()->get(MagasinStockService::class)->stockTotal($this->em->getRepository(MagasinProduit::class)->find($this->farine->getId())));
    }

    public function testUneSaisieIllisibleEstReafficheeEn422(): void
    {
        $this->saisir('trois');

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame('trois', $this->client->getCrawler()->filter('input[name="lignes[0][quantite]"]')->attr('value'));
        $this->assertSame(0, $this->em->getRepository(MagasinSortie::class)->count([]));
    }

    public function testUneSortieValideeNeSeReprendPasEtSAnnule(): void
    {
        $this->saisir('3');
        $id = $this->sortie()->getId();

        $this->client->request('GET', "/magasin/sorties/$id/modifier");
        $this->assertResponseRedirects("/magasin/sorties/$id", 303);

        $this->client->request('POST', "/magasin/sorties/$id/annuler", ['_token' => $this->jeton("/magasin/sorties/$id"), 'motif' => 'Sacs rendus']);
        $this->assertSame(StatutSortieMagasin::ANNULEE, $this->sortie()->getStatut());
        $this->assertSame('20 sacs (1 000 kg)', $this->stockFarine());
    }

    public function testLeBonSImprimeEtLaListeFiltre(): void
    {
        $this->saisir('3');
        $id = $this->sortie()->getId();

        $crawler = $this->client->request('GET', "/magasin/sorties/$id/bon");
        $this->assertResponseIsSuccessful();
        $texte = $crawler->filter('body')->text();
        $this->assertStringContainsString('SOR-2026-0001', $texte);
        $this->assertStringContainsString('Atelier', $texte);
        $this->assertStringContainsString('3 sacs (150 kg)', $texte);
        $this->assertStringNotContainsString('Valeur', $texte, 'La gérante ne voit pas les prix.');

        $crawler = $this->client->request('GET', '/magasin/sorties?statut=VALIDEE');
        $this->assertStringContainsString('SOR-2026-0001', $crawler->filter('#sorties-magasin')->text());
        $crawler = $this->client->request('GET', '/magasin/sorties?statut=BROUILLON');
        $this->assertStringNotContainsString('SOR-2026-0001', $crawler->filter('#sorties-magasin')->text());
    }

    public function testPlusieursZonesFontApparaitreLEmplacement(): void
    {
        $this->em->persist(new MagasinEmplacement('FROID', 'Chambre froide'));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/magasin/sorties/nouvelle');
        $this->assertCount(1, $crawler->filter('select[name="lignes[0][emplacement]"]'));
    }

    public function testLeCaissierNEntrePasDansLesSorties(): void
    {
        $caissier = (new Utilisateur('fatou@test.ci', 'Fatou'))->setRoles(['ROLE_CAISSIER'])->setCodePin('x');
        $this->em->persist($caissier);
        $this->em->flush();

        $this->client->loginUser($caissier);
        $this->client->request('GET', '/magasin/sorties');
        $this->assertResponseStatusCodeSame(403);
    }
}
