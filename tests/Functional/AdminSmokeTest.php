<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\FamilleProduit;
use App\Entity\FicheTechnique;
use App\Entity\LigneFicheTechnique;
use App\Entity\MatierePremiere;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminSmokeTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $articleId;
    private int $matiereId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $connexion = $this->em->getConnection();
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['ligne_fiche_technique', 'fiche_technique', 'ligne_vente', 'reglement', 'vente', 'mouvement_caisse', 'session_caisse', 'mouvement_stock', 'perte', 'article', 'matiere_premiere', 'fournisseur', 'famille_produit', 'journal_audit', 'utilisateur'] as $table) {
            $connexion->executeStatement('DELETE FROM '.$table);
        }
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $gerant = new Utilisateur('gerant@test.ci', 'Gérant Test');
        $gerant->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        $this->em->persist($gerant);

        $famille = new FamilleProduit('Pains');
        $this->em->persist($famille);

        $matiere = (new MatierePremiere('Farine', 'kg'))
            ->setStockActuel(300000)   // 300 kg en millièmes (BIGINT)
            ->setStockMini(50000)
            ->setCoutMoyenPondere(45000);
        $this->em->persist($matiere);

        $article = new Article('Baguette', 15000, 'pièce');
        $article->setFamilleProduit($famille)->setTauxTva(0);
        $this->em->persist($article);

        $fiche = new FicheTechnique($article);
        new LigneFicheTechnique($fiche, $matiere, 250, 300); // quantité BIGINT
        $this->em->persist($fiche);

        $this->em->flush();

        $this->articleId = $article->getId();
        $this->matiereId = $matiere->getId();

        $this->client->loginUser($gerant);
    }

    #[DataProvider('pagesAdmin')]
    public function testPagesAdminAccessibles(string $url): void
    {
        $this->client->request('GET', $url);
        $this->assertResponseIsSuccessful('Échec du rendu de '.$url);
    }

    public static function pagesAdmin(): array
    {
        return [
            ['/admin'],
            ['/admin/ventes'],
            ['/admin/articles'],
            ['/admin/stock'],          // matières premières (hydratation BIGINT)
            ['/admin/production'],     // fiches (hydratation BIGINT des lignes)
            ['/admin/pertes'],
            ['/admin/clotures'],
            ['/admin/utilisateurs'],
            ['/admin/familles'],
            ['/admin/fournisseurs'],
            ['/admin/articles/nouveau'],
            ['/admin/stock/nouvelle'],
            ['/admin/fournisseurs/nouveau'],
        ];
    }

    public function testCaissierNePeutPasVoirLesCouts(): void
    {
        // Un caissier ne doit jamais accéder au back-office (donc jamais aux coûts/marges).
        $caissier = new Utilisateur('caissier@test.ci', 'Caissier Test');
        $caissier->setRoles(['ROLE_CAISSIER'])->setCodePin('x');
        $this->em->persist($caissier);
        $this->em->flush();

        $this->client->loginUser($caissier);
        $this->client->request('GET', '/admin/articles');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testColonneCoutMargeVisiblePourLeGerant(): void
    {
        $crawler = $this->client->request('GET', '/admin/articles');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('thead', 'Coût / Marge');
    }

    public function testFiltreArticleParRecherche(): void
    {
        $crawler = $this->client->request('GET', '/admin/articles?q=Baguette');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('table', 'Baguette');

        $crawler = $this->client->request('GET', '/admin/articles?q=Inexistant');
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('Baguette', $crawler->filter('tbody')->text());
    }

    public function testBasculerActivationArticle(): void
    {
        $crawler = $this->client->request('GET', '/admin/articles');
        $this->client->submit($crawler->filter('form[action$="/basculer"]')->form());
        $this->assertResponseStatusCodeSame(302);

        $this->em->clear();
        $this->assertFalse($this->em->getRepository(Article::class)->find($this->articleId)->isActif());
    }

    public function testAjoutLigneFicheTechnique(): void
    {
        // Nouvelle matière à rattacher à la fiche.
        $beurre = (new MatierePremiere('Beurre', 'kg'))->setCoutMoyenPondere(350000);
        $this->em->persist($beurre);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/articles/'.$this->articleId);
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form[action$="/fiche/lignes"]')->form();
        $form['ligne_fiche_technique[matierePremiere]'] = (string) $beurre->getId();
        $form['ligne_fiche_technique[quantite]'] = '0,2';
        $form['ligne_fiche_technique[pourcentagePerte]'] = '2';
        $this->client->submit($form);

        $this->assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        $this->assertSelectorTextContains('#fiche_technique', 'Beurre');
    }
}
