<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\FamilleProduit;
use App\Entity\Utilisateur;
use App\Service\ImageArticle;
use App\Service\SessionCaisseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Photos des touches produits : téléversement, réduction, affichage en caisse.
 *
 * Trois exigences se rejoignent ici. La photo doit **arriver jusqu'à la caisse**,
 * par les deux chemins de rendu (Twig au premier affichage, IndexedDB ensuite).
 * Elle doit être **réduite** : une photo de téléphone chargerait la tablette et
 * gonflerait le cache hors ligne. Et le fichier ne doit **jamais rester orphelin**
 * sur le disque quand l'article qui le désignait disparaît.
 */
class ImageArticleTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Utilisateur $dirigeante;
    private Article $baguette;
    private string $repertoire;
    /** @var list<string> fichiers temporaires à effacer après le test */
    private array $temporaires = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $connexion = $this->em->getConnection();
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['ligne_inventaire', 'inventaire', 'ligne_fiche_technique', 'fiche_technique', 'ligne_vente', 'reglement', 'vente', 'mouvement_caisse', 'session_caisse', 'mouvement_stock', 'perte', 'article', 'matiere_premiere', 'fournisseur', 'famille_produit', 'journal_audit', 'notification', 'utilisateur'] as $table) {
            $connexion->executeStatement('DELETE FROM '.$table);
        }
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $this->dirigeante = new Utilisateur('aya@test.ci', 'Aya');
        $this->dirigeante->setRoles(['ROLE_DIRIGEANTE'])->setMotDePasse('x');
        $this->em->persist($this->dirigeante);

        $famille = (new FamilleProduit('Pains'))->setActif(true);
        $this->em->persist($famille);

        $this->baguette = new Article('Baguette', 15000, 'pièce');
        $this->baguette->setFamilleProduit($famille)->setActif(true)->setTauxTva(0);
        $this->em->persist($this->baguette);

        $this->em->flush();

        $this->repertoire = static::getContainer()->getParameter('kernel.cache_dir').'/images-articles';

        $this->client->loginUser($this->dirigeante);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaires as $fichier) {
            if (is_file($fichier)) {
                @unlink($fichier);
            }
        }

        parent::tearDown();
    }

    private function images(): ImageArticle
    {
        return static::getContainer()->get(ImageArticle::class);
    }

    /** Fabrique une image PNG de la taille voulue et l'emballe en fichier téléversé. */
    private function televersement(int $largeur = 60, int $hauteur = 40, string $type = 'png'): UploadedFile
    {
        $image = imagecreatetruecolor($largeur, $hauteur);
        imagefill($image, 0, 0, imagecolorallocate($image, 210, 120, 40));

        $chemin = sys_get_temp_dir().'/zedpos-test-'.bin2hex(random_bytes(4)).'.'.$type;
        'png' === $type ? imagepng($image, $chemin) : imagejpeg($image, $chemin);
        imagedestroy($image);

        $this->temporaires[] = $chemin;

        return new UploadedFile($chemin, 'photo.'.$type, 'image/'.$type, null, true);
    }

    private function chemin(string $nom): string
    {
        return $this->repertoire.'/'.$nom;
    }

    /** Téléverse une photo sur un article via le formulaire du back-office. */
    private function envoyerPhoto(Article $article, UploadedFile $fichier): void
    {
        $crawler = $this->client->request('GET', '/admin/articles/'.$article->getId().'/modifier');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Enregistrer')->form();
        $form['article[imageFichier]']->upload($fichier->getPathname());

        $this->client->submit($form);
    }

    // ------------------------------------------------------------ Téléversement

    public function testUnePhotoTeleverseeEstEnregistreeSurLArticle(): void
    {
        $this->envoyerPhoto($this->baguette, $this->televersement());

        $this->assertResponseRedirects();
        $this->em->clear();

        $article = $this->em->getRepository(Article::class)->find($this->baguette->getId());
        $this->assertNotNull($article->getImage());
        $this->assertFileExists($this->chemin($article->getImage()));

        $this->images()->supprimer($article->getImage());
    }

    /**
     * Une photo de téléphone fait 4 000 px pour une touche qui en occupe 92.
     * Servie telle quelle, elle chargerait la tablette du comptoir et gonflerait
     * le cache du Service Worker — dont dépend la caisse hors ligne.
     */
    public function testUneGrandePhotoEstReduite(): void
    {
        $nom = $this->images()->enregistrer($this->televersement(2400, 1600));

        [$largeur, $hauteur] = getimagesize($this->chemin($nom));

        $this->assertSame(400, $largeur, 'Le grand côté est ramené à 400 px.');
        $this->assertSame(267, $hauteur, 'Les proportions sont conservées.');

        $this->images()->supprimer($nom);
    }

    /** Agrandir une petite image ne créerait que du flou et du poids. */
    public function testUnePetitePhotoNestPasAgrandie(): void
    {
        $nom = $this->images()->enregistrer($this->televersement(60, 40));

        $this->assertSame([60, 40], \array_slice(getimagesize($this->chemin($nom)), 0, 2));

        $this->images()->supprimer($nom);
    }

    public function testUnFichierQuiNestPasUneImageEstRefuse(): void
    {
        $chemin = sys_get_temp_dir().'/zedpos-test-'.bin2hex(random_bytes(4)).'.txt';
        file_put_contents($chemin, 'ceci n\'est pas une image');
        $this->temporaires[] = $chemin;

        $crawler = $this->client->request('GET', '/admin/articles/'.$this->baguette->getId().'/modifier');
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['article[imageFichier]']->upload($chemin);
        $this->client->submit($form);

        // 422 : Turbo ne remplace pas la page sur un 200.
        $this->assertResponseStatusCodeSame(422);

        $this->em->clear();
        $this->assertNull($this->em->getRepository(Article::class)->find($this->baguette->getId())->getImage());
    }

    // -------------------------------------------------- Le disque reste propre

    public function testRemplacerUnePhotoEffaceLAncienne(): void
    {
        $ancienne = $this->images()->enregistrer($this->televersement());
        $this->baguette->setImage($ancienne);
        $this->em->flush();

        $this->envoyerPhoto($this->baguette, $this->televersement());
        $this->em->clear();

        $article = $this->em->getRepository(Article::class)->find($this->baguette->getId());
        $this->assertNotSame($ancienne, $article->getImage());
        $this->assertFileDoesNotExist($this->chemin($ancienne), 'L\'ancien fichier ne doit pas rester sur le disque.');

        $this->images()->supprimer($article->getImage());
    }

    public function testRetirerLaPhotoEffaceLeFichier(): void
    {
        $nom = $this->images()->enregistrer($this->televersement());
        $this->baguette->setImage($nom);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/articles/'.$this->baguette->getId().'/modifier');
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['article[supprimerImage]']->tick();
        $this->client->submit($form);

        $this->em->clear();
        $this->assertNull($this->em->getRepository(Article::class)->find($this->baguette->getId())->getImage());
        $this->assertFileDoesNotExist($this->chemin($nom));
    }

    /** Un article supprimé ne doit pas laisser sa photo derrière lui. */
    public function testSupprimerLArticleEffaceSaPhoto(): void
    {
        $nom = $this->images()->enregistrer($this->televersement());
        $this->baguette->setImage($nom);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/articles/'.$this->baguette->getId());
        $this->client->submit($crawler->filter('form[action$="/supprimer"] button')->form());

        $this->assertFileDoesNotExist($this->chemin($nom));
    }

    // --------------------------------------------------- L'arrivée en caisse

    public function testLeCatalogueExposeLeCheminDeLaPhoto(): void
    {
        $nom = $this->images()->enregistrer($this->televersement());
        $this->baguette->setImage($nom);
        $this->em->flush();

        $this->client->request('GET', '/caisse/catalogue.json');
        $this->assertResponseIsSuccessful();

        $donnees = json_decode((string) $this->client->getResponse()->getContent(), true);
        $article = $donnees['familles'][0]['articles'][0];

        $this->assertSame('/uploads/articles/'.$nom, $article['image']);

        $this->images()->supprimer($nom);
    }

    public function testUnArticleSansPhotoExposeNull(): void
    {
        $this->client->request('GET', '/caisse/catalogue.json');

        $donnees = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertNull($donnees['familles'][0]['articles'][0]['image']);
    }

    public function testLaGrilleDeCaisseAfficheLaPhoto(): void
    {
        $nom = $this->images()->enregistrer($this->televersement());
        $this->baguette->setImage($nom);
        $this->em->flush();

        $caissier = new Utilisateur('fatou@test.ci', 'Fatou');
        $caissier->setRoles(['ROLE_CAISSIER'])->setCodePin('x');
        $this->em->persist($caissier);
        $this->em->flush();
        static::getContainer()->get(SessionCaisseService::class)->ouvrir($caissier, 0);

        $this->client->loginUser($caissier);
        $crawler = $this->client->request('GET', '/caisse');
        $this->assertResponseIsSuccessful();

        $img = $crawler->filter('button[data-action="ticket#appuyerProduit"] img');
        $this->assertCount(1, $img);
        $this->assertSame('/uploads/articles/'.$nom, $img->attr('src'));
        // Le nom figure juste en dessous : le répéter en `alt` le ferait lire deux
        // fois par un lecteur d'écran.
        $this->assertSame('', $img->attr('alt'));

        $this->images()->supprimer($nom);
    }

    /**
     * Le libellé n'est **jamais** posé sur la photo : son contraste dépendrait de
     * l'image téléversée, donc de personne, et l'écran doit rester lisible en
     * plein jour. Il reste sur l'aplat de couleur, comme sans photo.
     */
    public function testLeLibelleResteSurLAplatEtNonSurLaPhoto(): void
    {
        $racine = \dirname(__DIR__, 2);

        foreach ([
            $racine.'/templates/caisse/index.html.twig',
            $racine.'/assets/controllers/ticket_controller.js',
        ] as $fichier) {
            $source = (string) file_get_contents($fichier);

            $this->assertStringNotContainsString(
                'background-image',
                $source,
                'La photo est une balise <img>, pas un fond sous le texte : '.basename($fichier),
            );
            $this->assertStringContainsString('object-cover', $source);
        }
    }

    /**
     * Les touches sont rendues **deux fois** — par Twig au premier affichage, par
     * le contrôleur Stimulus depuis IndexedDB ensuite. Une touche qui changerait
     * d'allure au rechargement, ou hors ligne, serait déroutante au comptoir.
     */
    public function testLaPhotoEstRendueALIdentiqueCoteTwigEtCoteJs(): void
    {
        $racine = \dirname(__DIR__, 2);
        $twig = (string) file_get_contents($racine.'/templates/caisse/index.html.twig');
        $js = (string) file_get_contents($racine.'/assets/controllers/ticket_controller.js');

        foreach (['h-20 w-full shrink-0 object-cover', 'loading="lazy"', 'onerror="this.remove()"'] as $regle) {
            $this->assertStringContainsString($regle, $twig, 'Absent du gabarit Twig : '.$regle);
            $this->assertStringContainsString($regle, $js, 'Absent du contrôleur Stimulus : '.$regle);
        }
    }

    /**
     * Sans cette règle, la grille perdrait ses photos dès la coupure de réseau
     * alors que tout le reste continuerait de fonctionner.
     */
    public function testLeServiceWorkerMetLesPhotosEnCache(): void
    {
        $sw = (string) file_get_contents(\dirname(__DIR__, 2).'/public/sw.js');

        $this->assertStringContainsString("const IMAGES = '/uploads/';", $sw);
        $this->assertStringContainsString('cacheDAbord(requete, CACHE_IMAGES)', $sw);
    }
}
