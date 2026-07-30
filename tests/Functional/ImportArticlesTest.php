<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\Utilisateur;
use App\Service\ImportArticles;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Import du catalogue en masse : nom et prix de vente, depuis un CSV.
 *
 * Deux invariants dominent. **L'import ne contourne pas la règle du prix** : fixer
 * un prix de vente est réservé à la dirigeante, et un gérant qui importe un fichier
 * chiffré ne doit pas obtenir autrement ce que le formulaire lui refuse. **L'import
 * n'écrase rien** : un article déjà au catalogue est laissé intact, prix compris.
 *
 * Le reste couvre ce qui sort réellement d'un tableur — point-virgule, BOM,
 * Windows-1252, espaces insécables, séparateurs de milliers, ligne d'en-têtes.
 */
class ImportArticlesTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Utilisateur $gerant;
    private Utilisateur $dirigeante;
    private Utilisateur $caissier;
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

        $this->gerant = new Utilisateur('koffi@test.ci', 'Koffi');
        $this->gerant->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        $this->em->persist($this->gerant);

        $this->dirigeante = new Utilisateur('aya@test.ci', 'Aya');
        $this->dirigeante->setRoles(['ROLE_DIRIGEANTE'])->setMotDePasse('x');
        $this->em->persist($this->dirigeante);

        $this->caissier = new Utilisateur('fatou@test.ci', 'Fatou');
        $this->caissier->setRoles(['ROLE_CAISSIER'])->setCodePin('x');
        $this->em->persist($this->caissier);

        $this->em->flush();
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

    private function service(): ImportArticles
    {
        return static::getContainer()->get(ImportArticles::class);
    }

    /** @return array<string, Article> indexés par nom */
    private function catalogue(): array
    {
        $this->em->clear();

        $articles = [];
        foreach ($this->em->getRepository(Article::class)->findAll() as $article) {
            $articles[$article->getNom()] = $article;
        }

        return $articles;
    }

    /** Dépose un contenu sur disque et le passe au formulaire d'import. */
    private function importerParLEcran(string $contenu, Utilisateur $auteur): void
    {
        $chemin = sys_get_temp_dir().'/zedpos-import-'.bin2hex(random_bytes(4)).'.csv';
        file_put_contents($chemin, $contenu);
        $this->temporaires[] = $chemin;

        $this->client->loginUser($auteur);
        $crawler = $this->client->request('GET', '/admin/articles/importer');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Importer')->form();
        $form['import_articles[fichier]']->upload($chemin);
        $this->client->submit($form);
    }

    // ------------------------------------------------------ Le bouton et l'écran

    public function testLeBoutonImporterFigureSurLeCatalogue(): void
    {
        $this->client->loginUser($this->gerant);
        $crawler = $this->client->request('GET', '/admin/articles');
        $this->assertResponseIsSuccessful();

        $bouton = $crawler->filter('a[href="/admin/articles/importer"]');
        $this->assertCount(1, $bouton);
        $this->assertSame('Importer', trim($bouton->text()));
    }

    public function testEcranInterditAuCaissier(): void
    {
        $this->client->loginUser($this->caissier);
        $this->client->request('GET', '/admin/articles/importer');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testLeModeleEstTelechargeableEtPorteLeBom(): void
    {
        $this->client->loginUser($this->gerant);
        $this->client->request('GET', '/admin/articles/importer/modele');

        $this->assertResponseIsSuccessful();
        $corps = (string) $this->client->getResponse()->getContent();

        // Sans BOM, Excel sous Windows lit le fichier en ANSI et massacre les accents.
        $this->assertStringStartsWith("\u{FEFF}", $corps);
        $this->assertStringContainsString('Baguette;150', $corps);
        $this->assertStringContainsString(
            'attachment; filename="modele-articles.csv"',
            (string) $this->client->getResponse()->headers->get('Content-Disposition'),
        );
    }

    // ------------------------------------------------------------ Le cas courant

    public function testLaDirigeanteImporteDesArticlesAvecLeurPrix(): void
    {
        $this->importerParLEcran(
            "Nom;Prix\nBaguette;150\nPain au chocolat;300\n",
            $this->dirigeante,
        );

        $this->assertResponseRedirects('/admin/articles/importer');

        $catalogue = $this->catalogue();
        $this->assertCount(2, $catalogue);

        // Le prix est saisi en FCFA et stocké en centimes.
        $this->assertSame(15000, $catalogue['Baguette']->getPrixVenteTtc());
        $this->assertSame(30000, $catalogue['Pain au chocolat']->getPrixVenteTtc());

        // Un article avec un prix part actif : il est vendable en l'état.
        $this->assertTrue($catalogue['Baguette']->isActif());
        $this->assertSame('pièce', $catalogue['Baguette']->getUnite());
    }

    public function testLeCompteRenduSAfficheApresLaRedirection(): void
    {
        $this->importerParLEcran("Baguette;150\nCroissant;200\n", $this->dirigeante);

        $crawler = $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        $texte = $crawler->filter('body')->text();
        $this->assertStringContainsString('Résultat de l\'import', $texte);
        $this->assertStringContainsString('Baguette', $texte);
        $this->assertStringContainsString('Croissant', $texte);
    }

    /**
     * Le compte rendu passe par la session : rafraîchir la page ne doit pas le
     * réafficher, on croirait avoir rejoué l'import.
     */
    public function testLeCompteRenduNestAfficheQuUneFois(): void
    {
        $this->importerParLEcran("Baguette;150\n", $this->dirigeante);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/admin/articles/importer');
        $this->assertStringNotContainsString('Résultat de l\'import', $crawler->filter('body')->text());
    }

    // -------------------------------------- La règle du prix n'est pas contournable

    /**
     * L'invariant central. Un gérant ne peut pas fixer un prix au formulaire (le
     * champ n'y est même pas) : l'import ne doit pas être la porte de service.
     */
    public function testUnGerantNimportePasDePrix(): void
    {
        $this->importerParLEcran("Baguette;150\nCroissant;200\n", $this->gerant);

        $catalogue = $this->catalogue();
        $this->assertCount(2, $catalogue);

        foreach ($catalogue as $article) {
            $this->assertSame(0, $article->getPrixVenteTtc(), 'Le prix du fichier ne doit pas être repris.');
            // Même règle qu'à la création à l'unité : sans prix, l'article ne part
            // pas en caisse — sinon l'import gratifierait les clients.
            $this->assertFalse($article->isActif(), 'Un article sans prix reste inactif.');
        }
    }

    /** Le gérant doit l'apprendre **avant** de déposer son fichier, pas après. */
    public function testLEcranAvertitLeGerantQueLesPrixSerontIgnores(): void
    {
        $this->client->loginUser($this->gerant);
        $texte = $this->client->request('GET', '/admin/articles/importer')->filter('body')->text();

        $this->assertStringContainsString('Les prix ne seront pas repris', $texte);

        $this->client->loginUser($this->dirigeante);
        $texte = $this->client->request('GET', '/admin/articles/importer')->filter('body')->text();

        $this->assertStringNotContainsString('Les prix ne seront pas repris', $texte);
    }

    public function testLeCompteRenduSignaleLesPrixEcartes(): void
    {
        $this->importerParLEcran("Baguette;150\n", $this->gerant);
        $texte = $this->client->followRedirect()->filter('body')->text();

        $this->assertStringContainsString('Les prix du fichier n\'ont pas été repris', $texte);
    }

    // -------------------------------------------------- L'import n'écrase rien

    public function testUnArticleDejaAuCatalogueEstIgnoreEtSonPrixIntact(): void
    {
        $existant = new Article('Baguette', 12500, 'pièce');
        $this->em->persist($existant);
        $this->em->flush();

        $rapport = $this->service()->importer("Baguette;150\nCroissant;200\n", avecPrix: true);

        $this->assertSame(['Baguette'], $rapport->doublons);
        $this->assertSame(1, $rapport->nombreCreees());

        $catalogue = $this->catalogue();
        $this->assertSame(12500, $catalogue['Baguette']->getPrixVenteTtc(), 'Le prix en place ne doit pas bouger.');
        $this->assertCount(2, $catalogue);
    }

    /** Ni la casse ni les espaces superflus ne font deux articles différents. */
    public function testLeDoublonEstDetecteSansEgardALaCasse(): void
    {
        $this->em->persist(new Article('Baguette', 12500, 'pièce'));
        $this->em->flush();

        $rapport = $this->service()->importer("  BAGUETTE  ;150\n", avecPrix: true);

        $this->assertSame(0, $rapport->nombreCreees());
        $this->assertCount(1, $rapport->doublons);
    }

    /** Deux fois le même nom dans le fichier : deux articles indistinguables en caisse. */
    public function testUnDoublonInterneAuFichierNeCreeQuUnArticle(): void
    {
        $rapport = $this->service()->importer("Baguette;150\nBaguette;200\n", avecPrix: true);

        $this->assertSame(1, $rapport->nombreCreees());
        $this->assertSame(['Baguette'], $rapport->doublons);
        $this->assertCount(1, $this->catalogue());
    }

    /**
     * Un article importé puis désactivé ne doit pas revenir en double au prochain
     * fichier — d'où la recherche des doublons sur tout le catalogue, inactifs compris.
     */
    public function testUnArticleInactifCompteAussiCommeDoublon(): void
    {
        $inactif = new Article('Baguette', 0, 'pièce');
        $inactif->setActif(false);
        $this->em->persist($inactif);
        $this->em->flush();

        $rapport = $this->service()->importer("Baguette;150\n", avecPrix: true);

        $this->assertSame(0, $rapport->nombreCreees());
        $this->assertSame(['Baguette'], $rapport->doublons);
    }

    // ------------------------------------------ Ce qui sort vraiment d'un tableur

    #[DataProvider('prixAcceptes')]
    public function testLesFormatsDePrixDunTableurSontAcceptes(string $saisi, int $centimes): void
    {
        $rapport = $this->service()->importer("Baguette;{$saisi}\n", avecPrix: true);

        $this->assertSame([], $rapport->rejets, 'Aucun rejet attendu pour « '.$saisi.' ».');
        $this->assertSame($centimes, $rapport->creees[0]['prix']);
    }

    /** @return iterable<string, array{string, int}> */
    public static function prixAcceptes(): iterable
    {
        yield 'entier' => ['1500', 150000];
        yield 'espace de milliers' => ['1 500', 150000];
        yield 'espace insécable' => ["1\u{00a0}500", 150000];
        yield 'point de milliers' => ['1.500', 150000];
        yield 'virgule de milliers' => ['1,500', 150000];
        yield 'décimale nulle' => ['1500,00', 150000];
        yield 'devise mentionnée' => ['1500 FCFA', 150000];
        yield 'zéro' => ['0', 0];
    }

    /**
     * Le franc CFA ne circule pas en centimes. Arrondir un montant en silence est
     * précisément ce que cette application interdit : la ligne est refusée, avec sa
     * raison, plutôt que devinée.
     */
    #[DataProvider('prixRefuses')]
    public function testUnPrixIllisibleFaitRejeterLaLigne(string $saisi): void
    {
        $rapport = $this->service()->importer("Baguette;{$saisi}\n", avecPrix: true);

        $this->assertSame(0, $rapport->nombreCreees());
        $this->assertCount(1, $rapport->rejets);
        $this->assertStringContainsString('Prix illisible', $rapport->rejets[0]['raison']);
        // La ligne fautive est rendue telle quelle : c'est elle qu'on va corriger.
        $this->assertStringContainsString($saisi, $rapport->rejets[0]['contenu']);
    }

    /** @return iterable<string, array{string}> */
    public static function prixRefuses(): iterable
    {
        yield 'centimes non nuls' => ['1500,50'];
        yield 'texte' => ['gratuit'];
        yield 'négatif' => ['-150'];
    }

    /** Une ligne d'en-têtes de tableur ne doit pas ressortir en erreur. */
    public function testLaLigneDEnTetesEstIgnoreeEnSilence(): void
    {
        $rapport = $this->service()->importer("Nom;Prix de vente (FCFA)\nBaguette;150\n", avecPrix: true);

        $this->assertSame([], $rapport->rejets);
        $this->assertSame(1, $rapport->nombreCreees());
        $this->assertSame('Baguette', $rapport->creees[0]['nom']);
    }

    /** Un fichier sans en-têtes ne doit pas perdre sa première ligne. */
    public function testSansEnTetesLaPremiereLigneEstImportee(): void
    {
        $rapport = $this->service()->importer("Baguette;150\nCroissant;200\n", avecPrix: true);

        $this->assertSame(2, $rapport->nombreCreees());
    }

    /**
     * Le défaut le plus coûteux de cet import, et il a bel et bien existé : la
     * détection d'en-tête ne tenait qu'à « la deuxième colonne n'est pas un prix ».
     * Une première ligne au prix mal tapé passait donc pour un en-tête et
     * **disparaissait sans un mot** — l'article manquait au catalogue, le compte
     * rendu n'en disait rien, et rien ne permettait de le remarquer.
     */
    public function testUnePremiereLigneAuPrixFautifEstRejeteeEtNonAvalee(): void
    {
        $rapport = $this->service()->importer("Baguette;gratuit\nCroissant;200\n", avecPrix: true);

        $this->assertSame(1, $rapport->nombreCreees());
        $this->assertSame('Croissant', $rapport->creees[0]['nom']);

        $this->assertCount(1, $rapport->rejets, 'La ligne fautive doit être signalée, pas supprimée.');
        $this->assertSame(1, $rapport->rejets[0]['ligne']);
        $this->assertStringContainsString('Baguette', $rapport->rejets[0]['contenu']);
    }

    /**
     * Corollaire assumé : un en-tête dont le libellé n'est pas reconnu ressort en
     * ligne rejetée. C'est visible et corrigible — l'inverse, l'avaler, ne l'est pas.
     */
    public function testUnEnTeteNonReconnuEstSignaleEtNonAvale(): void
    {
        $rapport = $this->service()->importer("Colonne A;Colonne B\nBaguette;150\n", avecPrix: true);

        $this->assertSame(1, $rapport->nombreCreees());
        $this->assertCount(1, $rapport->rejets);
        $this->assertSame(1, $rapport->rejets[0]['ligne']);
    }

    /** Les en-têtes courants, eux, sont bien reconnus. */
    #[DataProvider('enTetesReconnus')]
    public function testLesEnTetesCourantsSontReconnus(string $premiereCellule): void
    {
        $rapport = $this->service()->importer("{$premiereCellule};Prix de vente\nBaguette;150\n", avecPrix: true);

        $this->assertSame([], $rapport->rejets, 'En-tête non reconnu : '.$premiereCellule);
        $this->assertSame(1, $rapport->nombreCreees());
    }

    /** @return iterable<string, array{string}> */
    public static function enTetesReconnus(): iterable
    {
        yield 'Nom' => ['Nom'];
        yield 'Article' => ['Article'];
        yield 'Produit' => ['Produit'];
        yield 'Désignation' => ['Désignation'];
        yield 'Libelle sans accent' => ['Libelle'];
        yield 'libellé complet' => ['Nom de l\'article'];
    }

    #[DataProvider('separateurs')]
    public function testLeSeparateurEstReconnuToutSeul(string $contenu): void
    {
        $rapport = $this->service()->importer($contenu, avecPrix: true);

        $this->assertSame([], $rapport->rejets);
        $this->assertSame(1, $rapport->nombreCreees());
        $this->assertSame('Baguette', $rapport->creees[0]['nom']);
        $this->assertSame(15000, $rapport->creees[0]['prix']);
    }

    /** @return iterable<string, array{string}> */
    public static function separateurs(): iterable
    {
        yield 'point-virgule' => ["Baguette;150\n"];
        yield 'virgule' => ["Baguette,150\n"];
        yield 'tabulation' => ["Baguette\t150\n"];
    }

    /**
     * Excel sous Windows pose un BOM UTF-8 en tête de fichier. Sans le retirer, le
     * premier nom commencerait par trois octets invisibles : « Baguette » ne serait
     * plus jamais reconnu comme un doublon de « Baguette », sans que rien ne se voie.
     */
    public function testLeBomUtf8EstRetire(): void
    {
        $rapport = $this->service()->importer("\u{FEFF}Baguette;150\n", avecPrix: true);

        $this->assertSame('Baguette', $rapport->creees[0]['nom']);
    }

    /**
     * Excel francophone enregistre ses CSV en Windows-1252, pas en UTF-8 : sans
     * conversion, « Pâté » arriverait en base en « PÃ¢tÃ© », à retaper à la main.
     */
    public function testUnFichierWindows1252EstConverti(): void
    {
        $this->importerParLEcran(
            (string) mb_convert_encoding("Pâté en croûte;2500\n", 'Windows-1252', 'UTF-8'),
            $this->dirigeante,
        );

        $this->assertArrayHasKey('Pâté en croûte', $this->catalogue());
    }

    public function testUnFichierUtf8NestPasAbime(): void
    {
        $this->importerParLEcran("Pâté en croûte;2500\n", $this->dirigeante);

        $this->assertArrayHasKey('Pâté en croûte', $this->catalogue());
    }

    // ------------------------------------------------------------- Les garde-fous

    public function testUneLigneSansNomEstRejetee(): void
    {
        $rapport = $this->service()->importer(";150\n", avecPrix: true);

        $this->assertSame(0, $rapport->nombreCreees());
        $this->assertStringContainsString('Nom d\'article absent', $rapport->rejets[0]['raison']);
    }

    /** Tronquer un nom serait pire que le refuser : personne ne verrait la coupure. */
    public function testUnNomTropLongEstRejete(): void
    {
        $rapport = $this->service()->importer(str_repeat('A', 151).";150\n", avecPrix: true);

        $this->assertSame(0, $rapport->nombreCreees());
        $this->assertStringContainsString('Nom trop long', $rapport->rejets[0]['raison']);
    }

    /**
     * Un prix **absent** est une omission, pas une erreur : l'article naît sans
     * prix, donc inactif, et le compte rendu le dit.
     */
    public function testUnPrixAbsentDonneUnArticleInactif(): void
    {
        $rapport = $this->service()->importer("Baguette\nCroissant;\n", avecPrix: true);

        $this->assertSame([], $rapport->rejets);
        $this->assertSame(2, $rapport->nombreCreees());
        $this->assertSame(['Baguette', 'Croissant'], $rapport->sansPrix());

        foreach ($this->catalogue() as $article) {
            $this->assertFalse($article->isActif());
        }
    }

    /** Les lignes vides d'un fichier aéré ne doivent pas ressortir en erreurs. */
    public function testLesLignesVidesSontIgnorees(): void
    {
        $rapport = $this->service()->importer("Baguette;150\n\n;;\n\nCroissant;200\n", avecPrix: true);

        $this->assertSame([], $rapport->rejets);
        $this->assertSame(2, $rapport->nombreCreees());
    }

    /**
     * Le catalogue d'une boulangerie se compte en dizaines d'articles : au-delà du
     * plafond, ce n'est pas un catalogue qu'on importe, c'est un fichier qui n'est
     * pas celui qu'on croit.
     */
    public function testAuDelaDuPlafondLeResteDuFichierNestPasTraite(): void
    {
        $lignes = '';
        for ($i = 1; $i <= ImportArticles::MAX_LIGNES + 10; ++$i) {
            $lignes .= "Article {$i};150\n";
        }

        $rapport = $this->service()->importer($lignes, avecPrix: true);

        $this->assertSame(ImportArticles::MAX_LIGNES, $rapport->nombreCreees());
        $this->assertCount(1, $rapport->rejets);
        $this->assertStringContainsString('Plafond', $rapport->rejets[0]['raison']);
    }

    /** Un fichier vide ou hors sujet réaffiche le formulaire, avec un message. */
    public function testUnFichierSansAucuneLigneExploitableEstRefuse(): void
    {
        $this->importerParLEcran("\n\n", $this->dirigeante);

        // 422 : Turbo ne remplace pas la page sur un 200.
        $this->assertResponseStatusCodeSame(422);
        $this->assertCount(0, $this->catalogue());
    }

    /** Un import ne doit pas laisser la base à moitié garnie. */
    public function testRienNestEcritQuandToutesLesLignesSontRejetees(): void
    {
        $rapport = $this->service()->importer("Baguette;gratuit\nCroissant;1500,50\n", avecPrix: true);

        $this->assertSame(0, $rapport->nombreCreees());
        $this->assertCount(2, $rapport->rejets);
        $this->assertCount(0, $this->catalogue());
    }
}
