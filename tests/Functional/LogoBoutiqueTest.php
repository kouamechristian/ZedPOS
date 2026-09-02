<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\FamilleProduit;
use App\Entity\Utilisateur;
use App\Enum\CleParametre;
use App\Service\LogoBoutique;
use App\Service\ParametresBoutique;
use App\Service\SessionCaisseService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * Logo de l'établissement : téléversement en back-office, impression sur le ticket,
 * reprise dans les en-têtes du back-office et du pilotage.
 *
 * Le logo est un paramètre comme les autres — il vit dans la table `parametre` —
 * mais sa valeur est un **nom de fichier** et non une saisie. Trois exigences en
 * découlent, vérifiées ici : le nom persisté doit toujours désigner un fichier
 * réellement présent sur le disque, le disque ne doit pas garder d'orphelin, et le
 * ticket doit sortir même si le fichier a disparu entre-temps.
 *
 * La dernière partie vérifie que **rien n'est codé en dur dans les gabarits** :
 * nom et logo des écrans de gestion viennent de la même table que le ticket.
 */
class LogoBoutiqueTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Utilisateur $gerant;
    private Utilisateur $dirigeante;
    private Utilisateur $caissier;
    private int $articleId;
    private string $repertoire;
    /** @var list<string> fichiers temporaires à effacer après le test */
    private array $temporaires = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $connexion = $this->em->getConnection();
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['ligne_inventaire', 'inventaire', 'ligne_fiche_technique', 'fiche_technique', 'ligne_vente', 'reglement', 'vente', 'mouvement_caisse', 'session_caisse', 'mouvement_stock', 'perte', 'article', 'matiere_premiere', 'fournisseur', 'famille_produit', 'journal_audit', 'notification', 'parametre', 'utilisateur'] as $table) {
            $connexion->executeStatement('DELETE FROM '.$table);
        }
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $this->gerant = new Utilisateur('koffi@test.ci', 'Koffi');
        $this->gerant->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        $this->em->persist($this->gerant);

        $this->dirigeante = new Utilisateur('aya@test.ci', 'Aya');
        $this->dirigeante->setRoles(['ROLE_DIRIGEANTE'])->setMotDePasse('x');
        $this->em->persist($this->dirigeante);

        $this->caissier = new Utilisateur('fatou@test.ci', 'Fatou');
        $this->caissier->setRoles(['ROLE_CAISSIER'])->setCodePin('x');
        $this->em->persist($this->caissier);

        $famille = (new FamilleProduit('Pains'))->setActif(true);
        $this->em->persist($famille);

        $article = new Article('Baguette', 15000, 'pièce');
        $article->setFamilleProduit($famille)->setActif(true)->setTauxTva(0);
        $this->em->persist($article);

        $this->em->flush();
        $this->articleId = $article->getId();

        $this->repertoire = static::getContainer()->getParameter('kernel.cache_dir').'/logo-boutique';
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

    private function parametres(): ParametresBoutique
    {
        return static::getContainer()->get(ParametresBoutique::class);
    }

    private function logos(): LogoBoutique
    {
        return static::getContainer()->get(LogoBoutique::class);
    }

    private function chemin(string $nom): string
    {
        return $this->repertoire.'/'.$nom;
    }

    /** Fabrique une image PNG de la taille voulue et l'emballe en fichier téléversé. */
    private function televersement(int $largeur = 120, int $hauteur = 60): UploadedFile
    {
        $image = imagecreatetruecolor($largeur, $hauteur);
        imagefill($image, 0, 0, imagecolorallocate($image, 180, 83, 9));

        $chemin = sys_get_temp_dir().'/zedpos-logo-'.bin2hex(random_bytes(4)).'.png';
        imagepng($image, $chemin);
        imagedestroy($image);

        $this->temporaires[] = $chemin;

        return new UploadedFile($chemin, 'logo.png', 'image/png', null, true);
    }

    /** Téléverse un logo via le formulaire du back-office. */
    private function envoyerLogo(string $chemin): void
    {
        $crawler = $this->client->request('GET', '/admin/parametres');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Enregistrer')->form();
        $form['parametres_boutique[logo_fichier]']->upload($chemin);

        $this->client->submit($form);
    }

    /** Valeur brute rangée dans la table, sans passer par le service. */
    private function valeurPersistee(): ?string
    {
        return $this->em->getConnection()->fetchOne(
            'SELECT valeur FROM parametre WHERE cle = ?',
            [CleParametre::LOGO->value],
        ) ?: null;
    }

    // ------------------------------------------------------------ Téléversement

    public function testAucunLogoParDefaut(): void
    {
        // Une installation neuve imprime ses tickets sans logo, sans rien réclamer.
        $this->assertNull($this->parametres()->cheminLogo());
        $this->assertSame('', $this->parametres()->pourTicket()->logo);
    }

    public function testUnLogoTeleverseEstRangeDansLaTableParametre(): void
    {
        $this->client->loginUser($this->gerant);
        $this->envoyerLogo($this->televersement()->getPathname());

        $this->assertResponseRedirects('/admin/parametres');

        $this->em->clear();
        $nom = $this->valeurPersistee();

        // La table ne garde qu'un **nom de fichier** : déplacer le stockage ne doit
        // pas obliger à réécrire la table.
        $this->assertNotNull($nom);
        $this->assertStringNotContainsString('/', $nom);
        $this->assertFileExists($this->chemin($nom));
        $this->assertSame('/uploads/boutique/'.$nom, $this->parametres()->cheminLogo());

        $this->logos()->supprimer($nom);
    }

    /**
     * Le logo s'imprime sur toute la largeur utile du papier thermique (384 points
     * à 203 dpi sur 58 mm) et s'affiche par ailleurs à l'écran : 400 px, la borne
     * des touches produits, le rendrait crénelé sur le seul support où il n'y a
     * pas de seconde chance.
     */
    public function testUnGrandLogoEstReduitA600Px(): void
    {
        $nom = $this->logos()->enregistrer($this->televersement(2400, 1200));

        [$largeur, $hauteur] = getimagesize($this->chemin($nom));

        $this->assertSame(600, $largeur, 'Le grand côté est ramené à 600 px.');
        $this->assertSame(300, $hauteur, 'Les proportions sont conservées.');

        $this->logos()->supprimer($nom);
    }

    public function testEcranReserveAuGerant(): void
    {
        $this->client->loginUser($this->caissier);
        $this->client->request('GET', '/admin/parametres');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testUnFichierQuiNestPasUneImageEstRefuse(): void
    {
        $chemin = sys_get_temp_dir().'/zedpos-logo-'.bin2hex(random_bytes(4)).'.txt';
        file_put_contents($chemin, 'ceci n\'est pas une image');
        $this->temporaires[] = $chemin;

        $this->client->loginUser($this->gerant);
        $this->envoyerLogo($chemin);

        // 422 : Turbo ne remplace pas la page sur un 200.
        $this->assertResponseStatusCodeSame(422);

        $this->em->clear();
        $this->assertNull($this->valeurPersistee(), 'Rien ne doit être persisté si le fichier est refusé.');
    }

    /**
     * Les autres paramètres se saisissent au clavier ; le logo, non. Un champ texte
     * sur cette clé laisserait taper n'importe quel nom, et le ticket désignerait
     * alors un fichier absent du disque.
     */
    public function testLeLogoNestPasUnChampTexte(): void
    {
        $this->client->loginUser($this->gerant);
        $crawler = $this->client->request('GET', '/admin/parametres');

        $this->assertCount(0, $crawler->filter('input[name="parametres_boutique[boutique_logo]"]'));
        $this->assertCount(1, $crawler->filter('input[type="file"][name="parametres_boutique[logo_fichier]"]'));
    }

    /**
     * Enregistrer les informations de la boutique ne doit pas faire disparaître le
     * logo : la clé n'est pas dans la soumission, elle doit rester telle quelle.
     */
    public function testEnregistrerLesAutresParametresNeTouchePasAuLogo(): void
    {
        $nom = $this->logos()->enregistrer($this->televersement());
        $this->parametres()->definirLogo($nom);

        $this->client->loginUser($this->gerant);
        $crawler = $this->client->request('GET', '/admin/parametres');
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['parametres_boutique[boutique_raison_sociale]'] = 'Boulangerie du Marché';
        $this->client->submit($form);

        $this->assertResponseRedirects('/admin/parametres');

        $this->em->clear();
        $this->assertSame($nom, $this->valeurPersistee());
        $this->assertFileExists($this->chemin($nom));

        $this->logos()->supprimer($nom);
    }

    // -------------------------------------------------- Le disque reste propre

    public function testRemplacerLeLogoEffaceLAncien(): void
    {
        $ancien = $this->logos()->enregistrer($this->televersement());
        $this->parametres()->definirLogo($ancien);

        $this->client->loginUser($this->gerant);
        $this->envoyerLogo($this->televersement()->getPathname());

        $this->em->clear();
        $nouveau = $this->valeurPersistee();

        $this->assertNotSame($ancien, $nouveau);
        $this->assertFileDoesNotExist($this->chemin($ancien), 'L\'ancien fichier ne doit pas rester sur le disque.');

        $this->logos()->supprimer($nouveau);
    }

    public function testRetirerLeLogoEffaceLeFichierEtLaValeur(): void
    {
        $nom = $this->logos()->enregistrer($this->televersement());
        $this->parametres()->definirLogo($nom);

        $this->client->loginUser($this->gerant);
        $crawler = $this->client->request('GET', '/admin/parametres');

        $form = $crawler->selectButton('Enregistrer')->form();
        $form['parametres_boutique[logo_retirer]']->tick();
        $this->client->submit($form);

        $this->assertResponseRedirects('/admin/parametres');

        $this->em->clear();
        $this->assertSame('', (string) $this->valeurPersistee());
        $this->assertNull($this->parametres()->cheminLogo());
        $this->assertFileDoesNotExist($this->chemin($nom));
    }

    /** Sans logo, la case de retrait n'a rien à retirer : elle ne s'affiche pas. */
    public function testLaCaseDeRetraitNapparaitQueSiUnLogoExiste(): void
    {
        $this->client->loginUser($this->gerant);

        $crawler = $this->client->request('GET', '/admin/parametres');
        $this->assertCount(0, $crawler->filter('input[name="parametres_boutique[logo_retirer]"]'));

        $nom = $this->logos()->enregistrer($this->televersement());
        $this->parametres()->definirLogo($nom);

        $crawler = $this->client->request('GET', '/admin/parametres');
        $this->assertCount(1, $crawler->filter('input[name="parametres_boutique[logo_retirer]"]'));

        $this->logos()->supprimer($nom);
    }

    // ------------------------------------------------------- L'arrivée au ticket

    public function testLeTicketImprimeLeLogo(): void
    {
        $nom = $this->logos()->enregistrer($this->televersement());
        $this->parametres()->definirLogo($nom);

        $uuid = $this->encaisser();
        $crawler = $this->client->request('GET', '/caisse/ticket/'.$uuid);
        $this->assertResponseIsSuccessful();

        $img = $crawler->filter('.ticket img.logo');
        $this->assertCount(1, $img);
        $this->assertSame('/uploads/boutique/'.$nom, $img->attr('src'));

        // Un fichier disparu retire l'image : une icône de lien cassé en tête de
        // ticket ne rend service à personne, et le nom en clair est juste dessous.
        $this->assertSame('this.remove()', $img->attr('onerror'));

        $this->logos()->supprimer($nom);
    }

    public function testUnTicketSansLogoNaPasDImage(): void
    {
        $uuid = $this->encaisser();

        $crawler = $this->client->request('GET', '/caisse/ticket/'.$uuid);
        $this->assertCount(0, $crawler->filter('.ticket img.logo'));
    }

    /**
     * Le reçu affiché après encaissement et le papier tendu au client sortent du
     * même fragment : le logo ne peut donc pas figurer sur l'un et pas sur l'autre.
     */
    public function testLApercuDeCaisseMontreLeMemeLogo(): void
    {
        $nom = $this->logos()->enregistrer($this->televersement());
        $this->parametres()->definirLogo($nom);

        $uuid = $this->encaisser();

        $this->client->request('GET', '/caisse/ticket/'.$uuid.'/apercu');
        $this->assertResponseIsSuccessful();

        $this->assertStringContainsString(
            '/uploads/boutique/'.$nom,
            (string) $this->client->getResponse()->getContent(),
        );

        $this->logos()->supprimer($nom);
    }

    /**
     * Sans cette règle, le logo disparaîtrait du reçu dès la coupure de réseau
     * alors que tout le reste de la caisse continuerait de fonctionner. Le Service
     * Worker met déjà `/uploads/` en cache : le logo en bénéficie parce qu'il est
     * rangé là, et pas ailleurs.
     */
    public function testLeLogoEstServiDepuisUploadsDoncMisEnCacheHorsLigne(): void
    {
        $this->assertStringStartsWith('/uploads/', (string) $this->logos()->chemin('x.png'));
        $this->assertStringContainsString(
            "const IMAGES = '/uploads/';",
            (string) file_get_contents(\dirname(__DIR__, 2).'/public/sw.js'),
        );
    }

    // ------------------------------ L'identité reprise par les écrans de gestion

    /**
     * Le back-office et le pilotage portaient « ZedPOS » en dur — le nom du
     * logiciel, là où l'exploitant attend celui de sa boutique. Les deux écrans
     * lisent maintenant la même table que le ticket.
     */
    #[DataProvider('ecransDeGestion')]
    public function testLesEcransDeGestionAffichentLeNomDeLEtablissement(string $url, string $role): void
    {
        $this->parametres()->enregistrer([
            CleParametre::RAISON_SOCIALE->value => 'DELICES DU CAMPUS',
        ]);

        $this->client->loginUser('ROLE_DIRIGEANTE' === $role ? $this->dirigeante : $this->gerant);
        $crawler = $this->client->request('GET', $url);
        $this->assertResponseIsSuccessful();

        $entete = $crawler->filter('header, aside')->text();
        $this->assertStringContainsString('DELICES DU CAMPUS', $entete);
        $this->assertStringNotContainsString('ZedPOS', $entete, 'Le nom du logiciel n\'a rien à faire dans l\'en-tête.');

        // L'onglet du navigateur aussi : c'est ce qu'on lit avec dix onglets ouverts.
        $this->assertStringContainsString('DELICES DU CAMPUS', $crawler->filter('title')->text());
    }

    /**
     * Même règle qu'en tête de ticket : « ETS KOUAME SARL » est ce qu'on écrit au
     * fisc, pas ce qu'on lit sur la devanture.
     */
    #[DataProvider('ecransDeGestion')]
    public function testLEnseignePrimeSurLaRaisonSocialeDansLesEnTetes(string $url, string $role): void
    {
        $this->parametres()->enregistrer([
            CleParametre::RAISON_SOCIALE->value => 'ETS KOUAME SARL',
            CleParametre::ENSEIGNE->value => 'DELICES DU CAMPUS',
        ]);

        $this->client->loginUser('ROLE_DIRIGEANTE' === $role ? $this->dirigeante : $this->gerant);
        $crawler = $this->client->request('GET', $url);

        $entete = $crawler->filter('header, aside')->text();
        $this->assertStringContainsString('DELICES DU CAMPUS', $entete);
        $this->assertStringNotContainsString('ETS KOUAME SARL', $entete);
    }

    #[DataProvider('ecransDeGestion')]
    public function testLeLogoFigureDansLesEnTetesDeGestion(string $url, string $role): void
    {
        $nom = $this->logos()->enregistrer($this->televersement());
        $this->parametres()->definirLogo($nom);

        $this->client->loginUser('ROLE_DIRIGEANTE' === $role ? $this->dirigeante : $this->gerant);
        $crawler = $this->client->request('GET', $url);

        $img = $crawler->filter('header img, aside img');
        $this->assertGreaterThan(0, $img->count(), 'Le logo doit figurer dans l\'en-tête.');
        $this->assertSame('/uploads/boutique/'.$nom, $img->first()->attr('src'));

        $this->logos()->supprimer($nom);
    }

    /**
     * Sans logo, la pastille « Z » reste : un en-tête ne doit pas s'ouvrir sur un
     * trou, et c'est l'état de toute installation neuve.
     */
    #[DataProvider('ecransDeGestion')]
    public function testSansLogoLaPastilleParDefautResteAffichee(string $url, string $role): void
    {
        $this->client->loginUser('ROLE_DIRIGEANTE' === $role ? $this->dirigeante : $this->gerant);
        $crawler = $this->client->request('GET', $url);

        $this->assertCount(0, $crawler->filter('header img, aside img'));
        $this->assertStringContainsString('Z', $crawler->filter('header, aside')->text());
    }

    /** @return iterable<string, array{string, string}> */
    public static function ecransDeGestion(): iterable
    {
        yield 'back-office' => ['/admin', 'ROLE_GERANT'];
        yield 'pilotage' => ['/pilotage', 'ROLE_DIRIGEANTE'];
    }

    /** Encaisse une vente et renvoie son uuid. */
    private function encaisser(): string
    {
        static::getContainer()->get(SessionCaisseService::class)->ouvrir($this->caissier, 0);
        $this->client->loginUser($this->caissier);

        $uuid = (string) Uuid::v4();
        $this->client->request('POST', '/api/vente', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'uuid' => $uuid,
            'mode' => 'BOULANGERIE',
            'lignes' => [['articleId' => $this->articleId, 'quantite' => 2]],
            'reglements' => [['mode' => 'ESPECES', 'montant' => 30000]],
        ]));
        $this->assertResponseStatusCodeSame(201);

        return $uuid;
    }
}
