<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\FamilleProduit;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Coquille des espaces de gestion : ce que la refonte promet et qu'un œil ne
 * vérifie pas.
 *
 * Le rendu lui-même (animations, transitions, toasts qui glissent) ne se teste
 * pas ici. Ce qui se teste, c'est ce dont il dépend et qui casserait sans bruit :
 * l'état replié rendu par le serveur, une palette qui ne propose que ce qu'on peut
 * ouvrir, des attributs d'accessibilité bien formés, des compteurs qui ne
 * reçoivent que des entiers et un système de composants qui ne déborde pas sur
 * la caisse.
 */
class InterfaceGestionTest extends WebTestCase
{
    private KernelBrowser $client;
    private Utilisateur $gerant;
    private Utilisateur $dirigeante;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $connexion = $em->getConnection();
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['ligne_fiche_technique', 'fiche_technique', 'ligne_vente', 'reglement', 'vente', 'mouvement_caisse', 'session_caisse', 'mouvement_stock', 'perte', 'article', 'matiere_premiere', 'fournisseur', 'famille_produit', 'journal_audit', 'notification', 'utilisateur'] as $table) {
            $connexion->executeStatement('DELETE FROM '.$table);
        }
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $this->gerant = new Utilisateur('koffi@test.ci', 'Koffi Nguessan');
        $this->gerant->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        $em->persist($this->gerant);

        $this->dirigeante = new Utilisateur('aya@test.ci', 'Aya Koné');
        $this->dirigeante->setRoles(['ROLE_DIRIGEANTE'])->setMotDePasse('x');
        $em->persist($this->dirigeante);

        $famille = (new FamilleProduit('Pains'))->setActif(true);
        $em->persist($famille);

        $article = new Article('Baguette', 15000, 'pièce');
        $article->setFamilleProduit($famille)->setActif(true)->setTauxTva(0);
        $em->persist($article);

        $em->flush();
    }

    /**
     * Rendu par le serveur depuis un cookie : appliqué en JavaScript après coup,
     * l'état replié ferait déplier puis replier la barre à chaque navigation.
     */
    public function testLaBarreReplieeEstRendueParLeServeur(): void
    {
        $this->client->loginUser($this->gerant);

        $crawler = $this->client->request('GET', '/admin');
        $this->assertCount(1, $crawler->filter('.coquille'));
        $this->assertCount(0, $crawler->filter('.coquille[data-replie]'), 'Dépliée par défaut.');

        $this->client->getCookieJar()->set(new Cookie('zp_barre_repliee', '1'));
        $crawler = $this->client->request('GET', '/admin');
        $this->assertCount(1, $crawler->filter('.coquille[data-replie]'), 'Le cookie replie la barre dès le rendu.');
    }

    /**
     * Les composants sont confinés sous `.espace-gestion` : l'élément doit donc
     * **englober** la coquille, ses toasts et sa palette — sans quoi aucune de leurs
     * règles ne s'appliquerait.
     */
    public function testLaCoquilleVitSousLEspaceDeGestion(): void
    {
        $this->client->loginUser($this->gerant);
        $crawler = $this->client->request('GET', '/admin');

        $this->assertCount(1, $crawler->filter('.espace-gestion > .coquille'));
        $this->assertCount(1, $crawler->filter('.espace-gestion .toasts'));
        $this->assertCount(1, $crawler->filter('.espace-gestion dialog.palette'));
    }

    /**
     * La palette et la barre latérale lisent la même liste : ce qu'on ne peut pas
     * ouvrir n'apparaît dans aucune des deux. Le pilotage n'est proposé qu'à la
     * dirigeante.
     */
    public function testLaPaletteNeProposeQueLesEcransAccessibles(): void
    {
        $this->client->loginUser($this->gerant);
        $crawler = $this->client->request('GET', '/admin');

        $palette = $crawler->filter('dialog.palette a')->each(static fn ($lien) => $lien->attr('href'));
        $barre = $crawler->filter('aside nav a')->each(static fn ($lien) => $lien->attr('href'));

        $this->assertSame($barre, $palette, 'La palette propose exactement les écrans de la barre latérale.');
        $this->assertContains('/admin/articles', $palette);
        $this->assertContains('/comptabilite', $palette);
        $this->assertNotContains('/pilotage', $palette, 'Le gérant n\'a pas accès au pilotage.');

        $this->client->loginUser($this->dirigeante);
        $crawler = $this->client->request('GET', '/admin');
        $this->assertContains('/pilotage', $crawler->filter('dialog.palette a')->each(static fn ($lien) => $lien->attr('href')));
    }

    /**
     * L'entrée active est signalée par `aria-current`, et l'attribut doit arriver
     * intact : écrit dans une expression Twig, ses guillemets seraient échappés.
     * Les fournisseurs appartiennent au stock — la barre dit où l'on est même un
     * niveau plus bas.
     */
    public function testLEntreeActiveEstSignaleeParAriaCurrent(): void
    {
        $this->client->loginUser($this->gerant);
        $crawler = $this->client->request('GET', '/admin/fournisseurs');

        $actives = $crawler->filter('aside nav a[aria-current="page"]');
        $this->assertCount(1, $actives);
        $this->assertSame('/admin/stock', $actives->attr('href'));
        $this->assertStringNotContainsString('&quot;page&quot;', (string) $this->client->getResponse()->getContent());
    }

    /**
     * Le compteur anime un chiffre déjà présent dans la page, en arithmétique
     * entière : sa cible ne doit jamais porter de décimale.
     */
    public function testLesCompteursNeRecoiventQueDesEntiers(): void
    {
        $this->client->loginUser($this->dirigeante);

        foreach (['/admin', '/pilotage'] as $url) {
            $crawler = $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful();

            $cibles = $crawler->filter('[data-controller="compteur"]')->each(static fn ($noeud) => $noeud->attr('data-compteur-cible-value'));
            $this->assertNotEmpty($cibles, $url.' doit animer ses chiffres clés.');
            foreach ($cibles as $cible) {
                $this->assertMatchesRegularExpression('/^-?\d+$/', (string) $cible, $url.' : cible non entière « '.$cible.' ».');
            }
        }
    }

    /** Un message flash arrive en toast, et reste lisible dans la page. */
    public function testLesMessagesFlashSontAffichesEnToast(): void
    {
        $this->client->loginUser($this->gerant);
        $crawler = $this->client->request('GET', '/admin/articles');

        $this->client->submit($crawler->filter('turbo-frame#liste-articles form[method="post"]')->first()->form());
        $crawler = $this->client->followRedirect();

        $toasts = $crawler->filter('.toasts .toast[role="status"]');
        $this->assertCount(1, $toasts);
        $this->assertNotSame('', trim($toasts->text()));
    }

    /**
     * Le système de composants ne doit rien imposer à la caisse, qui a ses propres
     * classes `champ` et `onglet` : chaque règle de composant est préfixée.
     */
    public function testLesComposantsNeDebordentPasSurLaCaisse(): void
    {
        $css = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/styles/app.css');

        $debut = strpos($css, '@layer components {');
        $this->assertNotFalse($debut);
        $this->assertMatchesRegularExpression(
            '/@layer components \{\s*(\/\*.*?\*\/\s*)?\.espace-gestion \{/s',
            substr($css, $debut, 400),
            'Tout le calque de composants doit être imbriqué sous .espace-gestion.',
        );
    }
}
