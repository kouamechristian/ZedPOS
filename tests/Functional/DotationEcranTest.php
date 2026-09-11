<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\BonDotation;
use App\Entity\Emplacement;
use App\Entity\JournalAudit;
use App\Entity\Utilisateur;
use App\Entity\Vendeur;
use App\Enum\ActionAudit;
use App\Enum\ModeRemuneration;
use App\Enum\PeriodicitePoint;
use App\Enum\TypeEmplacement;
use App\Enum\TypeMouvementStock;
use App\Repository\BonDotationRepository;
use App\Service\BonDotationService;
use App\Service\StockManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Écrans du module des revendeurs : qui y entre, ce que la saisie pré-remplit, ce
 * que l'écran fait d'une erreur, et le bon de sortie imprimé.
 */
class DotationEcranTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Utilisateur $gerante;
    private Utilisateur $dirigeante;
    private Vendeur $awa;
    private Vendeur $kone;
    private Emplacement $stand;
    private Article $coca;
    private Article $baguette;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $connexion = $this->em->getConnection();
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['remboursement_dette', 'dette_vendeur', 'ligne_retour', 'bon_retour', 'ligne_dotation', 'bon_dotation', 'arrete', 'vendeur', 'ligne_fiche_technique', 'fiche_technique', 'ligne_vente', 'reglement', 'vente', 'mouvement_caisse', 'session_caisse', 'mouvement_stock', 'stock_courant', 'perte', 'article', 'matiere_premiere', 'famille_produit', 'journal_audit', 'notification', 'utilisateur'] as $table) {
            $connexion->executeStatement('DELETE FROM '.$table);
        }
        $connexion->executeStatement("DELETE FROM emplacement WHERE code <> 'DEPOT'");
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $this->gerante = (new Utilisateur('mariam@test.ci', 'Mariam'))->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        $this->dirigeante = (new Utilisateur('aya@test.ci', 'Aya'))->setRoles(['ROLE_DIRIGEANTE'])->setMotDePasse('x');
        $this->awa = new Vendeur('Awa');
        $this->kone = new Vendeur('Koné');
        $this->stand = (new Emplacement('STAND-GARE', 'Stand de la gare', TypeEmplacement::STAND))->setVendeurHabituel($this->kone);
        $this->coca = (new Article('Coca', 50000, 'bouteille'))->setSuiviStock(true)->setPrixCession(42000);
        $this->baguette = (new Article('Baguette', 15000, 'pièce'))->setPrixCession(12500);

        foreach ([$this->gerante, $this->dirigeante, $this->awa, $this->kone, $this->stand, $this->coca, $this->baguette] as $entite) {
            $this->em->persist($entite);
        }
        $this->em->flush();

        $stock = static::getContainer()->get(StockManager::class);
        $stock->enregistrerMouvement($this->coca, $stock->depotPrincipal(), 24000, TypeMouvementStock::AJUSTEMENT);
    }

    private function utilisateur(string $email, string $role): Utilisateur
    {
        $utilisateur = (new Utilisateur($email, $role))->setRoles([$role]);
        $utilisateur = 'ROLE_CAISSIER' === $role ? $utilisateur->setCodePin('x') : $utilisateur->setMotDePasse('x');
        $this->em->persist($utilisateur);
        $this->em->flush();

        return $utilisateur;
    }

    private function bonValide(int $baguettes, int $cocas = 0): BonDotation
    {
        $service = static::getContainer()->get(BonDotationService::class);
        $quantites = [$this->baguette->getId() => $baguettes] + ($cocas > 0 ? [$this->coca->getId() => $cocas] : []);
        $bon = $service->creer($this->stand, $this->awa, new \DateTimeImmutable('yesterday'), $quantites, $this->gerante);
        $service->valider($bon, $this->gerante);

        return $bon;
    }

    // ------------------------------------------------------------ Accès

    /** Un seul rôle de terrain gère le module : la gérante. Aucun rôle créé pour lui. */
    public function testSeuleLaGeranteEtLaDirigeanteEntrentDansLeModule(): void
    {
        foreach (['/admin/dotations', '/admin/dotations/nouvelle', '/admin/stands', '/admin/vendeurs'] as $url) {
            foreach ([$this->gerante, $this->dirigeante] as $autorise) {
                $this->client->loginUser($autorise);
                $this->client->request('GET', $url);
                $this->assertResponseIsSuccessful($autorise->getEmail().' sur '.$url);
            }

            foreach (['ROLE_CAISSIER', 'ROLE_COMPTABLE'] as $role) {
                $this->client->loginUser($this->utilisateur(strtolower(substr($role, 5)).uniqid().'@test.ci', $role));
                $this->client->request('GET', $url);
                $this->assertResponseStatusCodeSame(403, $role.' sur '.$url);
            }
        }
    }

    // ------------------------------------------------------------- Saisie

    public function testLaSaisieProposeLeVendeurHabituelEtLaDerniereDotation(): void
    {
        $this->bonValide(35, 6);
        $this->client->loginUser($this->gerante);

        $crawler = $this->client->request('GET', '/admin/dotations/nouvelle');
        $this->assertCount(1, $crawler->filter(\sprintf('a[href="/admin/dotations/nouvelle?stand=%d"]', $this->stand->getId())), 'Le stand se choisit d\'un appui.');

        $crawler = $this->client->request('GET', '/admin/dotations/nouvelle?stand='.$this->stand->getId());
        $this->assertResponseIsSuccessful();

        $this->assertSame((string) $this->kone->getId(), $crawler->filter('select[name="vendeur"] option[selected]')->attr('value'), 'Vendeur habituel pré-sélectionné.');
        $this->assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $crawler->filter('input[name="date"]')->attr('value'));

        $formulaire = $crawler->filter('form[data-controller="dotation"]');
        $reprise = json_decode($formulaire->attr('data-dotation-reprise-value'), true);
        ksort($reprise);
        $attendu = [$this->baguette->getId() => 35, $this->coca->getId() => 6];
        ksort($attendu);
        $this->assertSame($attendu, $reprise, 'Quantités de la dernière dotation validée, en unités.');
        $this->assertCount(0, $crawler->filter('button[data-action="dotation#reprendre"][disabled]'));

        // Stock dépôt en direct : 24 − 6 cocas déjà dotés ; la baguette n'est pas suivie.
        $this->assertSame('18000', $crawler->filter(\sprintf('[data-dotation-target="tuile"][data-nom="Coca"]'))->attr('data-stock'));
        $this->assertSame('', $crawler->filter('[data-dotation-target="tuile"][data-nom="Baguette"]')->attr('data-stock'));
        $this->assertCount(10, $crawler->filter('button[data-action="dotation#appuyer"]'), 'Pavé numérique complet.');
    }

    public function testSansDotationPasseeLeBoutonReprendreEstInactif(): void
    {
        $this->client->loginUser($this->gerante);
        $crawler = $this->client->request('GET', '/admin/dotations/nouvelle?stand='.$this->stand->getId());

        $this->assertCount(1, $crawler->filter('button[data-action="dotation#reprendre"][disabled]'));
    }

    public function testValiderDepuisLEcranCreeEtValideLeBon(): void
    {
        $this->client->loginUser($this->gerante);
        $crawler = $this->client->request('GET', '/admin/dotations/nouvelle?stand='.$this->stand->getId());

        $formulaire = $crawler->selectButton('Valider la dotation')->form();
        $formulaire['vendeur'] = (string) $this->awa->getId();
        $formulaire['quantites['.$this->baguette->getId().']'] = '40';
        $formulaire['quantites['.$this->coca->getId().']'] = '10';
        $this->client->submit($formulaire);

        $bon = static::getContainer()->get(BonDotationRepository::class)->findOneBy([]);
        $this->assertInstanceOf(BonDotation::class, $bon);
        $this->assertResponseRedirects('/admin/dotations/'.$bon->getId(), 303);
        $this->assertTrue($bon->estValide());
        $this->assertSame('Awa', $bon->getVendeur()->getNom());

        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'validé');
    }

    /** Une erreur réaffiche l'écran avec ce qui a été tapé : quarante quantités ne se retapent pas. */
    public function testUneSaisieInvalideReaffichelEcranEn422SansRienPerdre(): void
    {
        $this->client->loginUser($this->gerante);
        $crawler = $this->client->request('GET', '/admin/dotations/nouvelle?stand='.$this->stand->getId());

        $formulaire = $crawler->selectButton('Enregistrer le brouillon')->form();
        $formulaire['vendeur'] = '';
        $formulaire['quantites['.$this->baguette->getId().']'] = '40';
        $crawler = $this->client->submit($formulaire);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('[role="alert"]', 'vendeur');
        $this->assertSame('40', $crawler->filter('input[name="quantites['.$this->baguette->getId().']"]')->attr('value'));
        $this->assertSame(0, static::getContainer()->get(BonDotationRepository::class)->count([]));
    }

    /** Validation refusée : le brouillon est gardé, on y revient avec le message. */
    public function testUneValidationRefuseeGardeLeBrouillon(): void
    {
        $this->client->loginUser($this->gerante);
        $crawler = $this->client->request('GET', '/admin/dotations/nouvelle?stand='.$this->stand->getId());

        $formulaire = $crawler->selectButton('Valider la dotation')->form();
        $formulaire['vendeur'] = (string) $this->awa->getId();
        $formulaire['quantites['.$this->coca->getId().']'] = '30'; // 24 au dépôt
        $this->client->submit($formulaire);

        $bon = static::getContainer()->get(BonDotationRepository::class)->findOneBy([]);
        $this->assertResponseRedirects('/admin/dotations/'.$bon->getId().'/modifier', 303);
        $this->assertTrue($bon->estBrouillon());

        $crawler = $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'Stock insuffisant pour « Coca »');
        $this->assertSame('30', $crawler->filter('input[name="quantites['.$this->coca->getId().']"]')->attr('value'));
    }

    public function testAnnulerDepuisLaFicheTraceEtRestaure(): void
    {
        $bon = $this->bonValide(40, 10);
        $this->client->loginUser($this->gerante);

        $crawler = $this->client->request('GET', '/admin/dotations/'.$bon->getId());
        $formulaire = $crawler->selectButton('Annuler ce bon')->form(['motif' => 'Vendeur malade']);
        $this->client->submit($formulaire);
        $this->assertResponseRedirects('/admin/dotations/'.$bon->getId(), 303);

        $this->em->clear();
        $this->assertTrue(static::getContainer()->get(BonDotationRepository::class)->find($bon->getId())->estAnnule());
        $this->assertCount(1, $this->em->getRepository(JournalAudit::class)->findBy(['action' => ActionAudit::DOTATION_ANNULEE->value]));
    }

    // ---------------------------------------------------------- Impression

    public function testLeBonImprimableMentionneLeDepotVenteEtLesSignatures(): void
    {
        $this->stand->setModeRemuneration(ModeRemuneration::MARGE);
        $this->em->flush();
        $bon = $this->bonValide(40, 10);
        $this->client->loginUser($this->gerante);

        $crawler = $this->client->request('GET', '/admin/dotations/'.$bon->getId().'/bon');

        $this->assertResponseIsSuccessful();
        $texte = $crawler->filter('body')->text();
        $this->assertStringContainsString('Marchandise confiée en dépôt-vente', $texte);
        $this->assertStringContainsString($bon->getNumero(), $texte);
        $this->assertStringContainsString('La gérante', $texte);
        $this->assertStringContainsString('Le vendeur', $texte);
        $this->assertStringContainsString('Awa', $texte);
        $this->assertStringContainsString('9 200 FCFA', $texte, '10 × 420 + 40 × 125 FCFA dus si tout est vendu.');
        $this->assertStringNotContainsString('sans valeur', $texte);
    }

    /** À la commission, le bon parle de valeur de vente et de taux, pas de prix de cession. */
    public function testLeBonDUnStandACommissionAfficheLeNetApresCommission(): void
    {
        $this->stand->setTauxCommission(1000);
        $this->em->flush();
        $bon = $this->bonValide(40, 10);
        $this->client->loginUser($this->gerante);

        $texte = $this->client->request('GET', '/admin/dotations/'.$bon->getId().'/bon')->filter('body')->text();

        $this->assertStringNotContainsString('Prix de cession', $texte);
        $this->assertStringContainsString('commission 10 %', $texte);
        $this->assertStringContainsString('9 900 FCFA', $texte, '(40 × 150 + 10 × 500) − 10 % = 9 900 F.');
    }

    public function testUnBrouillonImpriméEstMarqueSansValeur(): void
    {
        $service = static::getContainer()->get(BonDotationService::class);
        $bon = $service->creer($this->stand, $this->awa, new \DateTimeImmutable(), [$this->baguette->getId() => 5], $this->gerante);
        $this->client->loginUser($this->gerante);

        $this->client->request('GET', '/admin/dotations/'.$bon->getId().'/bon');

        $this->assertSelectorTextContains('.statut', 'sans valeur');
    }

    // ----------------------------------------------------------- Réglages

    /** Même règle que le prix de vente : la gérante voit le prix de cession, la dirigeante le fixe. */
    public function testLePrixDeCessionNeSeFixeQueParLaDirigeante(): void
    {
        $url = '/admin/articles/'.$this->baguette->getId().'/modifier';

        $this->client->loginUser($this->gerante);
        $crawler = $this->client->request('GET', $url);
        $this->assertCount(0, $crawler->filter('input[name="article[prixCession]"]'));
        $this->assertSelectorTextContains('body', '125 FCFA');

        $this->client->loginUser($this->dirigeante);
        $crawler = $this->client->request('GET', $url);
        $formulaire = $crawler->selectButton('Enregistrer')->form();
        $formulaire['article[prixCession]'] = '130';
        $this->client->submit($formulaire);

        $this->em->clear();
        $this->assertSame(13000, $this->em->getRepository(Article::class)->find($this->baguette->getId())->getPrixCession());
        $trace = $this->em->getRepository(JournalAudit::class)->findOneBy(['action' => ActionAudit::PRIX_MODIFIE->value]);
        $this->assertSame(['prixCession' => 12500], $trace->getAvant());
    }

    /** Périodicité du point : JOUR proposé, rien d'imposé. */
    public function testUnStandSeCreeAvecLaPeriodiciteJourParDefaut(): void
    {
        $this->client->loginUser($this->gerante);
        $crawler = $this->client->request('GET', '/admin/stands/nouveau');
        $this->assertSame('JOUR', $crawler->filter('select[name="stand[periodicitePoint]"] option[selected]')->attr('value'));

        $formulaire = $crawler->selectButton('Enregistrer')->form([
            'stand[code]' => 'stand-marche',
            'stand[libelle]' => 'Stand du marché',
            'stand[periodicitePoint]' => PeriodicitePoint::SEMAINE->value,
            'stand[vendeurHabituel]' => (string) $this->awa->getId(),
        ]);
        $this->client->submit($formulaire);
        $this->assertResponseRedirects('/admin/stands', 303);

        $stand = $this->em->getRepository(Emplacement::class)->findOneBy(['code' => 'STAND-MARCHE']);
        $this->assertTrue($stand->estStand());
        $this->assertSame(PeriodicitePoint::SEMAINE, $stand->getPeriodicitePoint());
        $this->assertSame('Awa', $stand->getVendeurHabituel()?->getNom());
    }
}
