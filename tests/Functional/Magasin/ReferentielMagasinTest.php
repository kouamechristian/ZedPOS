<?php

namespace App\Tests\Functional\Magasin;

use App\Entity\Fournisseur;
use App\Entity\JournalAudit;
use App\Entity\Magasin\MagasinEmplacement;
use App\Entity\Magasin\MagasinProduit;
use App\Entity\Utilisateur;
use App\Enum\ActionAudit;
use App\Enum\Magasin\CategorieProduitMagasin;
use App\Enum\Magasin\TypeMouvementMagasin;
use App\Service\Magasin\DemandeMouvementMagasin;
use App\Service\Magasin\MagasinStockService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Écrans du référentiel du magasin : produits, emplacements, stock actuel — et
 * qui y entre.
 */
class ReferentielMagasinTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Utilisateur $gerante;
    private Utilisateur $dirigeante;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $connexion = $this->em->getConnection();
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['magasin_ligne_inventaire', 'magasin_inventaire', 'magasin_ligne_reception', 'magasin_reception', 'magasin_mouvement', 'magasin_stock', 'magasin_produit', 'fournisseur', 'journal_audit', 'utilisateur'] as $table) {
            $connexion->executeStatement('DELETE FROM '.$table);
        }
        $connexion->executeStatement("DELETE FROM magasin_emplacement WHERE code <> 'RESERVE'");
        $connexion->executeStatement("UPDATE magasin_emplacement SET actif = 1, libelle = 'Réserve principale' WHERE code = 'RESERVE'");
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $this->gerante = (new Utilisateur('mariam@test.ci', 'Mariam'))->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        $this->dirigeante = (new Utilisateur('aya@test.ci', 'Aya'))->setRoles(['ROLE_DIRIGEANTE'])->setMotDePasse('x');
        $this->em->persist($this->gerante);
        $this->em->persist($this->dirigeante);
        $this->em->persist(new Fournisseur('Grands Moulins'));
        $this->em->flush();
    }

    private function produit(string $nom): ?MagasinProduit
    {
        $this->em->clear();

        return $this->em->getRepository(MagasinProduit::class)->findOneBy(['nom' => $nom]);
    }

    /** @param array<string, string> $champs */
    private function creerProduit(array $champs): void
    {
        $crawler = $this->client->request('GET', '/magasin/produits/nouveau');
        $this->client->submit($crawler->selectButton('Enregistrer')->form(array_combine(
            array_map(static fn (string $c): string => 'produit['.$c.']', array_keys($champs)),
            array_values($champs),
        )));
    }

    // ---------------------------------------------------------------- Produits

    public function testLaGeranteCreeUnProduitAvecSonUniteDAchat(): void
    {
        $this->client->loginUser($this->gerante);
        $this->creerProduit(['nom' => 'Farine de blé', 'categorie' => 'MATIERE', 'uniteStock' => 'kg', 'uniteAchat' => 'sac', 'contenanceAchat' => '50', 'seuilAlerte' => '100']);
        $this->assertResponseRedirects('/magasin/produits');

        $farine = $this->produit('Farine de blé');
        $this->assertSame('sac', $farine->getUniteAchat());
        $this->assertSame(50000, $farine->getContenanceAchat());
        $this->assertSame(100000, $farine->getSeuilAlerte());
        $this->assertSame(0, $farine->getCoutMoyen(), 'Le coût moyen ne se saisit pas.');

        $entree = $this->em->getRepository(JournalAudit::class)->findOneBy(['action' => ActionAudit::MAGASIN_PRODUIT_ENREGISTRE->value]);
        $this->assertSame(50000, $entree->getApres()['contenanceAchat']);

        $crawler = $this->client->request('GET', '/magasin/produits');
        $this->assertStringContainsString('sac de 50 kg', $crawler->filter('table')->text());
        $this->assertStringContainsString('2 sacs (100 kg)', $crawler->filter('table')->text(), 'Le seuil en sacs et en kilos.');
    }

    /** @return iterable<string, array{0: array<string, string>, 1: string}> */
    public static function produitsInvalides(): iterable
    {
        yield 'unité sans contenance' => [['uniteAchat' => 'sac', 'contenanceAchat' => ''], 'Indiquez ce que contient'];
        yield 'contenance sans unité' => [['uniteAchat' => '', 'contenanceAchat' => '50'], 'Nommez l\'unité'];
        yield 'contenance illisible' => [['uniteAchat' => 'sac', 'contenanceAchat' => 'cinquante'], 'Quantité illisible'];
        yield 'nom vide' => [['nom' => ''], 'Le nom est obligatoire'];
    }

    /** @param array<string, string> $surcharge */
    #[\PHPUnit\Framework\Attributes\DataProvider('produitsInvalides')]
    public function testUnProduitInvalideEstRefuseEn422(array $surcharge, string $message): void
    {
        $this->client->loginUser($this->gerante);
        $this->creerProduit($surcharge + ['nom' => 'Sucre', 'categorie' => 'MATIERE', 'uniteStock' => 'kg']);

        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString($message, $this->client->getCrawler()->text());
        $this->assertNull($this->produit('Sucre'));
    }

    public function testDeuxProduitsNePortentPasLeMemeNom(): void
    {
        $this->client->loginUser($this->gerante);
        $this->creerProduit(['nom' => 'Sucre', 'categorie' => 'MATIERE', 'uniteStock' => 'kg']);
        $this->creerProduit(['nom' => 'SUCRE', 'categorie' => 'MATIERE', 'uniteStock' => 'kg']);

        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('Un produit porte déjà ce nom', (string) $this->client->getResponse()->getContent());
    }

    public function testLeProduitSeModifieEtSeDesactive(): void
    {
        $sel = new MagasinProduit('Sel', CategorieProduitMagasin::MATIERE, 'kg');
        $this->em->persist($sel);
        $this->em->flush();

        $this->client->loginUser($this->gerante);
        $crawler = $this->client->request('GET', '/magasin/produits/'.$sel->getId().'/modifier');
        $form = $crawler->selectButton('Enregistrer')->form(['produit[uniteAchat]' => 'carton', 'produit[contenanceAchat]' => '12']);
        $form['produit[actif]']->untick();
        $this->client->submit($form);
        $this->assertResponseRedirects('/magasin/produits');

        $sel = $this->produit('Sel');
        $this->assertFalse($sel->isActif());
        $this->assertSame(12000, $sel->getContenanceAchat());
    }

    public function testLeFiltreParCategorieEtLaRecherche(): void
    {
        $this->em->persist(new MagasinProduit('Farine de blé', CategorieProduitMagasin::MATIERE, 'kg'));
        $this->em->persist(new MagasinProduit('Sachet kraft', CategorieProduitMagasin::EMBALLAGE, 'pièce'));
        $this->em->flush();

        $this->client->loginUser($this->gerante);
        $texte = $this->client->request('GET', '/magasin/produits?categorie=EMBALLAGE')->filter('tbody')->text();
        $this->assertStringContainsString('Sachet kraft', $texte);
        $this->assertStringNotContainsString('Farine', $texte);

        $texte = $this->client->request('GET', '/magasin/produits?q=farine')->filter('tbody')->text();
        $this->assertStringContainsString('Farine de blé', $texte);
        $this->assertStringNotContainsString('Sachet', $texte);
    }

    // ------------------------------------------------------------- Emplacements

    public function testUnEmplacementSeCreeAvecUnCodeUniqueEtDefinitif(): void
    {
        $this->client->loginUser($this->gerante);
        $crawler = $this->client->request('GET', '/magasin/emplacements/nouveau');
        $this->client->submit($crawler->selectButton('Enregistrer')->form(['emplacement[code]' => 'froid', 'emplacement[libelle]' => 'Chambre froide']));
        $this->assertResponseRedirects('/magasin/emplacements');

        $froid = $this->em->getRepository(MagasinEmplacement::class)->findOneBy(['code' => 'FROID']);
        $this->assertNotNull($froid, 'Le code est mis en majuscules.');

        $crawler = $this->client->request('GET', '/magasin/emplacements/nouveau');
        $this->client->submit($crawler->selectButton('Enregistrer')->form(['emplacement[code]' => 'FROID', 'emplacement[libelle]' => 'Autre']));
        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('Ce code est déjà pris', (string) $this->client->getResponse()->getContent());

        $crawler = $this->client->request('GET', '/magasin/emplacements/'.$froid->getId().'/modifier');
        $this->assertCount(0, $crawler->filter('input[name="emplacement[code]"]'), 'Le code ne se modifie pas.');
    }

    public function testLaReservePrincipaleNeSeDesactivePas(): void
    {
        $reserve = static::getContainer()->get(MagasinStockService::class)->reserveParDefaut();

        $this->client->loginUser($this->gerante);
        $crawler = $this->client->request('GET', '/magasin/emplacements/'.$reserve->getId().'/modifier');
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['emplacement[actif]']->untick();
        $this->client->submit($form);

        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('ne se désactive pas', (string) $this->client->getResponse()->getContent());
    }

    // ------------------------------------------------------------------- Stock

    /** Le stock en sacs et en kilos, le détail par zone, le seuil mis en évidence. */
    public function testLeStockSAfficheParProduitEtParEmplacement(): void
    {
        $farine = (new MagasinProduit('Farine de blé', CategorieProduitMagasin::MATIERE, 'kg'))->setUniteAchat('sac')->setContenanceAchat(50000)->setSeuilAlerte(1500000);
        $sucre = (new MagasinProduit('Sucre', CategorieProduitMagasin::MATIERE, 'kg'))->setSeuilAlerte(10000);
        $froid = new MagasinEmplacement('FROID', 'Chambre froide');
        foreach ([$farine, $sucre, $froid] as $entite) {
            $this->em->persist($entite);
        }
        $this->em->flush();

        $stock = static::getContainer()->get(MagasinStockService::class);
        $stock->appliquer([
            new DemandeMouvementMagasin($farine, $stock->reserveParDefaut(), 900000, TypeMouvementMagasin::ENTREE_RECEPTION, 'reception', 1),
            new DemandeMouvementMagasin($farine, $froid, 100000, TypeMouvementMagasin::ENTREE_RECEPTION, 'reception', 1),
            new DemandeMouvementMagasin($sucre, $stock->reserveParDefaut(), 25000, TypeMouvementMagasin::ENTREE_RECEPTION, 'reception', 1),
        ], $this->gerante);

        $this->client->loginUser($this->gerante);
        $crawler = $this->client->request('GET', '/magasin/stock');
        $this->assertResponseIsSuccessful();

        $ligne = $crawler->filter('#stock-magasin tbody tr')->reduce(static fn ($tr): bool => str_contains($tr->text(), 'Farine'));
        $texte = preg_replace('/\s+/u', ' ', $ligne->text());
        $this->assertStringContainsString('20 sacs (1 000 kg)', $texte);
        $this->assertStringContainsString('Réserve principale : 18 sacs (900 kg)', $texte);
        $this->assertStringContainsString('Chambre froide : 2 sacs (100 kg)', $texte);
        $this->assertNotNull($ligne->attr('data-sous-seuil'), '1 000 kg sous un seuil de 1 500 kg.');

        $sucre = $crawler->filter('#stock-magasin tbody tr')->reduce(static fn ($tr): bool => str_contains($tr->text(), 'Sucre'));
        $this->assertNull($sucre->attr('data-sous-seuil'), '25 kg au-dessus d\'un seuil de 10 kg.');
        $this->assertStringNotContainsString('Valeur', $crawler->filter('#stock-magasin thead')->text(), 'La gérante ne voit pas la valeur.');

        $this->client->loginUser($this->dirigeante);
        $crawler = $this->client->request('GET', '/magasin/stock');
        $this->assertStringContainsString('Valeur', $crawler->filter('#stock-magasin thead')->text());
    }

    // ----------------------------------------------------------------- Accès

    public function testLeCaissierEtLAtelierNEntrentPasAuMagasin(): void
    {
        $caissier = (new Utilisateur('fatou@test.ci', 'Fatou'))->setRoles(['ROLE_CAISSIER'])->setCodePin('x');
        $boulanger = (new Utilisateur('yao@test.ci', 'Yao'))->setRoles(['ROLE_ATELIER'])->setCodePin('x');
        $comptable = (new Utilisateur('cabinet@test.ci', 'Cabinet'))->setRoles(['ROLE_COMPTABLE'])->setMotDePasse('x');
        foreach ([$caissier, $boulanger, $comptable] as $qui) {
            $this->em->persist($qui);
        }
        $this->em->flush();

        foreach ([$caissier, $boulanger, $comptable] as $qui) {
            $this->client->loginUser($qui);
            foreach (['/magasin/stock', '/magasin/produits', '/magasin/emplacements'] as $url) {
                $this->client->request('GET', $url);
                $this->assertResponseStatusCodeSame(403, $qui->getNom().' sur '.$url);
            }
        }

        $this->client->loginUser($this->gerante);
        foreach (['/magasin/stock', '/magasin/produits', '/magasin/produits/nouveau', '/magasin/emplacements', '/magasin/emplacements/nouveau'] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful('Gérante sur '.$url);
        }
    }
}
