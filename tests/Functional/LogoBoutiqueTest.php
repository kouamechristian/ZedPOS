<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\FamilleProduit;
use App\Entity\Utilisateur;
use App\Enum\CleParametre;
use App\Service\LogoBoutique;
use App\Service\LogoThermique;
use App\Service\ParametresBoutique;
use App\Service\SessionCaisseService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
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

    /**
     * Le ticket imprimé hors ligne sort d'une fenêtre vierge, que le Service
     * Worker ne contrôle pas forcément : un `<img src="/uploads/…">` y partait au
     * réseau, échouait, et le ticket sortait sans logo. Il imprime donc le logo
     * thermique, que la page de caisse porte déjà en URL `data:` — aucune requête.
     */
    public function testLeTicketImprimeHorsLigneNeVaPasChercherLeLogoSurLeReseau(): void
    {
        // Un dessin, pas un aplat : un logo uniforme n'a rien à imprimer.
        $nom = $this->logoDessine([255, 255, 255], [60, 30, 10]);

        $crawler = $this->ouvrirLaCaisse();
        $parametres = json_decode((string) $crawler->filter('[data-ticket-parametres-value]')->attr('data-ticket-parametres-value'), true);
        $this->assertStringStartsWith('data:image/png;base64,', $parametres['logo']);

        $source = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/controllers/ticket_controller.js');
        $this->assertStringContainsString('${this.htmlRecuLocal(ticket, true)}</body>', $source, 'La fenêtre d\'impression doit demander le rendu « papier ».');
        $this->assertStringContainsString('const logo = thermique ? ticket.logo :', $source, 'Sur le papier, le logo thermique en URL data: passe en premier.');

        $this->logos()->supprimer($nom);
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

    // ------------------------------------------- Le logo sur la tête thermique

    /**
     * L'agent matériel imprime le ticket automatique : sans logo dans sa charge
     * utile, il n'en imprimait aucun. Il le reçoit désormais prêt à pousser —
     * toute la largeur de la tête, dessin recadré, centré, noir et blanc pur.
     */
    public function testLeTicketMaterielPorteLeLogoPretPourLaTeteThermique(): void
    {
        $nom = $this->logoDessine([255, 255, 255], [60, 30, 10]);

        $uuid = $this->encaisser();
        $this->client->request('GET', '/caisse/ticket/'.$uuid.'/materiel');
        $this->assertResponseIsSuccessful();
        $logo = json_decode($this->client->getResponse()->getContent(), true)['ticket']['logo'];

        $this->assertIsString($logo);
        $this->assertStringStartsWith('data:image/png;base64,', $logo);

        $image = $this->decoder($logo);
        $this->assertSame(384, imagesx($image), 'Toute la largeur imprimable de la tête : l\'agent n\'a rien à redimensionner.');
        $this->assertSame(128, imagesy($image), 'Même hauteur maximale que le logo du ticket HTML (16 mm).');
        $this->assertDessinCentre($image);

        $this->logos()->supprimer($nom);
    }

    /**
     * Le papier est blanc, quel que soit le fond du fichier téléversé :
     *
     * - **fond coloré** — le cas réel d'un logo carré sur fond orange, que la
     *   première version tramait en grisaille au point de noyer le dessin ;
     * - **fond transparent** — la tête ne connaît pas la transparence, et laissée
     *   telle quelle elle sortirait en aplat noir ;
     * - **fond sombre** — un logo clair sur fond noir ferait sinon imprimer un
     *   pavé noir plein à chaque vente.
     *
     * Le dessin de 100 × 50 est posé sur une toile de 400 × 150 : si le fond
     * passait à l'impression, le recadrage prendrait toute la toile et le dessin
     * ne tomberait plus sur les colonnes attendues.
     *
     * @param array{int, int, int}|null $fond   null = transparent
     * @param array{int, int, int}      $dessin
     */
    #[DataProvider('fondsDeLogo')]
    public function testLeFondDuLogoSortToujoursBlanc(?array $fond, array $dessin): void
    {
        $nom = $this->logoDessine($fond, $dessin);

        $logo = static::getContainer()->get(LogoThermique::class)->pourImpression();
        $this->assertNotNull($logo);

        $image = $this->decoder($logo);
        $this->assertSame(128, imagesy($image), 'Recadré sur le dessin, puis ramené à 16 mm de haut.');
        $this->assertDessinCentre($image);

        $this->logos()->supprimer($nom);
    }

    /** @return iterable<string, array{array{int, int, int}|null, array{int, int, int}}> */
    public static function fondsDeLogo(): iterable
    {
        yield 'fond orange, dessin brun' => [[254, 189, 89], [60, 30, 10]];
        yield 'fond transparent, dessin noir' => [null, [0, 0, 0]];
        yield 'fond sombre, dessin blanc' => [[15, 20, 26], [255, 255, 255]];
    }

    public function testSansLogoLeTicketMaterielNeTransporteRien(): void
    {
        $uuid = $this->encaisser();
        $this->client->request('GET', '/caisse/ticket/'.$uuid.'/materiel');

        $ticket = json_decode($this->client->getResponse()->getContent(), true)['ticket'];
        $this->assertArrayHasKey('logo', $ticket, 'La clé est toujours là : l\'agent n\'a pas à tester son existence.');
        $this->assertNull($ticket['logo']);
    }

    /** Une image uniforme ne laisserait sur le papier qu'une bande vide : pas de logo. */
    public function testUnLogoUniformeNImprimeRien(): void
    {
        $nom = $this->logoDessine([255, 255, 255], [255, 255, 255]);

        $this->assertNull(static::getContainer()->get(LogoThermique::class)->pourImpression());

        $this->logos()->supprimer($nom);
    }

    /**
     * Téléverse et retient un logo d'essai : un rectangle de 100 × 50 au centre
     * d'une toile de 400 × 150.
     *
     * @param array{int, int, int}|null $fond   null = transparent
     * @param array{int, int, int}      $dessin
     */
    private function logoDessine(?array $fond, array $dessin): string
    {
        $image = imagecreatetruecolor(400, 150);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, null === $fond
            ? imagecolorallocatealpha($image, 0, 0, 0, 127)
            : imagecolorallocate($image, ...$fond));
        imagefilledrectangle($image, 150, 50, 249, 99, imagecolorallocate($image, ...$dessin));

        $chemin = sys_get_temp_dir().'/zedpos-logo-'.bin2hex(random_bytes(4)).'.png';
        imagepng($image, $chemin);
        $this->temporaires[] = $chemin;

        $nom = $this->logos()->enregistrer(new UploadedFile($chemin, 'logo.png', 'image/png', null, true));
        $this->parametres()->definirLogo($nom);

        return $nom;
    }

    private function decoder(string $logo): \GdImage
    {
        $image = imagecreatefromstring((string) base64_decode(substr($logo, \strlen('data:image/png;base64,')), true));
        $this->assertNotFalse($image, 'Le logo doit être un PNG lisible.');

        return $image;
    }

    /**
     * Le rectangle de 100 × 50, agrandi à 256 × 128, doit occuper les colonnes
     * 64 à 319 des 384 — et l'image ne doit contenir que du noir et du blanc.
     * Un point de tolérance : le rééchantillonnage peut adoucir un bord.
     */
    private function assertDessinCentre(\GdImage $image): void
    {
        $gauche = \PHP_INT_MAX;
        $droite = -1;
        $teintes = [];
        for ($y = 0; $y < imagesy($image); ++$y) {
            for ($x = 0; $x < imagesx($image); ++$x) {
                $rgb = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                $teintes[$rgb['red'].','.$rgb['green'].','.$rgb['blue']] = true;
                if (0 === $rgb['red'] + $rgb['green'] + $rgb['blue']) {
                    $gauche = min($gauche, $x);
                    $droite = max($droite, $x);
                }
            }
        }

        $this->assertSame([], array_values(array_diff(array_keys($teintes), ['0,0,0', '255,255,255'])), 'Noir ou blanc, rien entre les deux.');
        $this->assertEqualsWithDelta(64, $gauche, 1, 'Le dessin est centré dans la largeur de la tête.');
        $this->assertEqualsWithDelta(319, $droite, 1, 'Le fond n\'est pas imprimé : seul le dessin l\'est.');
    }

    // ----------------------------------------------- L'identité reprise en caisse

    /**
     * La caissière travaille sous l'enseigne de la boutique, pas sous le nom du
     * logiciel : même règle que les écrans de gestion, enseigne comprise.
     */
    public function testLaCaisseAfficheLeLogoEtLeNomDeLEtablissement(): void
    {
        $this->parametres()->enregistrer([
            CleParametre::RAISON_SOCIALE->value => 'ETS KOUAME SARL',
            CleParametre::ENSEIGNE->value => 'DELICES DU CAMPUS',
        ]);
        $nom = $this->logos()->enregistrer($this->televersement());
        $this->parametres()->definirLogo($nom);

        $crawler = $this->ouvrirLaCaisse();

        $identite = $crawler->filter('aside.flanc');
        $this->assertStringContainsString('DELICES DU CAMPUS', $identite->text());
        $this->assertStringNotContainsString('ETS KOUAME SARL', $identite->text());
        $this->assertStringNotContainsString('ZedPOS', $identite->text(), 'Le nom du logiciel n\'a rien à faire dans l\'en-tête de la caisse.');
        $this->assertSame('/uploads/boutique/'.$nom, $identite->filter('img')->first()->attr('src'));

        // Repli tablette : le panneau latéral disparaît, l'identité passe dans la barre du haut.
        $this->assertStringContainsString('DELICES DU CAMPUS', $crawler->filter('header')->text());
        $this->assertStringContainsString('DELICES DU CAMPUS', $crawler->filter('title')->text());

        $this->logos()->supprimer($nom);
    }

    public function testSansLogoLaCaisseGardeLaPastilleParDefaut(): void
    {
        $crawler = $this->ouvrirLaCaisse();

        $identite = $crawler->filter('aside.flanc');
        $this->assertCount(0, $identite->filter('img'));
        $this->assertStringContainsString('Z', $identite->text());
    }

    /**
     * L'écran du code PIN est le premier que la caissière voit en ouvrant le
     * comptoir, et il donne sur une caisse qui porte déjà l'enseigne : il porte donc
     * la même identité, jamais le nom du logiciel.
     */
    public function testLEcranDuCodePinPorteLIdentiteDeLEtablissement(): void
    {
        $this->parametres()->enregistrer([
            CleParametre::RAISON_SOCIALE->value => 'ETS KOUAME SARL',
            CleParametre::ENSEIGNE->value => 'DELICES DU CAMPUS',
        ]);
        $nom = $this->logos()->enregistrer($this->televersement());
        $this->parametres()->definirLogo($nom);

        $crawler = $this->client->request('GET', '/caisse/login');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('DELICES DU CAMPUS', $crawler->filter('h1')->text());
        $this->assertStringContainsString('DELICES DU CAMPUS', $crawler->filter('title')->text());
        $this->assertStringNotContainsString('ETS KOUAME SARL', $crawler->filter('body')->text(), 'L\'enseigne prime, comme en tête de ticket.');
        $this->assertStringNotContainsString('ZedPOS', $crawler->filter('body')->text(), 'Le nom du logiciel n\'a rien à faire sur l\'écran de la caissière.');
        $this->assertSame('/uploads/boutique/'.$nom, $crawler->filter('img')->first()->attr('src'));
        // L'identité s'ajoute au pavé, elle ne lui prend pas sa place.
        $this->assertCount(10, $crawler->filter('button[data-chiffre]'));

        $this->logos()->supprimer($nom);
    }

    public function testSansLogoLEcranDuCodePinGardeLaPastilleParDefaut(): void
    {
        $crawler = $this->client->request('GET', '/caisse/login');

        $this->assertCount(0, $crawler->filter('img'));
        $this->assertStringContainsString('Z', $crawler->filter('body')->text());
    }

    private function ouvrirLaCaisse(): Crawler
    {
        static::getContainer()->get(SessionCaisseService::class)->ouvrir($this->caissier, 0);
        $this->client->loginUser($this->caissier);

        $crawler = $this->client->request('GET', '/caisse');
        $this->assertResponseIsSuccessful();

        return $crawler;
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
