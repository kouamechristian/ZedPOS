<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\FamilleProduit;
use App\Entity\FicheProduction;
use App\Entity\JournalAudit;
use App\Entity\LigneVente;
use App\Entity\Reglement;
use App\Entity\SaisieProduction;
use App\Entity\SessionCaisse;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Enum\ActionAudit;
use App\Enum\Atelier;
use App\Enum\ModeReglement;
use App\Enum\ModeVente;
use App\Enum\TypeSaisieProduction;
use App\Service\ProductionService;
use App\Service\SessionCaisseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Module Production et vitrine : la caisse ouvre sa fiche de production, restes
 * de la caisse précédente repris en vitrine,
 * le boulanger y ajoute ses fournées, la vendeuse ou la gérante ce qu'elle met en
 * vitrine, et la caisse y décompte ses ventes.
 */
class ProductionVitrineTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Utilisateur $gerante;
    private Utilisateur $caissiere;
    private Utilisateur $boulanger;
    private Utilisateur $vendeuse;
    private Article $croissant;
    private Article $baguette;
    private Article $eclair;
    private Article $coca;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $connexion = $this->em->getConnection();
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['ligne_saisie_production', 'saisie_production', 'ligne_fiche_production', 'fiche_production', 'ligne_vente', 'reglement', 'vente', 'mouvement_caisse', 'session_caisse', 'mouvement_stock', 'stock_courant', 'perte', 'ligne_fiche_technique', 'fiche_technique', 'article', 'famille_produit', 'journal_audit', 'notification', 'parametre', 'utilisateur'] as $table) {
            $connexion->executeStatement('DELETE FROM '.$table);
        }
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        static::getContainer()->get('test.cache.rate_limiter')->clear();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->gerante = (new Utilisateur('mariam@test.ci', 'Mariam'))->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        $this->caissiere = (new Utilisateur('fatou@test.ci', 'Fatou'))->setRoles(['ROLE_CAISSIER'])->setCodePin('x');
        $this->boulanger = (new Utilisateur('yao@test.ci', 'Yao'))->setRoles(['ROLE_ATELIER']);
        $this->boulanger->setCodePin($hasher->hashPassword($this->boulanger, '4321'));
        $this->vendeuse = (new Utilisateur('awa@test.ci', 'Awa'))->setRoles(['ROLE_VITRINE']);
        $this->vendeuse->setCodePin($hasher->hashPassword($this->vendeuse, '8765'));

        $pain = (new FamilleProduit('Pains'))->setAtelier(Atelier::BOULANGERIE);
        $viennoiserie = (new FamilleProduit('Viennoiseries'))->setAtelier(Atelier::BOULANGERIE);
        $patisserie = (new FamilleProduit('Pâtisseries'))->setAtelier(Atelier::PATISSERIE);
        $boissons = new FamilleProduit('Boissons');

        $this->baguette = (new Article('Baguette', 15000, 'pièce'))->setFamilleProduit($pain);
        $this->croissant = (new Article('Croissant', 30000, 'pièce'))->setFamilleProduit($viennoiserie);
        $this->eclair = (new Article('Éclair', 50000, 'pièce'))->setFamilleProduit($patisserie);
        $this->coca = (new Article('Coca', 50000, 'bouteille'))->setFamilleProduit($boissons);

        foreach ([$this->gerante, $this->caissiere, $this->boulanger, $this->vendeuse, $pain, $viennoiserie, $patisserie, $boissons, $this->baguette, $this->croissant, $this->eclair, $this->coca] as $entite) {
            $this->em->persist($entite);
        }
        $this->em->flush();
    }

    private function ouvrirCaisse(): SessionCaisse
    {
        return static::getContainer()->get(SessionCaisseService::class)->ouvrir($this->caissiere, 0);
    }

    private function cloturer(SessionCaisse $session): void
    {
        static::getContainer()->get(SessionCaisseService::class)->cloturer($session, $this->especesEnCaisse($session));
    }

    /** Espèces théoriques : pas de fond, pas de dépense, toutes les ventes en espèces. */
    private function especesEnCaisse(SessionCaisse $session): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            "SELECT COALESCE(SUM(total_ttc), 0) FROM vente WHERE session_caisse_id = ? AND statut = 'VALIDEE'",
            [$session->getId()],
        );
    }

    private function vendre(SessionCaisse $session, Article $article, int $unites): Vente
    {
        $total = $unites * $article->getPrixVenteTtc();
        $vente = new Vente($session, ModeVente::BOULANGERIE, \sprintf('VT-%05d', ++$this->sequence), $total, 0, $total);
        new LigneVente($vente, $article, $unites * 1000, $article->getPrixVenteTtc());
        new Reglement($vente, ModeReglement::ESPECES, $total);
        $this->em->persist($vente);
        $this->em->flush();

        return $vente;
    }

    /** @param array<int, int> $quantites unités par article */
    private function declarer(TypeSaisieProduction $type, array $quantites, Utilisateur $auteur, ?Atelier $atelier = Atelier::BOULANGERIE): SaisieProduction
    {
        // Relu dans l'EntityManager courant : une requête HTTP redémarre le noyau,
        // et l'utilisateur chargé au setUp() n'y est plus géré.
        $auteur = static::getContainer()->get(EntityManagerInterface::class)->find(Utilisateur::class, $auteur->getId());

        return static::getContainer()->get(ProductionService::class)->declarer(
            $type,
            TypeSaisieProduction::PRODUCTION === $type ? $atelier : null,
            array_map('strval', $quantites),
            $auteur,
        );
    }

    private function saisirPin(string $pin): void
    {
        $crawler = $this->client->request('GET', '/caisse/login');
        $this->client->request('POST', '/caisse/login', [
            'code_pin' => $pin,
            '_csrf_token' => $crawler->filter('input[name="_csrf_token"]')->attr('value'),
        ]);
    }

    // ------------------------------------------------------------------ Accès

    public function testLeBoulangerEtLaVendeuseSeConnectentAuPaveEtArriventALAtelier(): void
    {
        $this->saisirPin('4321');
        $this->assertResponseRedirects('/atelier');

        $this->client->request('GET', '/caisse/logout');
        $this->client->restart();
        static::getContainer()->get('test.cache.rate_limiter')->clear();

        $this->saisirPin('8765');
        $this->assertResponseRedirects('/atelier');
    }

    public function testChacunNOuvreQueSaPorte(): void
    {
        $this->ouvrirCaisse();

        $this->client->loginUser($this->boulanger);
        $this->client->request('GET', '/atelier/production/boulangerie');
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', '/atelier/vitrine');
        $this->assertResponseStatusCodeSame(403, 'Le boulanger ne met pas en vitrine.');
        foreach (['/caisse', '/admin'] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseStatusCodeSame(403, 'Atelier sur '.$url);
        }

        $this->client->loginUser($this->vendeuse);
        $this->client->request('GET', '/atelier/vitrine');
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', '/atelier/production/boulangerie');
        $this->assertResponseStatusCodeSame(403, 'La vendeuse ne déclare pas de fournée.');

        $this->client->loginUser($this->caissiere);
        $this->client->request('GET', '/atelier');
        $this->assertResponseStatusCodeSame(403, 'La caissière n\'a rien à déclarer.');

        // La gérante déclare à leur place quand personne n'a sa tablette.
        $this->client->loginUser($this->gerante);
        foreach (['/atelier', '/atelier/production/boulangerie', '/atelier/vitrine'] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful('Gérante sur '.$url);
        }
    }

    public function testLeBoulangerChangeSonCodePinEtRevientALAtelier(): void
    {
        $this->client->loginUser($this->boulanger);
        $crawler = $this->client->request('GET', '/caisse/code-pin');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('a[href="/atelier"]'), 'Le retour mène à l\'atelier, pas à une caisse qui lui est fermée.');
    }

    /** L'atelier ne lit que des quantités. */
    public function testLAtelierNAfficheAucunPrix(): void
    {
        $this->ouvrirCaisse();
        $this->declarer(TypeSaisieProduction::PRODUCTION, [$this->croissant->getId() => 40], $this->boulanger);

        foreach ([[$this->boulanger, '/atelier'], [$this->boulanger, '/atelier/production/boulangerie'], [$this->vendeuse, '/atelier/vitrine']] as [$qui, $url]) {
            $this->client->loginUser($qui);
            $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful();
            $this->assertStringNotContainsString('FCFA', (string) $this->client->getResponse()->getContent(), $url);
        }
    }

    // ------------------------------------------------------------ Déclarations

    public function testSansCaisseOuverteRienNeSeDeclare(): void
    {
        $this->client->loginUser($this->boulanger);
        $crawler = $this->client->request('GET', '/atelier');
        $this->assertStringContainsString('Aucune caisse n\'est ouverte', $crawler->text());

        $this->client->request('GET', '/atelier/production/boulangerie');
        $this->assertResponseRedirects('/atelier');

        $this->expectException(\DomainException::class);
        $this->declarer(TypeSaisieProduction::PRODUCTION, [$this->croissant->getId() => 40], $this->boulanger);
    }

    public function testUneFourneeSeDeclareAuDoigtEtSeRattacheALaCaisseOuverte(): void
    {
        $session = $this->ouvrirCaisse();
        $this->client->loginUser($this->boulanger);

        $crawler = $this->client->request('GET', '/atelier/production/boulangerie');
        $this->assertCount(0, $crawler->filter(\sprintf('input[name="quantites[%d]"]', $this->eclair->getId())), 'La pâtisserie n\'est pas au fournil du boulanger.');
        $this->assertCount(0, $crawler->filter(\sprintf('input[name="quantites[%d]"]', $this->coca->getId())), 'Une boisson n\'a pas de fournée.');

        $this->client->request('POST', '/atelier/production/boulangerie', [
            '_token' => $crawler->filter('input[name="_token"]')->attr('value'),
            'quantites' => [$this->croissant->getId() => '40', $this->baguette->getId() => '120', $this->eclair->getId() => ''],
        ]);
        $this->assertResponseRedirects('/atelier');

        $saisie = $this->em->getRepository(SaisieProduction::class)->findOneBy([]);
        $this->assertSame($session->getId(), $saisie->getSessionCaisse()->getId());
        $this->assertSame(Atelier::BOULANGERIE, $saisie->getAtelier());
        $this->assertMatchesRegularExpression('/^PRD-\d{8}-001$/', $saisie->getNumero());
        $this->assertSame(160000, $saisie->totalQuantite());
        $this->assertSame(1, $this->em->getRepository(JournalAudit::class)->count(['action' => ActionAudit::PRODUCTION_DECLAREE->value]));
    }

    public function testUneSaisieInvalideReaffichelEcranAvecCeQuiAEteTape(): void
    {
        $this->ouvrirCaisse();
        $this->client->loginUser($this->boulanger);
        $crawler = $this->client->request('GET', '/atelier/production/boulangerie');

        $crawler = $this->client->request('POST', '/atelier/production/boulangerie', [
            '_token' => $crawler->filter('input[name="_token"]')->attr('value'),
            'quantites' => [$this->croissant->getId() => '40', $this->eclair->getId() => '5'],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('ne relève pas de l\'atelier « Boulangerie »', $crawler->text());
        $this->assertSame('40', $crawler->filter(\sprintf('input[name="quantites[%d]"]', $this->croissant->getId()))->attr('value'));
    }

    public function testChacunAnnuleSaPropreDeclarationTantQueLaCaisseEstOuverte(): void
    {
        $this->ouvrirCaisse();
        $saisie = $this->declarer(TypeSaisieProduction::PRODUCTION, [$this->croissant->getId() => 40], $this->boulanger);

        $this->client->loginUser($this->vendeuse);
        $this->client->request('POST', '/atelier/declarations/'.$saisie->getId().'/annuler', ['motif' => 'x']);
        $this->assertResponseStatusCodeSame(403, 'On n\'annule pas la déclaration d\'un autre.');

        $this->client->loginUser($this->boulanger);
        $crawler = $this->client->request('GET', '/atelier');
        $this->client->request('POST', '/atelier/declarations/'.$saisie->getId().'/annuler', [
            '_token' => $crawler->filter('form[action$="/annuler"] input[name="_token"]')->attr('value'),
            'motif' => 'Faute de frappe : 4 et non 40',
        ]);
        $this->assertResponseRedirects('/atelier');

        $this->em->clear();
        $saisie = $this->em->getRepository(SaisieProduction::class)->find($saisie->getId());
        $this->assertTrue($saisie->estAnnulee());
        $this->assertSame('Faute de frappe : 4 et non 40', $saisie->getMotifAnnulation());
        $entree = $this->em->getRepository(JournalAudit::class)->findOneBy(['action' => ActionAudit::PRODUCTION_ANNULEE->value]);
        $this->assertNotNull($entree);
        $this->assertTrue(ActionAudit::PRODUCTION_ANNULEE->estSensible());
    }

    /** @return array<string, array{0: int, 1: int, 2: int, 3: int}> produit, vitrine, vendu, reste en unités, par article */
    private function fiche(SessionCaisse $session): array
    {
        $fiche = [];
        foreach (static::getContainer()->get(ProductionService::class)->lireFiche($session) as $ligne) {
            $fiche[$ligne->article->getNom()] = [intdiv($ligne->produite, 1000), intdiv($ligne->vitrine, 1000), intdiv($ligne->vendue, 1000), intdiv($ligne->reste(), 1000)];
        }

        return $fiche;
    }

    public function testLOuvertureDeLaCaisseCreeLaFicheDeProductionAZero(): void
    {
        $session = $this->ouvrirCaisse();

        $fiche = $this->em->getRepository(FicheProduction::class)->findOneBy(['sessionCaisse' => $session]);
        $this->assertNotNull($fiche, 'La fiche naît avec la caisse.');

        $articles = [];
        foreach ($fiche->getLignes() as $ligne) {
            $articles[$ligne->getArticle()->getNom()] = [$ligne->getQteProduite(), $ligne->getQteVitrine()];
        }
        ksort($articles);
        $this->assertSame(['Baguette' => [0, 0], 'Croissant' => [0, 0], 'Éclair' => [0, 0]], $articles, 'Tous les articles d\'atelier, à zéro ; pas la boisson.');
    }

    public function testLesDeclarationsSAjoutentALaFicheEtLaVenteDecompte(): void
    {
        $session = $this->ouvrirCaisse();

        // La caisse vend avant toute déclaration : rien ne la bloque.
        $this->vendre($session, $this->croissant, 3);
        $this->assertSame([0, 0, 3, -3], $this->fiche($session)['Croissant'], 'Vendu sans vitrine : le reste passe sous zéro.');

        $this->declarer(TypeSaisieProduction::PRODUCTION, [$this->croissant->getId() => 20], $this->boulanger);
        $this->declarer(TypeSaisieProduction::PRODUCTION, [$this->croissant->getId() => 15], $this->boulanger);
        $this->declarer(TypeSaisieProduction::VITRINE, [$this->croissant->getId() => 30], $this->gerante);
        $this->vendre($session, $this->croissant, 7);

        $this->assertSame([35, 30, 10, 20], $this->fiche($session)['Croissant'], '20 + 15 produits, 30 en vitrine, 10 vendus : 20 restent.');
        $this->assertSame([0, 0, 0, 0], $this->fiche($session)['Baguette']);
        $this->assertArrayNotHasKey('Coca', $this->fiche($session));

        $this->client->loginUser($this->boulanger);
        $crawler = $this->client->request('GET', '/atelier');
        $this->assertResponseIsSuccessful();
        $ligne = $crawler->filter('#titre-fiche')->closest('section')->filter('tr')->reduce(static fn ($tr): bool => str_contains($tr->text(), 'Croissant'));
        $this->assertSame(['Croissant', '35', '30', '10', '20'], $ligne->filter('td')->each(static fn ($td): string => trim($td->text())));
    }

    /** Des invendus d'hier remis en vitrine n'ont pas été produits aujourd'hui. */
    public function testLaVitrineNEstPasBorneeParLaProduction(): void
    {
        $session = $this->ouvrirCaisse();
        $this->declarer(TypeSaisieProduction::PRODUCTION, [$this->croissant->getId() => 10], $this->boulanger);

        $this->client->loginUser($this->vendeuse);
        $crawler = $this->client->request('GET', '/atelier/vitrine');
        $tuile = $crawler->filter(\sprintf('input[name="quantites[%d]"]', $this->croissant->getId()))->ancestors()->first();
        $this->assertSame('10000', $tuile->attr('data-fournil'), '10 croissants au fournil.');
        $this->assertCount(1, $crawler->filter(\sprintf('input[name="quantites[%d]"]', $this->baguette->getId())), 'Tous les articles d\'atelier sont proposés.');

        $this->client->request('POST', '/atelier/vitrine', [
            '_token' => $crawler->filter('input[name="_token"]')->attr('value'),
            'quantites' => [$this->croissant->getId() => '14'],
        ]);
        $this->assertResponseRedirects('/atelier');
        $this->assertSame([10, 14, 0, 14], $this->fiche($session)['Croissant']);
    }

    public function testAnnulerUneDeclarationLaRetireDeLaFicheMemeApresLeZ(): void
    {
        $session = $this->ouvrirCaisse();
        $this->declarer(TypeSaisieProduction::PRODUCTION, [$this->croissant->getId() => 40], $this->boulanger);
        $erreur = $this->declarer(TypeSaisieProduction::PRODUCTION, [$this->croissant->getId() => 400], $this->boulanger);
        $this->cloturer($session);

        // Après le Z, la gérante corrige encore la fiche.
        static::getContainer()->get(ProductionService::class)->annuler($erreur, 'Un zéro de trop', $this->gerante);

        $this->assertSame([40, 0, 0, 0], $this->fiche($session)['Croissant']);
    }

    /**
     * Après le Z, la caisse suivante ouvre sa fiche en reprenant ce qui restait
     * en vitrine : produit à zéro, reste de la veille en vitrine.
     */
    public function testLaCaisseSuivanteReprendLesRestesEnVitrine(): void
    {
        $premiere = $this->ouvrirCaisse();
        $this->declarer(TypeSaisieProduction::PRODUCTION, [$this->croissant->getId() => 45, $this->baguette->getId() => 10], $this->boulanger);
        $this->declarer(TypeSaisieProduction::VITRINE, [$this->croissant->getId() => 40, $this->baguette->getId() => 10], $this->vendeuse);
        $this->vendre($premiere, $this->croissant, 34);
        $this->vendre($premiere, $this->baguette, 10);
        $this->vendre($premiere, $this->eclair, 2);
        $this->cloturer($premiere);

        $seconde = $this->ouvrirCaisse();
        $this->assertSame([0, 6, 0, 6], $this->fiche($seconde)['Croissant'], '40 en vitrine, 34 vendus : les 6 restants sont repris en vitrine.');
        $this->assertSame([0, 0, 0, 0], $this->fiche($seconde)['Baguette'], 'Tout vendu : rien à reprendre.');
        $this->assertSame([0, 0, 0, 0], $this->fiche($seconde)['Éclair'], 'Un reste négatif ne se reprend pas.');

        // La journée continue par-dessus la reprise.
        $this->declarer(TypeSaisieProduction::PRODUCTION, [$this->croissant->getId() => 20], $this->boulanger);
        $this->declarer(TypeSaisieProduction::VITRINE, [$this->croissant->getId() => 20], $this->vendeuse);
        $this->vendre($seconde, $this->croissant, 23);
        $this->assertSame([20, 26, 23, 3], $this->fiche($seconde)['Croissant']);

        $ligne = null;
        foreach (static::getContainer()->get(ProductionService::class)->lireFiche($seconde) as $l) {
            $ligne = 'Croissant' === $l->article->getNom() ? $l : $ligne;
        }
        $this->assertSame(6000, $ligne->reprise);
        $this->assertSame(0, $ligne->auFournil(), 'La reprise n\'a pas été produite : 20 produits, 20 mis en vitrine.');

        $this->assertSame([45, 40, 34, 6], $this->fiche($premiere)['Croissant'], 'La première fiche est restée telle quelle.');

        // Et d'une caisse à l'autre : la troisième reprend les 3 restants.
        $this->cloturer($seconde);
        $this->assertSame([0, 3, 0, 3], $this->fiche($this->ouvrirCaisse())['Croissant']);

        // La reprise se lit sur l'atelier (caisse ouverte) et au pilotage.
        $dirigeante = $this->dirigeante();
        $this->client->loginUser($this->boulanger);
        $this->assertStringContainsString('dont 3 repris', $this->client->request('GET', '/atelier')->text());
        $this->client->loginUser($dirigeante);
        $this->assertStringContainsString('dont 6 repris', $this->client->request('GET', '/pilotage/production?session='.$seconde->getId())->filter('#rapport-production')->text());
    }

    /** La caisse suivante a repris les restes : la fiche précédente ne bouge plus. */
    public function testUneDeclarationNeSAnnulePlusUneFoisLaCaisseSuivanteOuverte(): void
    {
        $premiere = $this->ouvrirCaisse();
        $saisie = $this->declarer(TypeSaisieProduction::VITRINE, [$this->croissant->getId() => 10], $this->vendeuse);
        $this->cloturer($premiere);
        $this->ouvrirCaisse();

        $this->client->loginUser($this->gerante);
        $this->client->request('POST', '/atelier/declarations/'.$saisie->getId().'/annuler', ['motif' => 'x']);
        $this->assertResponseStatusCodeSame(403);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('a repris les restes');
        static::getContainer()->get(ProductionService::class)->annuler($saisie, 'erreur', $this->gerante);
    }

    public function testUneVenteAnnuleeNeDecompteRien(): void
    {
        $session = $this->ouvrirCaisse();
        $this->declarer(TypeSaisieProduction::VITRINE, [$this->croissant->getId() => 10], $this->vendeuse);
        $this->vendre($session, $this->croissant, 4);
        $this->vendre($session, $this->croissant, 5)->annuler('erreur');
        $this->vendre($session, $this->eclair, 2);
        $this->em->flush();

        $this->assertSame([0, 10, 4, 6], $this->fiche($session)['Croissant'], 'La vente annulée n\'a rien vendu.');
        $this->assertSame([0, 0, 2, -2], $this->fiche($session)['Éclair'], 'Vendu sans déclaration : il apparaît quand même.');
    }

    /** Le point de vitrine a été retiré : la fiche de la caisse suffit. */
    public function testLePointDeVitrineNExistePlus(): void
    {
        $this->client->loginUser($this->gerante);

        $this->client->request('GET', '/admin/vitrine');
        $this->assertResponseStatusCodeSame(404);

        $crawler = $this->client->request('GET', '/admin');
        $this->assertCount(0, $crawler->filter('a[href^="/admin/vitrine"]'));
        $this->assertCount(1, $crawler->filter('aside a[href="/atelier"]'), 'La fiche de production reste dans la barre latérale.');
    }

    // ---------------------------------------------------------------- Pilotage

    private function dirigeante(): Utilisateur
    {
        $dirigeante = (new Utilisateur('aya@test.ci', 'Aya'))->setRoles(['ROLE_DIRIGEANTE'])->setMotDePasse('x');
        $this->em->persist($dirigeante);
        $this->em->flush();

        return $dirigeante;
    }

    public function testLeRapportDeProductionDuPilotageSuitLaCaisseOuverte(): void
    {
        $dirigeante = $this->dirigeante();

        // Une caisse de la veille, clôturée : le rapport ne la montre pas d'office.
        $hier = $this->ouvrirCaisse();
        $this->declarer(TypeSaisieProduction::PRODUCTION, [$this->croissant->getId() => 99], $this->boulanger);
        $this->cloturer($hier);

        $session = $this->ouvrirCaisse();
        $this->declarer(TypeSaisieProduction::PRODUCTION, [$this->croissant->getId() => 20], $this->boulanger);
        $this->declarer(TypeSaisieProduction::VITRINE, [$this->croissant->getId() => 15], $this->vendeuse);
        $this->vendre($session, $this->croissant, 10);
        $this->vendre($session, $this->coca, 2);

        $this->client->loginUser($dirigeante);
        $crawler = $this->client->request('GET', '/pilotage/production');
        $this->assertResponseIsSuccessful();

        $table = $crawler->filter('#rapport-production');
        $ligne = $table->filter('tbody tr')->reduce(static fn ($tr): bool => str_contains($tr->text(), 'Croissant'));
        $this->assertSame(
            ['Croissant', '20', '15', '10', '5', '3 000 FCFA'],
            $ligne->filter('td')->each(static fn ($td): string => trim(preg_replace('/\s+/u', ' ', $td->text()))),
            '20 produits, 15 en vitrine, 10 vendus à 300 FCFA : 5 restent, 3 000 FCFA rapportés.',
        );
        $this->assertStringNotContainsString('Coca', $table->text(), 'Une boisson n\'est pas un article d\'atelier.');
        $this->assertStringContainsString('3 000 FCFA', $table->filter('tfoot')->text());

        $lien = $crawler->filter(\sprintf('a[href="/pilotage/production.pdf?session=%d"]', $session->getId()));
        $this->assertCount(1, $lien, 'Le PDF porte la caisse affichée.');
        $this->assertSame('false', $lien->attr('data-turbo'));

        // La caisse d'hier se relit à la demande.
        $crawler = $this->client->request('GET', '/pilotage/production?session='.$hier->getId());
        $croissant = $crawler->filter('#rapport-production tbody tr')->reduce(static fn ($tr): bool => str_contains($tr->text(), 'Croissant'));
        $this->assertSame('99', trim($croissant->filter('td')->eq(1)->text()));
    }

    public function testLeRapportDeProductionSeTelechargeEnPdf(): void
    {
        $dirigeante = $this->dirigeante();
        $session = $this->ouvrirCaisse();
        $this->declarer(TypeSaisieProduction::PRODUCTION, [$this->croissant->getId() => 20], $this->boulanger);
        $this->vendre($session, $this->croissant, 4);

        $this->client->loginUser($dirigeante);
        $this->client->request('GET', '/pilotage/production.pdf?session='.$session->getId());

        $this->assertResponseIsSuccessful();
        $reponse = $this->client->getResponse();
        $this->assertSame('application/pdf', $reponse->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', (string) $reponse->getContent());
        $this->assertStringContainsString('attachment', (string) $reponse->headers->get('Content-Disposition'));
        $this->assertStringContainsString('production_'.$session->getOuvertureAt()->format('Y-m-d').'_Fatou.pdf', (string) $reponse->headers->get('Content-Disposition'));
    }

    /** Le même rapport sur /atelier : montants pour la gérante, quantités pour l'atelier. */
    public function testLeRapportDeProductionFigureALAtelier(): void
    {
        $session = $this->ouvrirCaisse();
        $this->declarer(TypeSaisieProduction::PRODUCTION, [$this->croissant->getId() => 20], $this->boulanger);
        $this->declarer(TypeSaisieProduction::VITRINE, [$this->croissant->getId() => 15], $this->vendeuse);
        $this->vendre($session, $this->croissant, 10);

        $this->client->loginUser($this->gerante);
        $crawler = $this->client->request('GET', '/atelier');
        $table = $crawler->filter('#fiche-production');
        $this->assertStringContainsString('Montant rapporté', $table->filter('thead')->text());
        $ligne = $table->filter('tbody tr')->reduce(static fn ($tr): bool => str_contains($tr->text(), 'Croissant'));
        $this->assertSame(
            ['Croissant', '20', '15', '10', '5', '3 000 FCFA'],
            $ligne->filter('td')->each(static fn ($td): string => trim(preg_replace('/\s+/u', ' ', $td->text()))),
        );
        $this->assertStringContainsString('3 000 FCFA', $table->filter('tfoot')->text());
        $lien = $crawler->filter('a[href="/atelier/rapport.pdf"]');
        $this->assertCount(1, $lien);
        $this->assertSame('false', $lien->attr('data-turbo'));

        // Le boulanger a le même tableau, sans la colonne d'argent.
        $this->client->loginUser($this->boulanger);
        $crawler = $this->client->request('GET', '/atelier');
        $this->assertStringNotContainsString('Montant', $crawler->filter('#fiche-production')->text());
        $this->assertCount(1, $crawler->filter('a[href="/atelier/rapport.pdf"]'));

        foreach ([$this->gerante, $this->boulanger, $this->vendeuse] as $qui) {
            $this->client->loginUser($qui);
            $this->client->request('GET', '/atelier/rapport.pdf');
            $this->assertResponseIsSuccessful($qui->getNom());
            $this->assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
            $this->assertStringContainsString('production_'.$session->getOuvertureAt()->format('Y-m-d').'_Fatou.pdf', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        }

        $this->client->loginUser($this->caissiere);
        $this->client->request('GET', '/atelier/rapport.pdf');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testSansCaisseOuverteLeRapportDeLAtelierRenvoieALAccueil(): void
    {
        $this->client->loginUser($this->boulanger);
        $this->client->request('GET', '/atelier/rapport.pdf');

        $this->assertResponseRedirects('/atelier');
    }

    public function testLeRapportDeProductionEstReserveALaDirigeante(): void
    {
        $this->ouvrirCaisse();

        foreach ([$this->gerante, $this->boulanger, $this->caissiere] as $qui) {
            $this->client->loginUser($qui);
            foreach (['/pilotage/production', '/pilotage/production.pdf'] as $url) {
                $this->client->request('GET', $url);
                $this->assertResponseStatusCodeSame(403, $qui->getNom().' sur '.$url);
            }
        }
    }

    // ---------------------------------------------------- Back-office de la gérante

    /** « Catalogue et stock » et « Administration » : fermés à la gérante, ouverts à la dirigeante. */
    public function testLaGeranteNEntrePlusDansLeCatalogueNiLAdministration(): void
    {
        $dirigeante = $this->dirigeante();
        $pages = ['/admin/articles', '/admin/articles/nouveau', '/admin/familles', '/admin/stock', '/admin/fournisseurs',
            '/admin/inventaires', '/admin/production', '/admin/pertes', '/admin/pertes/saisie',
            '/admin/utilisateurs', '/admin/utilisateurs/nouveau', '/admin/parametres', '/comptabilite'];

        $this->client->loginUser($this->gerante);
        foreach ($pages as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseStatusCodeSame(403, 'Gérante sur '.$url);
        }
        // Le reste du back-office lui reste ouvert.
        foreach (['/admin', '/admin/ventes', '/admin/clotures', '/admin/dotations', '/atelier'] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful('Gérante sur '.$url);
        }

        $this->client->loginUser($dirigeante);
        foreach ($pages as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful('Dirigeante sur '.$url);
        }
    }

    /** Le tableau de bord de la gérante ne lit que la caisse ouverte : rien sur trente jours. */
    public function testLeTableauDeBordDeLaGeranteNeMontreQueLaCaisseOuverte(): void
    {
        $dirigeante = $this->dirigeante();

        $hier = $this->ouvrirCaisse();
        $this->vendre($hier, $this->eclair, 4);
        // Une vente datée du mois dernier : dans le chiffre global, pas dans le mois.
        $ancienne = $this->vendre($hier, $this->baguette, 2);
        $this->em->getConnection()->executeStatement('UPDATE vente SET created_at = ? WHERE id = ?', [
            (new \DateTimeImmutable('first day of last month'))->format('Y-m-d 10:00:00'), $ancienne->getId(),
        ]);
        $this->cloturer($hier);

        // Sans caisse ouverte, la dernière clôturée ne lui est pas montrée.
        $this->client->loginUser($this->gerante);
        $crawler = $this->client->request('GET', '/admin');
        $this->assertStringContainsString('Aucune caisse ouverte', $crawler->text());
        $this->assertStringNotContainsString('Éclair', $crawler->text());

        $session = $this->ouvrirCaisse();
        $this->vendre($session, $this->croissant, 10);

        $crawler = $this->client->request('GET', '/admin');
        $texte = preg_replace('/\s+/u', ' ', $crawler->text());
        $this->assertStringNotContainsString('30 jours', $texte);
        $this->assertStringNotContainsString('30 derniers jours', $texte);
        $this->assertStringContainsString('Chiffre de la caisse ouverte', $texte);
        $this->assertStringContainsString('Tickets de la caisse', $texte);
        $this->assertStringContainsString('Croissant', $texte, 'Meilleures ventes de la caisse ouverte.');
        $this->assertStringNotContainsString('Éclair', $texte, 'Les ventes de la caisse d\'hier ne comptent pas.');
        foreach (['/admin/perte', '/admin/inventaires', '/admin/production', '/admin/articles', '/admin/stock'] as $prefixe) {
            $this->assertCount(0, $crawler->filter('main a[href^="'.$prefixe.'"]'), 'Pas de lien vers un écran fermé : '.$prefixe);
        }

        // La dirigeante lit le mois en cours et le chiffre global.
        $this->client->loginUser($dirigeante);
        $crawler = $this->client->request('GET', '/admin');
        $texte = preg_replace('/\s+/u', ' ', $crawler->text());
        $this->assertStringNotContainsString('30 jours', $texte);
        $this->assertStringContainsString('Éclair', $texte, 'Meilleures ventes du mois, toutes caisses.');

        $kpi = static fn (string $libelle): string => trim(preg_replace('/\s+/u', ' ', $crawler->filter('.kpi')->reduce(
            static fn ($k): bool => str_contains($k->filter('.kpi-libelle')->text(), $libelle),
        )->filter('.kpi-valeur')->text()));
        // Ce mois-ci : 4 éclairs à 500 FCFA + 10 croissants à 300 FCFA. Le global y
        // ajoute les 2 baguettes à 150 FCFA du mois dernier.
        $this->assertSame('5 000 FCFA', $kpi('Chiffre du mois'));
        $this->assertSame('5 300 FCFA', $kpi("Chiffre d'affaires global"));
    }

    // ---------------------------------------------------------------- Ateliers

    public function testLeFastFoodEtLesAutresAteliersSeDeclarentAussi(): void
    {
        $sandwichs = (new FamilleProduit('Sandwichs'))->setAtelier(Atelier::FAST_FOOD);
        $shawarma = (new Article('Shawarma', 150000, 'pièce'))->setFamilleProduit($sandwichs);
        $jus = (new FamilleProduit('Jus pressés'))->setAtelier(Atelier::AUTRE);
        $bissap = (new Article('Bissap', 50000, 'verre'))->setFamilleProduit($jus);
        foreach ([$sandwichs, $shawarma, $jus, $bissap] as $entite) {
            $this->em->persist($entite);
        }
        $this->em->flush();
        $this->ouvrirCaisse();

        $this->client->loginUser($this->boulanger);
        $crawler = $this->client->request('GET', '/atelier');
        $this->assertCount(1, $crawler->filter('a[href="/atelier/production/fast-food"]'));
        $this->assertCount(1, $crawler->filter('a[href="/atelier/production/autre"]'));
        $this->assertStringContainsString('Production · Fast-food', $crawler->text());

        $crawler = $this->client->request('GET', '/atelier/production/fast-food');
        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter(\sprintf('input[name="quantites[%d]"]', $shawarma->getId())));
        $this->assertCount(0, $crawler->filter(\sprintf('input[name="quantites[%d]"]', $this->croissant->getId())), 'Le croissant est à la boulangerie.');

        $saisie = $this->declarer(TypeSaisieProduction::PRODUCTION, [$shawarma->getId() => 12], $this->boulanger, Atelier::FAST_FOOD);
        $this->assertSame(Atelier::FAST_FOOD, $saisie->getAtelier());

        $this->client->request('GET', '/atelier/production/inconnu');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testLeComptePeutEtreCreeAvecLesNouveauxRoles(): void
    {
        $this->client->loginUser($this->dirigeante());
        $crawler = $this->client->request('GET', '/admin/utilisateurs/nouveau');

        $roles = $crawler->filter('select[name$="[role]"] option')->each(static fn ($o) => $o->attr('value'));
        $this->assertContains('ROLE_ATELIER', $roles);
        $this->assertContains('ROLE_VITRINE', $roles);
        $this->assertStringContainsString('ROLE_ATELIER', (string) $crawler->filter('[data-secret-role-roles-pin-value]')->attr('data-secret-role-roles-pin-value'));
    }
}
