<?php

namespace App\Tests\Functional\Magasin;

use App\Entity\Magasin\MagasinEmplacement;
use App\Entity\Magasin\MagasinProduit;
use App\Entity\Utilisateur;
use App\Enum\Magasin\CategorieProduitMagasin;
use App\Enum\Magasin\TypeMouvementMagasin;
use App\Enum\RoleUtilisateur;
use App\Service\Magasin\AlertesStockMagasin;
use App\Service\Magasin\DemandeMouvementMagasin;
use App\Service\Magasin\MagasinStockService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Alertes de seuil du magasin : stock ≤ seuil, relues sur le stock à chaque
 * page, montrées à la gérante, à la dirigeante et au magasinier — bannière,
 * compteur du menu, pilotage, et avertissement à la sortie qui fait atteindre
 * le seuil.
 */
class AlertesSeuilMagasinTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private MagasinStockService $stock;
    private Utilisateur $gerante;
    private MagasinEmplacement $reserve;
    private MagasinProduit $farine;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->stock = static::getContainer()->get(MagasinStockService::class);
        ReceptionMagasinServiceTest::viderTables($this->em);
        $this->em->getConnection()->executeStatement("UPDATE magasin_emplacement SET actif = 1, libelle = 'Réserve principale' WHERE code = 'RESERVE'");

        $this->gerante = (new Utilisateur('mariam@test.ci', 'Mariam'))->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        // Seuil : 10 sacs (500 kg).
        $this->farine = (new MagasinProduit('Farine de blé', CategorieProduitMagasin::MATIERE, 'kg'))
            ->setUniteAchat('sac')->setContenanceAchat(50000)->setSeuilAlerte(500000);
        $this->em->persist($this->gerante);
        $this->em->persist($this->farine);
        $this->em->flush();
        $this->reserve = $this->stock->reserveParDefaut();
    }

    private function mouvement(MagasinProduit $produit, int $millimes): void
    {
        $this->stock->appliquer([new DemandeMouvementMagasin(
            $produit,
            $this->reserve,
            $millimes,
            $millimes > 0 ? TypeMouvementMagasin::ENTREE_RECEPTION : TypeMouvementMagasin::SORTIE,
            $millimes > 0 ? 'reception' : 'sortie',
            1,
        )], $this->gerante);
    }

    private function produitsEnAlerte(): array
    {
        $this->em->clear();

        return array_map(static fn (array $l): string => $l['produit']->getNom(), static::getContainer()->get(AlertesStockMagasin::class)->parmi($this->em->getRepository(MagasinProduit::class)->findAll()));
    }

    public function testLeSeuilAtteintDeclencheLAlerteEtPasAuDessus(): void
    {
        $this->mouvement($this->farine, 550000); // 11 sacs
        $this->assertSame([], $this->produitsEnAlerte(), '11 sacs pour un seuil de 10 : pas d\'alerte.');

        $this->mouvement($this->farine, -50000); // 10 sacs
        $this->assertSame(['Farine de blé'], $this->produitsEnAlerte(), 'Seuil atteint : 10 sacs pour un seuil de 10.');
    }

    public function testUnProduitInactifOuSansSeuilNAlertePas(): void
    {
        $sel = new MagasinProduit('Sel', CategorieProduitMagasin::MATIERE, 'kg'); // seuil 0
        $levure = (new MagasinProduit('Levure', CategorieProduitMagasin::MATIERE, 'kg'))->setSeuilAlerte(1000);
        $levure->setActif(false);
        $this->em->persist($sel);
        $this->em->persist($levure);
        $this->em->flush();

        $this->assertSame(['Farine de blé'], $this->produitsEnAlerte(), 'Seule la farine, à zéro sous un seuil de 10 sacs.');
    }

    public function testLeProduitLePlusBasPasseEnPremier(): void
    {
        $sucre = (new MagasinProduit('Sucre', CategorieProduitMagasin::MATIERE, 'kg'))->setSeuilAlerte(10000);
        $this->em->persist($sucre);
        $this->em->flush();
        $this->mouvement($this->farine, 450000); // 90 % du seuil
        $this->mouvement($sucre, 1000);          // 10 % du seuil

        $this->assertSame(['Sucre', 'Farine de blé'], $this->produitsEnAlerte());
    }

    public function testLaBanniereEtLeCompteurPourLaGeranteEtLeMagasinier(): void
    {
        $this->mouvement($this->farine, 250000); // 5 sacs
        $magasinier = (new Utilisateur('kouadio@test.ci', 'Kouadio'))->setRoles([RoleUtilisateur::MAGASIN->value])->setMotDePasse('x');
        $this->em->persist($magasinier);
        $this->em->flush();

        foreach ([[$this->gerante, '/admin/ventes'], [$this->gerante, '/magasin/stock'], [$magasinier, '/magasin/sorties']] as [$qui, $url]) {
            $this->client->loginUser($qui);
            $page = $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful($url);
            $banniere = $page->filter('#alerte-seuil-magasin');
            $this->assertCount(1, $banniere, $qui->getNom().' sur '.$url);
            $this->assertStringContainsString('Farine de blé (5 sacs (250 kg), seuil 10 sacs (500 kg))', $banniere->text());
            $this->assertSame('1', trim($page->filter('aside nav [data-compteur-seuil]')->text()));
        }

        // Le tableau de bord la montre déjà en tableau : pas de bannière en double.
        $page = $this->client->request('GET', '/magasin');
        $this->assertCount(0, $page->filter('#alerte-seuil-magasin'));
        $this->assertStringContainsString('Farine de blé', $page->filter('#sous-seuil')->text());
    }

    public function testLaDirigeanteEstAlerteeAuPilotage(): void
    {
        $dirigeante = (new Utilisateur('aya@test.ci', 'Aya'))->setRoles(['ROLE_DIRIGEANTE'])->setMotDePasse('x');
        $this->em->persist($dirigeante);
        $this->em->flush();

        $this->client->loginUser($dirigeante);
        $page = $this->client->request('GET', '/pilotage');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('au seuil d\'alerte', $page->filter('#alerte-seuil-magasin')->text());
    }

    public function testLaBanniereDisparaitQuandLeStockRemonte(): void
    {
        $this->client->loginUser($this->gerante);
        $this->assertCount(1, $this->client->request('GET', '/magasin/stock')->filter('#alerte-seuil-magasin'));

        $this->mouvement($this->farine, 1000000); // 20 sacs
        $page = $this->client->request('GET', '/magasin/stock');
        $this->assertCount(0, $page->filter('#alerte-seuil-magasin'));
        $this->assertCount(0, $page->filter('aside nav [data-compteur-seuil]'));
    }

    public function testLaSortieQuiFaitAtteindreLeSeuilLeDitToutDeSuite(): void
    {
        $this->mouvement($this->farine, 600000); // 12 sacs
        $this->client->loginUser($this->gerante);

        $page = $this->client->request('GET', '/magasin/sorties/nouvelle');
        $this->client->request('POST', '/magasin/sorties/nouvelle', [
            '_token' => (string) $page->filter('input[name="_token"]')->attr('value'),
            'destination' => 'ATELIER',
            'motif' => 'PRODUCTION',
            'date' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'action' => 'valider',
            'lignes' => [0 => ['produit' => (string) $this->farine->getId(), 'quantite' => '3', 'unite' => 'achat']],
        ]);
        $page = $this->client->followRedirect();

        $this->assertStringContainsString('Seuil d\'alerte atteint : « Farine de blé » — il reste 9 sacs (450 kg) (seuil 10 sacs (500 kg)).', $page->text());
    }
}
