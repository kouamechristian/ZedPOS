<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\FamilleProduit;
use App\Entity\MatierePremiere;
use App\Entity\Utilisateur;
use App\Service\SessionCaisseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Navigation Turbo : ce qui peut être vérifié côté serveur.
 *
 * Le comportement final (absence de spinner navigateur) se constate dans un
 * navigateur. Ce test verrouille tout ce qui le rend possible et qui casserait
 * silencieusement : présence de Turbo dans le bundle, métadonnées Turbo 8,
 * existence des frames, échappement des liens hors frame, et surtout le code 422
 * sur formulaire invalide — sans lequel Turbo laisse l'utilisateur devant un
 * formulaire figé, sans message d'erreur.
 */
class TurboNavigationTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Utilisateur $gerant;
    private Utilisateur $caissier;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $connexion = $this->em->getConnection();
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['ligne_fiche_technique', 'fiche_technique', 'ligne_vente', 'reglement', 'vente', 'mouvement_caisse', 'session_caisse', 'mouvement_stock', 'perte', 'article', 'matiere_premiere', 'fournisseur', 'famille_produit', 'journal_audit', 'notification', 'utilisateur'] as $table) {
            $connexion->executeStatement('DELETE FROM '.$table);
        }
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $this->gerant = new Utilisateur('koffi@test.ci', 'Koffi');
        $this->gerant->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        $this->em->persist($this->gerant);

        $this->caissier = new Utilisateur('fatou@test.ci', 'Fatou');
        $this->caissier->setRoles(['ROLE_CAISSIER'])->setCodePin('x');
        $this->em->persist($this->caissier);

        $pains = new FamilleProduit('Pains');
        $this->em->persist($pains);
        $boissons = new FamilleProduit('Boissons');
        $this->em->persist($boissons);

        $baguette = new Article('Baguette', 15000, 'pièce');
        $baguette->setFamilleProduit($pains)->setTauxTva(0);
        $this->em->persist($baguette);

        $soda = new Article('Sucrerie', 50000, 'pièce');
        $soda->setFamilleProduit($boissons)->setTauxTva(1800);
        $this->em->persist($soda);

        $this->em->persist((new MatierePremiere('Farine', 'kg'))->setStockActuel(100000)->setStockMini(20000));

        $this->em->flush();
    }

    // ------------------------------------------------- Turbo présent et démarré

    public function testTurboEstDansLeBundleJavaScript(): void
    {
        $controleurs = file_get_contents(
            \dirname(__DIR__, 2).'/vendor/symfony/stimulus-bundle/assets/dist/loader.js',
        );
        $this->assertNotEmpty($controleurs);

        // Turbo est importé explicitement par le point d'entrée…
        $app = file_get_contents(\dirname(__DIR__, 2).'/assets/app.js');
        $this->assertStringContainsString("import '@hotwired/turbo'", $app, 'Turbo doit être démarré par app.js.');

        // …et le paquet est bien déclaré dans l'importmap.
        $importmap = file_get_contents(\dirname(__DIR__, 2).'/importmap.php');
        $this->assertStringContainsString("'@hotwired/turbo'", $importmap);

        // …et le contrôleur ux-turbo reste activé.
        $controllersJson = json_decode(
            file_get_contents(\dirname(__DIR__, 2).'/assets/controllers.json'),
            true,
        );
        $this->assertTrue($controllersJson['controllers']['@symfony/ux-turbo']['turbo-core']['enabled']);
    }

    public function testMetadonneesTurbo8SurToutesLesPages(): void
    {
        $this->client->loginUser($this->gerant);
        $crawler = $this->client->request('GET', '/admin/articles');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('meta[name="turbo-refresh-method"][content="morph"]'));
        $this->assertCount(1, $crawler->filter('meta[name="turbo-refresh-scroll"][content="preserve"]'));
        $this->assertCount(1, $crawler->filter('meta[name="turbo-prefetch"][content="true"]'));
    }

    public function testPrefetchSurLaNavigation(): void
    {
        $this->client->loginUser($this->gerant);
        $crawler = $this->client->request('GET', '/admin');

        $this->assertCount(1, $crawler->filter('nav[data-turbo-prefetch="true"]'), 'La navigation précharge au survol.');
    }

    public function testEcranDeCaisseSansCacheTurbo(): void
    {
        static::getContainer()->get(SessionCaisseService::class)->ouvrir($this->caissier, 0);
        $this->client->loginUser($this->caissier);

        $crawler = $this->client->request('GET', '/caisse');

        $this->assertResponseIsSuccessful();
        $this->assertCount(
            1,
            $crawler->filter('meta[name="turbo-cache-control"][content="no-cache"]'),
            "L'écran de caisse ne doit pas réafficher un ticket périmé depuis le cache Turbo.",
        );
    }

    // -------------------------------------------------------------- Les frames

    public function testLesTroisListesSontDesTurboFrames(): void
    {
        $this->client->loginUser($this->gerant);

        foreach ([
            '/admin/articles' => 'liste-articles',
            '/admin/ventes' => 'liste-ventes',
            '/admin/stock' => 'tableau-stock',
        ] as $url => $frame) {
            $crawler = $this->client->request('GET', $url);

            $this->assertResponseIsSuccessful();
            $this->assertCount(
                1,
                $crawler->filter('turbo-frame#'.$frame),
                \sprintf('%s doit contenir le frame « %s ».', $url, $frame),
            );
        }
    }

    public function testFiltrerLesArticlesRafraichitLeFrameSeul(): void
    {
        $this->client->loginUser($this->gerant);

        // Turbo envoie l'en-tête Turbo-Frame lors d'une navigation de frame.
        $crawler = $this->client->request(
            'GET',
            '/admin/articles?q=Baguette',
            [], [],
            ['HTTP_TURBO_FRAME' => 'liste-articles'],
        );

        $this->assertResponseIsSuccessful();

        // Le frame est présent dans la réponse : Turbo saura quoi remplacer.
        $frame = $crawler->filter('turbo-frame#liste-articles');
        $this->assertCount(1, $frame);

        // Et le filtre a bien été appliqué.
        $this->assertStringContainsString('Baguette', $frame->text());
        $this->assertStringNotContainsString('Sucrerie', $frame->text());
    }

    public function testLeFormulaireDeFiltreEstDansLeFrame(): void
    {
        $this->client->loginUser($this->gerant);
        $crawler = $this->client->request('GET', '/admin/articles');

        $formulaires = $crawler->filter('turbo-frame#liste-articles form[method="get"]');
        $this->assertCount(1, $formulaires, 'Le formulaire de filtre doit vivre dans le frame pour y rester.');
    }

    public function testLesLiensDeDetailSortentDuFrame(): void
    {
        $this->client->loginUser($this->gerant);
        $crawler = $this->client->request('GET', '/admin/articles');

        // Sans data-turbo-frame="_top", la fiche article s'afficherait à
        // l'intérieur du tableau — le bug classique des Turbo Frames.
        $liens = $crawler->filter('turbo-frame#liste-articles a[href*="/admin/articles/"]');
        $this->assertGreaterThan(0, $liens->count());

        $liens->each(function ($lien): void {
            $this->assertSame(
                '_top',
                $lien->attr('data-turbo-frame'),
                \sprintf('Le lien « %s » doit quitter le frame.', trim($lien->text())),
            );
        });
    }

    public function testLesActionsDuTableauDeStockSortentDuFrame(): void
    {
        $this->client->loginUser($this->gerant);
        $crawler = $this->client->request('GET', '/admin/stock');

        $crawler->filter('turbo-frame#tableau-stock a, turbo-frame#tableau-stock form')->each(function ($noeud): void {
            $this->assertSame('_top', $noeud->attr('data-turbo-frame'));
        });
    }

    // ------------------------------------ Compatibilité des formulaires (422)

    public function testFormulaireInvalideRepond422(): void
    {
        $this->client->loginUser($this->caissier);

        // Fond de caisse négatif : refusé par la contrainte du formulaire.
        $crawler = $this->client->request('GET', '/caisse/session/ouverture');
        $form = $crawler->selectButton('Ouvrir la caisse')->form();
        $form['ouverture_caisse[fondCaisse]'] = '-500';
        $this->client->submit($form);

        $this->assertResponseStatusCodeSame(
            422,
            'Turbo exige un statut d\'erreur pour réafficher un formulaire invalide.',
        );
        $this->assertSelectorExists('form', 'Le formulaire est réaffiché avec ses erreurs.');
    }

    public function testFormulaireValideRedirigeToujours(): void
    {
        $this->client->loginUser($this->caissier);

        $crawler = $this->client->request('GET', '/caisse/session/ouverture');
        $form = $crawler->selectButton('Ouvrir la caisse')->form();
        $form['ouverture_caisse[fondCaisse]'] = '30000';
        $this->client->submit($form);

        $this->assertResponseRedirects('/caisse', null, 'Une soumission valide redirige, Turbo suit la redirection.');
    }

    public function testAffichageInitialDunFormulaireRepond200(): void
    {
        $this->client->loginUser($this->gerant);
        $this->client->request('GET', '/admin/articles/nouveau');

        $this->assertResponseStatusCodeSame(200, 'Un formulaire non soumis reste un 200.');
    }

    // ------------------------------- Caisse : aucun aller-retour serveur par clic

    public function testLesInteractionsDuTicketNAppellentJamaisLeServeur(): void
    {
        $source = file_get_contents(\dirname(__DIR__, 2).'/assets/controllers/caisse_controller.js');

        // Seul `encaisser()` est asynchrone : ajouter un article, +/-, supprimer
        // une ligne, vider ou reprendre un ticket restent en mémoire.
        preg_match_all('/^    (?:async )?(\w+)\(/m', $source, $correspondances);
        $asynchrones = [];
        foreach ($correspondances[0] as $index => $signature) {
            if (str_contains($signature, 'async')) {
                $asynchrones[] = $correspondances[1][$index];
            }
        }

        sort($asynchrones);
        $this->assertSame(
            ['actualiserCatalogue', 'encaisser', 'venteTransmise'],
            $asynchrones,
            'Aucune autre méthode du contrôleur de caisse ne doit faire d\'aller-retour serveur.',
        );

        foreach (['ajouter', 'incrementer', 'decrementer', 'supprimer', 'vider', 'panneauPlus', 'panneauMoins'] as $methode) {
            $this->assertMatchesRegularExpression(
                '/^    '.$methode.'\(/m',
                $source,
                \sprintf('%s() doit rester synchrone et purement client.', $methode),
            );
        }
    }

    public function testLePaveNumeriqueLaisseTurboIntercepterLaSoumission(): void
    {
        $source = file_get_contents(\dirname(__DIR__, 2).'/assets/controllers/numpad_controller.js');

        // form.submit() natif n'émet pas d'événement `submit` : Turbo ne peut pas
        // l'intercepter et le navigateur recharge toute la page.
        $this->assertStringContainsString('requestSubmit()', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/(?<!request)Submit\(\);\s*$|formTarget\.submit\(\);(?!\s*\n\s*})/m',
            $source,
        );
    }
}
