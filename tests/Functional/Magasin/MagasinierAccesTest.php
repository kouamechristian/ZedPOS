<?php

namespace App\Tests\Functional\Magasin;

use App\Entity\Fournisseur;
use App\Entity\Magasin\MagasinProduit;
use App\Entity\Utilisateur;
use App\Enum\Magasin\CategorieProduitMagasin;
use App\Enum\Magasin\DestinationSortieMagasin;
use App\Enum\Magasin\MotifSortieMagasin;
use App\Enum\RoleUtilisateur;
use App\Security\Permission;
use App\Security\RoleRedirectionHandler;
use App\Service\Magasin\ReceptionMagasinService;
use App\Service\Magasin\SortieMagasinService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Le magasinier (ROLE_MAGASIN) enregistre au magasin : réceptions, sorties,
 * inventaires, et crée les produits nouveaux. Il ne modifie pas un produit
 * existant ni les emplacements, ne voit aucun prix, et un inventaire avec écart
 * reste à la dirigeante. Le reste du back-office lui est fermé.
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

    /** Ses permissions, telles que le voter les accorde. */
    public function testSesPermissions(): void
    {
        // Le voter interroge `Security`, qui lit le jeton de la session : on l'y pose.
        static::getContainer()->get(TokenStorageInterface::class)
            ->setToken(new UsernamePasswordToken($this->magasinier, 'main', $this->magasinier->getRoles()));
        $droits = static::getContainer()->get(AuthorizationCheckerInterface::class);

        $this->assertTrue($droits->isGranted(Permission::MAGASIN_VOIR));
        $this->assertTrue($droits->isGranted(Permission::MAGASIN_GERER));
        $this->assertTrue($droits->isGranted(Permission::MAGASIN_PRODUIT_CREER));
        $this->assertTrue($droits->isGranted(Permission::MAGASIN_PRODUIT_MODIFIER));
        $this->assertFalse($droits->isGranted(Permission::MAGASIN_REFERENTIEL));
        $this->assertFalse($droits->isGranted(Permission::MAGASIN_VOIR_PRIX));
        $this->assertFalse($droits->isGranted(Permission::MAGASIN_VALIDER_ECART));
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

    public function testLeMagasinierEnregistreReceptionsEtSorties(): void
    {
        $this->client->loginUser($this->magasinier);

        $pages = [
            '/magasin/receptions' => ['Nouvelle réception'],
            '/magasin/sorties' => ['Nouvelle sortie'],
            "/magasin/sorties/{$this->sortie}" => ['Valider la sortie'],
        ];
        foreach ($pages as $url => $attendus) {
            $texte = $this->client->request('GET', $url)->filter('main')->text();
            foreach ($attendus as $libelle) {
                $this->assertStringContainsString($libelle, $texte, "« $libelle » doit apparaître sur $url");
            }
        }
        $this->assertGreaterThan(0, $this->client->request('GET', '/magasin/receptions')->filter('main a[href$="/controle"]')->count());

        foreach ([
            '/magasin/receptions/nouvelle',
            "/magasin/receptions/{$this->reception}/controle",
            '/magasin/sorties/nouvelle',
            "/magasin/sorties/{$this->sortie}/modifier",
        ] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful('Saisie : '.$url);
        }
    }

    /** Il enregistre, mais sans un seul prix : le prix d'achat reste à la dirigeante. */
    public function testAucunPrixNApparaitDansSesSaisies(): void
    {
        $this->client->loginUser($this->magasinier);

        $texte = $this->client->request('GET', '/magasin/receptions/nouvelle')->filter('main')->text();
        $this->assertStringNotContainsString('Prix d\'achat', $texte);
        $this->assertCount(0, $this->client->getCrawler()->filter('input[name*="[prix]"]'));
    }

    /** Un article qui arrive pour la première fois : le magasinier l'inscrit lui-même. */
    public function testLeMagasinierCreeUnProduit(): void
    {
        $this->client->loginUser($this->magasinier);

        $page = $this->client->request('GET', '/magasin/produits');
        $this->assertStringContainsString('Nouveau produit', $page->filter('main')->text());

        $form = $this->client->request('GET', '/magasin/produits/nouveau')->selectButton('Enregistrer')->form();
        $this->assertStringNotContainsString('Coût', $this->client->getCrawler()->filter('main')->text(), 'Aucun prix dans le formulaire.');
        $this->client->submit($form, [
            'produit[nom]' => 'Sucre en poudre',
            'produit[uniteStock]' => 'kg',
        ]);
        $this->assertResponseRedirects('/magasin/produits', 303);

        $sucre = $this->em->getRepository(MagasinProduit::class)->findOneBy(['nom' => 'Sucre en poudre']);
        $this->assertNotNull($sucre);
        $trace = $this->em->getRepository(\App\Entity\JournalAudit::class)->findOneBy(['entiteId' => $sucre->getId(), 'entite' => 'MagasinProduit']);
        $this->assertNotNull($trace, 'La création est tracée au journal d\'audit.');
    }

    /** Il modifie un produit existant — sans prix, et la modification est tracée. */
    public function testLeMagasinierModifieUnProduit(): void
    {
        $this->client->loginUser($this->magasinier);
        $farine = $this->em->getRepository(MagasinProduit::class)->findOneBy(['nom' => 'Farine de blé']);
        $id = $farine->getId();

        $this->assertStringContainsString('Modifier', $this->client->request('GET', '/magasin/produits')->filter('main')->text());

        $form = $this->client->request('GET', "/magasin/produits/{$id}/modifier")->selectButton('Enregistrer')->form();
        $this->assertStringNotContainsString('Coût', $this->client->getCrawler()->filter('main')->text(), 'Aucun prix dans le formulaire.');
        $this->client->submit($form, ['produit[nom]' => 'Farine de blé T55']);
        $this->assertResponseRedirects('/magasin/produits', 303);

        $this->em->clear();
        $this->assertSame('Farine de blé T55', $this->em->getRepository(MagasinProduit::class)->find($id)->getNom());
        $traces = $this->em->getRepository(\App\Entity\JournalAudit::class)->findBy(['entiteId' => $id, 'entite' => 'MagasinProduit']);
        $this->assertNotEmpty($traces, 'La modification est tracée au journal d\'audit.');
    }

    /** Gérer les emplacements reste à la gérante, même en forgeant l'adresse. */
    public function testLesEmplacementsLuiRestentFermes(): void
    {
        $this->client->loginUser($this->magasinier);

        $texte = $this->client->request('GET', '/magasin/emplacements')->filter('main')->text();
        foreach (['Nouvel emplacement', 'Modifier'] as $libelle) {
            $this->assertStringNotContainsString($libelle, $texte, "« $libelle » ne doit pas apparaître sur /magasin/emplacements");
        }
        $this->client->request('GET', '/magasin/emplacements/nouveau');
        $this->assertResponseStatusCodeSame(403);
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
