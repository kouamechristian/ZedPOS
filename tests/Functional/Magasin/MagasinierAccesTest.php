<?php

namespace App\Tests\Functional\Magasin;

use App\Entity\Fournisseur;
use App\Entity\Magasin\MagasinProduit;
use App\Entity\Utilisateur;
use App\Enum\Magasin\CategorieProduitMagasin;
use App\Enum\Magasin\DestinationSortieMagasin;
use App\Enum\Magasin\MotifSortieMagasin;
use App\Enum\RoleUtilisateur;
use App\Security\RoleRedirectionHandler;
use App\Service\Magasin\ReceptionMagasinService;
use App\Service\Magasin\SortieMagasinService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le magasinier (ROLE_MAGASIN) consulte le magasin et rien d'autre : il lit les
 * listes, les fiches et les bons, n'a aucun bouton d'action, et chaque geste lui
 * est refusé même en forgeant l'adresse. Le reste du back-office lui est fermé.
 * Seules la gérante et la dirigeante agissent.
 */
class MagasinierAccesTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Utilisateur $magasinier;
    private Utilisateur $gerante;
    private int $reception;
    private int $sortie;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ReceptionMagasinServiceTest::viderTables($this->em);

        $this->magasinier = (new Utilisateur('kouadio@test.ci', 'Kouadio'))->setRoles([RoleUtilisateur::MAGASIN->value])->setMotDePasse('x');
        $this->gerante = (new Utilisateur('mariam@test.ci', 'Mariam'))->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        $fournisseur = new Fournisseur('Grands Moulins');
        $farine = (new MagasinProduit('Farine de blé', CategorieProduitMagasin::MATIERE, 'kg'))->setUniteAchat('sac')->setContenanceAchat(50000);
        foreach ([$this->magasinier, $this->gerante, $fournisseur, $farine] as $entite) {
            $this->em->persist($entite);
        }
        $this->em->flush();

        $this->reception = (int) static::getContainer()->get(ReceptionMagasinService::class)
            ->creer($fournisseur, new \DateTimeImmutable('2026-10-02'), 'BL-1', null, [['produit' => $farine, 'annoncee' => 20000]], $this->gerante)
            ->getId();
        $this->sortie = (int) static::getContainer()->get(SortieMagasinService::class)
            ->creer(new \DateTimeImmutable('2026-10-02'), DestinationSortieMagasin::ATELIER, MotifSortieMagasin::PRODUCTION, 'Yao', null, [
                ['produit' => $farine, 'emplacement' => static::getContainer()->get(\App\Service\Magasin\MagasinStockService::class)->reserveParDefaut(), 'quantite' => 1000, 'enUniteAchat' => true],
            ], $this->gerante)
            ->getId();
    }

    public function testLeMagasinierArriveSurLeTableauDeBordDuMagasin(): void
    {
        $url = static::getContainer()->get(RoleRedirectionHandler::class)->urlPour($this->magasinier);
        $this->assertSame('/magasin', $url);
        $this->assertFalse(RoleUtilisateur::MAGASIN->utiliseCodePin(), 'Connexion par mot de passe, comme le comptable.');
    }

    public function testLeMagasinierConsulteToutLeMagasin(): void
    {
        $this->client->loginUser($this->magasinier);

        foreach ([
            '/magasin',
            '/magasin/stock',
            '/magasin/receptions',
            "/magasin/receptions/{$this->reception}",
            "/magasin/receptions/{$this->reception}/bon",
            '/magasin/sorties',
            "/magasin/sorties/{$this->sortie}",
            "/magasin/sorties/{$this->sortie}/bon",
            '/magasin/produits',
            '/magasin/emplacements',
        ] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful('Consultation : '.$url);
        }
    }

    public function testLeMagasinierNAAucunBoutonDAction(): void
    {
        $this->client->loginUser($this->magasinier);

        $pages = [
            '/magasin/receptions' => ['Nouvelle réception'],
            "/magasin/receptions/{$this->reception}" => ['Annuler cette réception', 'À faire →'],
            '/magasin/sorties' => ['Nouvelle sortie', 'Reprendre'],
            "/magasin/sorties/{$this->sortie}" => ['Valider la sortie', 'Annuler cette sortie', 'Modifier'],
            '/magasin/produits' => ['Nouveau produit', 'Modifier'],
            '/magasin/emplacements' => ['Nouvel emplacement', 'Modifier'],
        ];
        foreach ($pages as $url => $interdits) {
            $texte = $this->client->request('GET', $url)->filter('main')->text();
            foreach ($interdits as $libelle) {
                $this->assertStringNotContainsString($libelle, $texte, "« $libelle » ne doit pas apparaître sur $url");
            }
        }
        $this->assertCount(0, $this->client->request('GET', "/magasin/sorties/{$this->sortie}")->filter('main form'));
        // « Contrôle » reste lisible dans la colonne « À faire » : c'est le lien vers l'étape qui disparaît.
        $this->assertCount(0, $this->client->request('GET', '/magasin/receptions')->filter('main a[href$="/controle"]'));
    }

    /** Même en forgeant l'adresse, chaque geste est refusé — avant tout jeton CSRF. */
    public function testChaqueGesteLuiEstRefuse(): void
    {
        $this->client->loginUser($this->magasinier);

        $gets = [
            '/magasin/receptions/nouvelle',
            "/magasin/receptions/{$this->reception}/annonce",
            "/magasin/receptions/{$this->reception}/controle",
            '/magasin/sorties/nouvelle',
            "/magasin/sorties/{$this->sortie}/modifier",
            '/magasin/produits/nouveau',
            '/magasin/emplacements/nouveau',
        ];
        foreach ($gets as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseStatusCodeSame(403, 'GET '.$url);
        }

        $posts = [
            "/magasin/receptions/{$this->reception}/controle",
            "/magasin/receptions/{$this->reception}/annuler",
            "/magasin/sorties/{$this->sortie}/valider",
            "/magasin/sorties/{$this->sortie}/annuler",
        ];
        foreach ($posts as $url) {
            $this->client->request('POST', $url, ['motif' => 'essai']);
            $this->assertResponseStatusCodeSame(403, 'POST '.$url);
        }
    }

    public function testLeMenuNeMontreQueLeMagasin(): void
    {
        $this->client->loginUser($this->magasinier);
        $crawler = $this->client->request('GET', '/magasin/stock');

        $liens = $crawler->filter('aside nav a')->each(static fn ($lien): string => (string) $lien->attr('href'));
        $this->assertNotEmpty($liens);
        foreach ($liens as $lien) {
            $this->assertStringStartsWith('/magasin', $lien, 'Hors du magasin : '.$lien);
        }
        $palette = $crawler->filter('dialog.palette a')->each(static fn ($lien): string => (string) $lien->attr('href'));
        $this->assertSame($liens, $palette);
    }

    public function testLeResteDuBackOfficeLuiEstFerme(): void
    {
        $this->client->loginUser($this->magasinier);

        foreach (['/admin', '/admin/ventes', '/admin/dotations', '/pilotage', '/caisse', '/atelier', '/comptabilite'] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseStatusCodeSame(403, $url);
        }
    }

    public function testLaGeranteGardeLaMain(): void
    {
        $this->client->loginUser($this->gerante);

        $texte = $this->client->request('GET', '/magasin/receptions')->filter('main')->text();
        $this->assertStringContainsString('Nouvelle réception', $texte);
        $this->client->request('GET', '/magasin/sorties/nouvelle');
        $this->assertResponseIsSuccessful();

        $liens = $this->client->request('GET', '/admin')->filter('aside nav a')->each(static fn ($lien): string => (string) $lien->attr('href'));
        $this->assertContains('/admin/ventes', $liens);
        $this->assertContains('/magasin/receptions', $liens);
    }
}
