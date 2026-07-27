<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\FamilleProduit;
use App\Entity\FicheTechnique;
use App\Entity\Fournisseur;
use App\Entity\LigneFicheTechnique;
use App\Entity\LigneVente;
use App\Entity\MatierePremiere;
use App\Entity\Perte;
use App\Entity\SessionCaisse;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Enum\ModeVente;
use App\Enum\MotifPerte;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Recherche des tableaux du back-office.
 *
 * Neuf listes, un seul mécanisme ({@see \App\Repository\Recherche} côté requête,
 * `admin/_recherche.html.twig` côté rendu). Ce qui est vérifié ici n'est pas que
 * « ça filtre » — c'est que **chaque** tableau filtre, sur les champs par
 * lesquels on cherche réellement, et que le filtrage n'emporte pas ce qui
 * l'entoure : les autres filtres, les jokers SQL, une saisie vide.
 */
class RechercheBackOfficeTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

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

        $dirigeante = new Utilisateur('aya@test.ci', 'Aya Koné');
        $dirigeante->setRoles(['ROLE_DIRIGEANTE'])->setMotDePasse('x');
        $this->em->persist($dirigeante);

        $fatou = new Utilisateur('fatou@test.ci', 'Fatou Traoré');
        $fatou->setRoles(['ROLE_CAISSIER'])->setCodePin('a');
        $this->em->persist($fatou);

        $yao = new Utilisateur('yao@test.ci', 'Yao Kouassi');
        $yao->setRoles(['ROLE_CAISSIER'])->setCodePin('b');
        $this->em->persist($yao);

        // Deux familles, deux fournisseurs, deux matières, deux articles : le
        // minimum pour qu'une recherche ait quelque chose à écarter.
        $pains = new FamilleProduit('Pains');
        $boissons = new FamilleProduit('Boissons');
        $this->em->persist($pains);
        $this->em->persist($boissons);

        $moulin = (new Fournisseur())->setNom('Moulin du Sud')->setTelephone('0700000001');
        $brasserie = (new Fournisseur())->setNom('Brasserie Ivoire')->setTelephone('0700000002');
        $this->em->persist($moulin);
        $this->em->persist($brasserie);

        $farine = (new MatierePremiere('Farine', 'kg'))
            ->setStockActuel(300000)->setStockMini(50000)->setCoutMoyenPondere(45000)
            ->setFournisseur($moulin);
        $sucre = (new MatierePremiere('Sucre', 'kg'))
            ->setStockActuel(100000)->setStockMini(10000)->setCoutMoyenPondere(60000)
            ->setFournisseur($brasserie);
        $this->em->persist($farine);
        $this->em->persist($sucre);

        $baguette = new Article('Baguette', 15000, 'pièce');
        $baguette->setFamilleProduit($pains)->setTauxTva(0);
        $coca = new Article('Coca', 50000, 'pièce');
        $coca->setFamilleProduit($boissons)->setTauxTva(1800);
        $this->em->persist($baguette);
        $this->em->persist($coca);

        // Une fiche par article, pour que l'écran Production ait deux lignes.
        $ficheBaguette = new FicheTechnique($baguette);
        new LigneFicheTechnique($ficheBaguette, $farine, 250, 300);
        $this->em->persist($ficheBaguette);

        $ficheCoca = new FicheTechnique($coca);
        new LigneFicheTechnique($ficheCoca, $sucre, 100, 0);
        $this->em->persist($ficheCoca);

        // Une session et une vente par caissière : clôtures et ventes se
        // distinguent alors par le nom, qui est l'entrée de recherche.
        foreach ([[$fatou, 'V260726-00001', $baguette], [$yao, 'V260726-00002', $coca]] as [$caissier, $numero, $article]) {
            $session = new SessionCaisse($caissier, 0);
            $this->em->persist($session);

            $vente = new Vente($session, ModeVente::BOULANGERIE, $numero, $article->getPrixVenteTtc(), 0, $article->getPrixVenteTtc());
            new LigneVente($vente, $article, 1000, $article->getPrixVenteTtc(), 0, null);
            $this->em->persist($vente);
        }

        // Deux pertes, l'une sur une matière, l'autre sur un article : la
        // recherche doit atteindre les deux libellés.
        $perteFarine = new Perte(MotifPerte::CASSE, 1000, 45000);
        $perteFarine->setMatierePremiere($farine);
        $this->em->persist($perteFarine);

        $perteCoca = new Perte(MotifPerte::PERIME, 1000, 50000);
        $perteCoca->setArticle($coca);
        $this->em->persist($perteCoca);

        $this->em->flush();

        $this->client->loginUser($dirigeante);
    }

    /**
     * Texte du tableau **filtré** de la page.
     *
     * `last()` et non `first()` : l'écran Pertes en compte trois, dont deux
     * synthèses (ventilation par motif, top 5) qui ne dépendent pas de la
     * recherche. Le détail — le seul que `q` filtre — vient en dernier. Ailleurs
     * il n'y a qu'un tableau et la distinction ne coûte rien.
     */
    private function texte(string $url): string
    {
        $crawler = $this->client->request('GET', $url);
        $this->assertResponseIsSuccessful('Échec du rendu de '.$url);

        return $crawler->filter('table')->last()->text();
    }

    // ------------------------------------------------- Chaque tableau filtre

    /**
     * @param string $url      la liste, avec sa recherche
     * @param string $attendu  ce qui doit rester
     * @param string $exclu    ce qui doit disparaître
     */
    #[DataProvider('tableaux')]
    public function testChaqueTableauFiltre(string $url, string $attendu, string $exclu): void
    {
        $texte = $this->texte($url);

        $this->assertStringContainsString($attendu, $texte);
        $this->assertStringNotContainsString($exclu, $texte);
    }

    public static function tableaux(): array
    {
        return [
            'articles' => ['/admin/articles?q=bague', 'Baguette', 'Coca'],
            'familles' => ['/admin/familles?q=pain', 'Pains', 'Boissons'],
            'fournisseurs' => ['/admin/fournisseurs?q=moulin', 'Moulin du Sud', 'Brasserie Ivoire'],
            'stock par matière' => ['/admin/stock?q=farine', 'Farine', 'Sucre'],
            // On cherche une matière autant par qui la livre que par son nom.
            'stock par fournisseur' => ['/admin/stock?q=brasserie', 'Sucre', 'Farine'],
            'ventes par numéro' => ['/admin/ventes?q=00002', 'V260726-00002', 'V260726-00001'],
            'ventes par caissière' => ['/admin/ventes?q=fatou', 'V260726-00001', 'V260726-00002'],
            'production par produit' => ['/admin/production?q=coca', 'Coca', 'Baguette'],
            'production par matière' => ['/admin/production?q=farine', 'Baguette', 'Coca'],
            'clôtures' => ['/admin/clotures?q=yao', 'Yao Kouassi', 'Fatou Traoré'],
            'utilisateurs par nom' => ['/admin/utilisateurs?q=fatou', 'Fatou Traoré', 'Yao Kouassi'],
            'utilisateurs par e-mail' => ['/admin/utilisateurs?q=aya@', 'Aya Koné', 'Yao Kouassi'],
            'pertes par matière' => ['/admin/pertes?q=farine', 'Farine', 'Coca'],
            'pertes par article' => ['/admin/pertes?q=coca', 'Coca', 'Farine'],
        ];
    }

    // ------------------------------------------------------------ Garde-fous

    /**
     * Le piège classique d'un `LIKE` écrit à la main : `%` et `_` sont des jokers
     * SQL. Sans échappement, chercher « 100% » ou « x_y » ramène la table entière
     * — et personne ne s'en aperçoit, puisque le tableau se remplit au lieu de se
     * vider.
     *
     * Le contrôle porte sur les fournisseurs, dont aucun champ ne contient ces
     * deux caractères. Sur les utilisateurs il serait faussé : les rôles stockés
     * (`ROLE_CAISSIER`) contiennent un souligné, et chercher un souligné
     * *littéral* doit précisément les ramener.
     */
    public function testLesJokersSqlNeSontPasInterpretes(): void
    {
        // Témoin : sur la même liste, un terme réel filtre bien.
        $this->assertStringContainsString('Moulin du Sud', $this->texte('/admin/fournisseurs?q=moulin'));

        foreach (['%', '_', '%%', '_%'] as $joker) {
            $texte = $this->texte('/admin/fournisseurs?q='.urlencode($joker));

            $this->assertStringNotContainsString(
                'Moulin du Sud',
                $texte,
                \sprintf('« %s » ne doit pas tout ramener.', $joker),
            );
        }
    }

    public function testUneRechercheVideNeFiltreRien(): void
    {
        foreach (['', '   '] as $vide) {
            $texte = $this->texte('/admin/utilisateurs?q='.urlencode($vide));

            $this->assertStringContainsString('Fatou Traoré', $texte);
            $this->assertStringContainsString('Yao Kouassi', $texte);
        }
    }

    public function testUneRechercheSansResultatRendUnTableauVideEtPasUneErreur(): void
    {
        $texte = $this->texte('/admin/utilisateurs?q=zzzzzz');

        $this->assertStringNotContainsString('Fatou', $texte);
    }

    /**
     * La recherche s'ajoute aux filtres existants, elle ne les remplace pas.
     * Chercher dans les pertes d'un mois donné doit rester dans ce mois — c'est
     * le défaut le plus facile à ne pas voir en développement, où l'on teste
     * sans filtre.
     */
    public function testLaRechercheSeCombineAuFiltreDeMois(): void
    {
        $moisPasse = (new \DateTimeImmutable('first day of last month'))->format('Y-m');

        $texte = $this->texte('/admin/pertes?mois='.$moisPasse.'&q=farine');

        $this->assertStringNotContainsString(
            'Farine',
            $texte,
            'La perte du mois en cours ne doit pas ressortir sur le mois passé.',
        );
    }

    /**
     * Le formulaire reconduit les autres paramètres de l'URL : sans cela, chercher
     * depuis un mois filtré ramènerait au mois en cours à la soumission.
     */
    public function testLeFormulaireConserveLesAutresFiltres(): void
    {
        $crawler = $this->client->request('GET', '/admin/pertes?mois=2026-05');
        $this->assertResponseIsSuccessful();

        $champ = $crawler->filter('form[role="search"] input[name="mois"]');

        $this->assertCount(1, $champ, 'Le mois doit survivre à la recherche.');
        $this->assertSame('2026-05', $champ->attr('value'));
    }

    /**
     * `page` est au contraire abandonné : une nouvelle recherche repart de la
     * première page. Rester en page 4 sur un résultat qui en compte une donnerait
     * un tableau vide, et l'utilisateur conclurait qu'il n'y a rien.
     */
    public function testLaRechercheRepartDeLaPremierePage(): void
    {
        $crawler = $this->client->request('GET', '/admin/utilisateurs?page=3');
        $this->assertResponseIsSuccessful();

        $this->assertCount(
            0,
            $crawler->filter('form[role="search"] input[name="page"]'),
            'Le numéro de page ne doit pas être reconduit par la recherche.',
        );
    }

    /**
     * Toutes les listes offrent la recherche : c'est ce qui rend le back-office
     * homogène, et ce qu'un nouvel écran devra reprendre.
     */
    #[DataProvider('listes')]
    public function testChaqueListePorteLaBarreDeRecherche(string $url): void
    {
        $crawler = $this->client->request('GET', $url);
        $this->assertResponseIsSuccessful();

        $this->assertGreaterThan(
            0,
            $crawler->filter('input[type="search"][name="q"]')->count(),
            'Aucune recherche sur '.$url,
        );
    }

    public static function listes(): array
    {
        return [
            ['/admin/articles'],
            ['/admin/familles'],
            ['/admin/fournisseurs'],
            ['/admin/stock'],
            ['/admin/ventes'],
            ['/admin/production'],
            ['/admin/clotures'],
            ['/admin/utilisateurs'],
            ['/admin/pertes'],
        ];
    }
}
