<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\FamilleProduit;
use App\Entity\FicheTechnique;
use App\Entity\LigneFicheTechnique;
use App\Entity\MatierePremiere;
use App\Entity\Utilisateur;
use App\Repository\Pagination;
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

    /**
     * La fiche technique vit dans un **onglet** de la fiche article, et la liste
     * n'offrait longtemps que « Désactiver » et « Modifier » : le seul accès était
     * le nom de l'article, un lien qui ne se souligne qu'au survol. Rien n'y
     * indiquait qu'on pouvait entrer, et l'écran Production — l'endroit où l'on
     * pense naturellement à chercher — ne fait que consulter.
     */
    public function testLaListeDArticlesMeneALaFicheTechnique(): void
    {
        $crawler = $this->client->request('GET', '/admin/articles');
        $this->assertResponseIsSuccessful();

        $lien = $crawler->filter('a:contains("Fiche technique")');
        $this->assertCount(1, $lien, 'Chaque ligne doit offrir un accès à sa fiche technique.');
        $this->assertStringContainsString('onglet=fiche', (string) $lien->attr('href'));
        // Le lien sort du frame : la fiche article remplace la page entière.
        $this->assertSame('_top', $lien->attr('data-turbo-frame'));
    }

    /**
     * Les onglets sont en CSS pur, donc sans URL propre. Sans ce paramètre, tout
     * lien venant d'ailleurs retomberait sur « Détails » et le bouton précédent
     * ne servirait à rien.
     */
    public function testLOngletFicheSOuvreDirectementParLUrl(): void
    {
        $crawler = $this->client->request('GET', '/admin/articles/'.$this->articleId.'?onglet=fiche');
        $this->assertResponseIsSuccessful();

        $this->assertNotNull($crawler->filter('#onglet-fiche')->attr('checked'), 'L\'onglet Fiche technique doit être ouvert.');
        $this->assertNull($crawler->filter('#onglet-details')->attr('checked'));
    }

    public function testSansParametreLOngletDetailsResteLeDefaut(): void
    {
        $crawler = $this->client->request('GET', '/admin/articles/'.$this->articleId);

        $this->assertNotNull($crawler->filter('#onglet-details')->attr('checked'));
        $this->assertNull($crawler->filter('#onglet-fiche')->attr('checked'));
    }

    /**
     * L'écran Production ne crée rien : dire « créez-en depuis un article » sans
     * donner le chemin laissait chercher.
     */
    public function testProductionIndiqueOuComposerUneFiche(): void
    {
        $crawler = $this->client->request('GET', '/admin/production');
        $this->assertResponseIsSuccessful();

        // La fiche de la baguette existe : c'est le lien de la ligne qu'on vérifie.
        $lien = $crawler->filter('a:contains("Modifier la fiche")');
        $this->assertCount(1, $lien);
        $this->assertStringContainsString('onglet=fiche', (string) $lien->attr('href'));
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

    // ------------------------------------------------------------- Pagination

    /**
     * Crée assez d'articles pour dépasser une page, dans une famille dédiée.
     */
    private function remplirCatalogue(int $nombre, string $familleNom = 'Viennoiseries'): FamilleProduit
    {
        $famille = new FamilleProduit($familleNom);
        $this->em->persist($famille);

        for ($i = 1; $i <= $nombre; ++$i) {
            $article = new Article(\sprintf('%s %02d', $familleNom, $i), 10000 + $i, 'pièce');
            $article->setFamilleProduit($famille)->setTauxTva(0);
            $this->em->persist($article);
        }

        $this->em->flush();

        return $famille;
    }

    public function testLaListeDArticlesEstDecoupeeEnPages(): void
    {
        $this->remplirCatalogue(Pagination::PAR_DEFAUT + 4);

        $crawler = $this->client->request('GET', '/admin/articles');
        $this->assertResponseIsSuccessful();

        // Une page pleine, pas une de plus : c'est la base qui découpe.
        $this->assertCount(Pagination::PAR_DEFAUT, $crawler->filter('turbo-frame#liste-articles tbody tr'));
        $this->assertSelectorExists('nav[aria-label="Pagination"]');

        // Le reste tient sur la seconde page (le catalogue compte aussi la baguette
        // créée dans le setUp).
        $crawler = $this->client->request('GET', '/admin/articles?page=2');
        $this->assertResponseIsSuccessful();
        $this->assertCount(5, $crawler->filter('turbo-frame#liste-articles tbody tr'));
    }

    /**
     * Le défaut le plus pénible d'une pagination faite à la main : changer de page
     * efface le filtre en cours. Il ne se voit pas en développement, où l'on teste
     * souvent sans filtre.
     */
    public function testLaPaginationConserveLesFiltresEnCours(): void
    {
        $famille = $this->remplirCatalogue(Pagination::PAR_DEFAUT + 3);

        $crawler = $this->client->request('GET', '/admin/articles?famille='.$famille->getId());
        $this->assertResponseIsSuccessful();

        $lien = $crawler->filter('nav[aria-label="Pagination"] a')->last()->attr('href');
        $this->assertStringContainsString('famille='.$famille->getId(), (string) $lien, 'Le filtre doit survivre au changement de page.');

        // Et la page suivante reste bien filtrée : uniquement des viennoiseries.
        $crawler = $this->client->request('GET', (string) $lien);
        $this->assertResponseIsSuccessful();
        $this->assertCount(3, $crawler->filter('turbo-frame#liste-articles tbody tr'));
        $this->assertStringNotContainsString('Baguette', $crawler->filter('turbo-frame#liste-articles tbody')->text());
    }

    public function testUnePageAuDelaDeLaFinNeCassePas(): void
    {
        $this->client->request('GET', '/admin/articles?page=999');

        $this->assertResponseIsSuccessful('Un lien périmé ne doit pas immobiliser l\'écran.');
    }

    /**
     * Les listes du back-office portent toutes le même contrôle, avec le même
     * balisage — c'est ce qui permet de n'avoir qu'une macro.
     */
    #[DataProvider('listesPeuplees')]
    public function testChaqueListePorteSaPagination(string $url): void
    {
        $this->client->request('GET', $url);

        $this->assertResponseIsSuccessful($url);
        $this->assertSelectorExists('nav[aria-label="Pagination"]', $url.' doit porter une pagination.');
        // Le décompte est affiché même sur une seule page : il dit combien
        // d'éléments existent, pas seulement où l'on se trouve.
        $this->assertSelectorTextContains('nav[aria-label="Pagination"]', 'sur 1');
    }

    /**
     * Listes garnies par le `setUp` — une liste vide est traitée à part.
     *
     * @return iterable<string, array{string}>
     */
    public static function listesPeuplees(): iterable
    {
        yield 'articles' => ['/admin/articles'];
        yield 'stock' => ['/admin/stock'];
        yield 'familles' => ['/admin/familles'];
        yield 'production' => ['/admin/production'];
    }

    /**
     * Une liste vide n'affiche pas de barre : « 0–0 sur 0 » et des flèches
     * inertes n'apprennent rien, le message « aucun élément » du tableau suffit.
     */
    #[DataProvider('listesVides')]
    public function testUneListeVideNAffichePasDeBarre(string $url): void
    {
        $this->client->request('GET', $url);

        $this->assertResponseIsSuccessful($url);
        $this->assertSelectorNotExists('nav[aria-label="Pagination"]', $url.' est vide : pas de barre.');
    }

    /** @return iterable<string, array{string}> */
    public static function listesVides(): iterable
    {
        yield 'ventes' => ['/admin/ventes'];
        yield 'fournisseurs' => ['/admin/fournisseurs'];
        yield 'clôtures' => ['/admin/clotures'];
        yield 'pertes' => ['/admin/pertes'];
    }
}
