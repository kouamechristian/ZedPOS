<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\FamilleProduit;
use App\Entity\LigneVente;
use App\Entity\MatierePremiere;
use App\Entity\Reglement;
use App\Entity\SessionCaisse;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Enum\ModeReglement;
use App\Enum\ModeVente;
use App\Enum\MotifPerte;
use App\Service\PerteService;
use App\Service\RapportQuotidienTexte;
use App\Service\SessionCaisseService;
use App\Service\SyntheseJourneeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Espace de pilotage : synthèse du jour, écran mobile, détail des tickets et
 * commande de rapport quotidien.
 */
class PilotageTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Utilisateur $dirigeante;
    private Utilisateur $gerant;
    private Utilisateur $caissier;
    private Article $baguette;
    private Article $croissant;
    private SessionCaisse $session;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $connexion = $this->em->getConnection();
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['ligne_fiche_technique', 'fiche_technique', 'ligne_vente', 'reglement', 'vente', 'mouvement_caisse', 'session_caisse', 'mouvement_stock', 'perte', 'article', 'matiere_premiere', 'fournisseur', 'famille_produit', 'journal_audit', 'utilisateur'] as $table) {
            $connexion->executeStatement('DELETE FROM '.$table);
        }
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $this->dirigeante = new Utilisateur('aya@test.ci', 'Aya Koné');
        $this->dirigeante->setRoles(['ROLE_DIRIGEANTE'])->setMotDePasse('x');
        $this->em->persist($this->dirigeante);

        $this->gerant = new Utilisateur('koffi@test.ci', 'Koffi');
        $this->gerant->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        $this->em->persist($this->gerant);

        $this->caissier = new Utilisateur('fatou@test.ci', 'Fatou Traoré');
        $this->caissier->setRoles(['ROLE_CAISSIER'])->setCodePin('x');
        $this->em->persist($this->caissier);

        $famille = new FamilleProduit('Pains');
        $this->em->persist($famille);

        $this->baguette = new Article('Baguette', 15000, 'pièce');
        $this->baguette->setFamilleProduit($famille)->setTauxTva(0);
        $this->em->persist($this->baguette);

        $this->croissant = new Article('Croissant', 25000, 'pièce');
        $this->croissant->setFamilleProduit($famille)->setTauxTva(0);
        $this->em->persist($this->croissant);

        $this->em->flush();

        $this->session = static::getContainer()->get(SessionCaisseService::class)->ouvrir($this->caissier, 0);
    }

    private function syntheses(): SyntheseJourneeService
    {
        return static::getContainer()->get(SyntheseJourneeService::class);
    }

    /**
     * Vente réglée en espèces, horodatée au jour voulu (réflexion, comme les fixtures).
     */
    private function vendre(
        \DateTimeImmutable $moment,
        int $totalTtc,
        ?Article $article = null,
        int $quantiteMillimes = 1000,
    ): Vente {
        $vente = new Vente($this->session, ModeVente::BOULANGERIE, \sprintf('VT-%05d', ++$this->sequence), $totalTtc, 0, $totalTtc);
        (new \ReflectionProperty($vente, 'createdAt'))->setValue($vente, $moment);

        // Prix unitaire déduit du total : la ligne doit sommer au montant de la vente.
        $prixUnitaire = intdiv($totalTtc * 1000, $quantiteMillimes);
        new LigneVente($vente, $article ?? $this->baguette, $quantiteMillimes, $prixUnitaire);
        new Reglement($vente, ModeReglement::ESPECES, $totalTtc);

        $this->em->persist($vente);
        $this->em->flush();

        return $vente;
    }

    // -------------------------------------------------------------- Agrégation

    public function testCaEtVariations(): void
    {
        $aujourdhui = new \DateTimeImmutable('today 10:00');

        $this->vendre($aujourdhui, 100000);                             // 1 000 FCFA
        $this->vendre($aujourdhui, 50000);                              //   500 FCFA
        $this->vendre($aujourdhui->modify('-1 day'), 100000);           // veille : 1 000
        $this->vendre($aujourdhui->modify('-7 days'), 200000);          // sem. dern. : 2 000

        $synthese = $this->syntheses()->construire();

        $this->assertSame(150000, $synthese->caJour);
        $this->assertSame(100000, $synthese->caVeille);
        $this->assertSame(200000, $synthese->caSemainePrecedente);
        $this->assertSame(5000, $synthese->variationVeilleBp, '+50 % vs la veille.');
        $this->assertSame(-2500, $synthese->variationSemaineBp, '−25 % vs la semaine précédente.');
        $this->assertSame(2, $synthese->nombreTickets);
        $this->assertSame(75000, $synthese->panierMoyen);
    }

    public function testVariationNulleQuandLaReferenceEstVide(): void
    {
        $this->vendre(new \DateTimeImmutable('today 10:00'), 100000);

        $synthese = $this->syntheses()->construire();

        $this->assertNull($synthese->variationVeilleBp, 'Aucun pourcentage calculable à partir de zéro.');
        $this->assertNull($synthese->variationSemaineBp);
    }

    public function testVentilationParReglementDeduitLeRendu(): void
    {
        $vente = $this->vendre(new \DateTimeImmutable('today 10:00'), 100000);
        $vente->enregistrerRemiseEtRendu(0, null, 20000); // 200 FCFA rendus
        $this->em->flush();

        $synthese = $this->syntheses()->construire();

        $this->assertCount(1, $synthese->parReglement);
        $this->assertSame(ModeReglement::ESPECES->value, $synthese->parReglement[0]['mode']);
        $this->assertSame(80000, $synthese->parReglement[0]['montant'], 'Espèces nettes du rendu de monnaie.');
    }

    public function testTopProduitsLimiteADix(): void
    {
        $aujourdhui = new \DateTimeImmutable('today 10:00');
        $this->vendre($aujourdhui, 15000, $this->baguette, 5000);  // 5 baguettes
        $this->vendre($aujourdhui, 25000, $this->croissant, 2000); // 2 croissants

        $top = $this->syntheses()->construire()->topProduits;

        $this->assertLessThanOrEqual(10, \count($top));
        $this->assertSame('Baguette', $top[0]['nom'], 'Classement par quantité vendue.');
        $this->assertSame(5000, $top[0]['quantite']);
        $this->assertSame('Croissant', $top[1]['nom']);
    }

    public function testCourbeSurTrenteJoursAvecLesJoursVides(): void
    {
        $this->vendre(new \DateTimeImmutable('today 10:00'), 100000);

        $serie = $this->syntheses()->construire()->serie30Jours;

        $this->assertCount(30, $serie, 'Les jours sans vente comptent pour zéro.');
        $this->assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $serie[29]['jour']);
        $this->assertSame(100000, $serie[29]['ca']);
        $this->assertSame(0, $serie[0]['ca']);
    }

    // ------------------------------------------------------ Points de vigilance

    public function testJourneePropre(): void
    {
        $this->vendre(new \DateTimeImmutable('today 10:00'), 100000);

        $synthese = $this->syntheses()->construire();

        $this->assertFalse($synthese->aDesPointsDeVigilance());
        $this->assertNull($synthese->ecartCaisse, 'Aucune caisse clôturée : pas de « 0 » trompeur.');
    }

    public function testPointsDeVigilanceRegroupes(): void
    {
        $aujourdhui = new \DateTimeImmutable('today 10:00');

        $annulee = $this->vendre($aujourdhui, 40000);
        $annulee->annuler('Erreur de saisie');

        $remisee = $this->vendre($aujourdhui, 100000);
        $remisee->enregistrerRemiseEtRendu(10000, 'Client fidèle', 0);
        $this->em->flush();

        $farine = (new MatierePremiere('Farine', 'kg'))->setCoutMoyenPondere(45000)->setStockActuel(1000)->setStockMini(50000);
        $this->em->persist($farine);
        $this->em->flush();

        $this->client->loginUser($this->gerant);
        static::getContainer()->get(PerteService::class)->enregistrer(MotifPerte::CASSE, $farine, null, 2000);

        static::getContainer()->get(SessionCaisseService::class)->cloturer($this->session, 50000, 'Manquant');

        $synthese = $this->syntheses()->construire();

        $this->assertTrue($synthese->aDesPointsDeVigilance());
        $this->assertSame(1, $synthese->annulationsNombre);
        $this->assertSame(40000, $synthese->annulationsMontant);
        $this->assertSame(1, $synthese->remisesNombre);
        $this->assertSame(10000, $synthese->remisesMontant);
        $this->assertSame(1, $synthese->pertesNombre);
        $this->assertSame(90000, $synthese->pertesMontant);
        $this->assertContains('Farine', $synthese->rupturesStock);
        $this->assertNotNull($synthese->ecartCaisse);
        $this->assertNotSame(0, $synthese->ecartCaisse);
    }

    // ------------------------------------------------------------------- Écrans

    public function testEcranReserveALaDirigeante(): void
    {
        $this->client->loginUser($this->gerant);
        $this->client->request('GET', '/pilotage');
        $this->assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->caissier);
        $this->client->request('GET', '/pilotage');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testTableauDeBordAfficheLesIndicateurs(): void
    {
        $aujourdhui = new \DateTimeImmutable('today 10:00');
        $this->vendre($aujourdhui, 150000, $this->baguette, 3000);
        $this->vendre($aujourdhui->modify('-1 day'), 100000);

        $this->client->loginUser($this->dirigeante);
        $crawler = $this->client->request('GET', '/pilotage');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '1 500');          // CA du jour, en gros
        $this->assertSelectorTextContains('body', 'Panier moyen');
        $this->assertSelectorTextContains('body', 'Espèces');
        $this->assertSelectorTextContains('body', 'Points de vigilance');
        $this->assertSelectorTextContains('body', 'Top 10 des produits');
        $this->assertSelectorTextContains('body', 'Baguette');
        $this->assertSelectorTextContains('body', '+50,0 %');        // variation vs veille
        $this->assertSelectorTextContains('body', '1 500 FCFA');     // top produit cohérent avec le CA

        // La courbe reçoit bien 30 points, en FCFA entiers.
        $graphique = $crawler->filter('[data-controller="graphique-ca"]');
        $this->assertCount(1, $graphique);
        $valeurs = json_decode($graphique->attr('data-graphique-ca-valeurs-value'), true);
        $this->assertCount(30, $valeurs);
        $this->assertSame(1500, $valeurs[29], 'Le graphique raisonne en FCFA, pas en centimes.');
    }

    public function testJourneeConsultableParLaDate(): void
    {
        $hier = (new \DateTimeImmutable('yesterday 10:00'));
        $this->vendre($hier, 320000);

        $this->client->loginUser($this->dirigeante);
        $this->client->request('GET', '/pilotage?jour='.$hier->format('Y-m-d'));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '3 200');
    }

    public function testListeEtDetailDunTicketAvecLeCaissier(): void
    {
        $vente = $this->vendre(new \DateTimeImmutable('today 10:00'), 150000, $this->baguette, 2000);

        $this->client->loginUser($this->dirigeante);

        // Liste du jour.
        $crawler = $this->client->request('GET', '/pilotage/ventes');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', $vente->getNumero());
        $this->assertSelectorTextContains('body', 'Fatou Traoré');

        // Détail atteint depuis la liste.
        $lien = $crawler->filter('a[href="/pilotage/ventes/'.$vente->getUuid().'"]');
        $this->assertCount(1, $lien);

        $this->client->click($lien->link());
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Fatou Traoré');
        $this->assertSelectorTextContains('body', 'Baguette');
        $this->assertSelectorTextContains('body', '1 500 FCFA');
    }

    public function testDetailDunTicketAnnule(): void
    {
        $vente = $this->vendre(new \DateTimeImmutable('today 10:00'), 100000);
        $vente->annuler('Client parti');
        $this->em->flush();

        $this->client->loginUser($this->dirigeante);
        $this->client->request('GET', '/pilotage/ventes/'.$vente->getUuid());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Ticket annulé');
        $this->assertSelectorTextContains('body', 'Client parti');
    }

    public function testTicketInconnu(): void
    {
        $this->client->loginUser($this->dirigeante);
        $this->client->request('GET', '/pilotage/ventes/pas-un-uuid');
        $this->assertResponseStatusCodeSame(404);
    }

    // ------------------------------------------------------ Rapport quotidien

    private function executer(array $entrees = []): string
    {
        $application = new Application(static::$kernel);
        $tester = new CommandTester($application->find('app:rapport-quotidien'));
        $tester->execute($entrees);
        $tester->assertCommandIsSuccessful();

        return $tester->getDisplay();
    }

    public function testRapportQuotidien(): void
    {
        $aujourdhui = new \DateTimeImmutable('today 10:00');
        $this->vendre($aujourdhui, 150000, $this->baguette, 3000);
        $this->vendre($aujourdhui->modify('-1 day'), 100000);

        $sortie = $this->executer();

        $this->assertStringContainsString('CA du jour', $sortie);
        $this->assertStringContainsString('1 500 FCFA', $sortie);
        $this->assertStringContainsString('+50,0 % vs hier', $sortie);
        $this->assertStringContainsString('Tickets : 1', $sortie);
        $this->assertStringContainsString('Espèces', $sortie);
        $this->assertStringContainsString('Baguette', $sortie);
        $this->assertStringContainsString('Vigilance', $sortie);

        // Message court, sans mise en forme lourde : lisible dans WhatsApp.
        $this->assertLessThan(1600, \strlen($sortie));
        $this->assertStringNotContainsString('<', $sortie);
    }

    public function testRapportQuotidienSurUneDateDonnee(): void
    {
        $hier = new \DateTimeImmutable('yesterday 10:00');
        $this->vendre($hier, 320000);

        $sortie = $this->executer(['--date' => $hier->format('Y-m-d')]);

        $this->assertStringContainsString('3 200 FCFA', $sortie);
    }

    public function testRapportQuotidienRefuseUneDateInvalide(): void
    {
        $application = new Application(static::$kernel);
        $tester = new CommandTester($application->find('app:rapport-quotidien'));

        $this->assertSame(2, $tester->execute(['--date' => '24/07/2026']), 'Code INVALID attendu.');
        $this->assertStringContainsString('Date invalide', $tester->getDisplay());
    }

    public function testRapportQuotidienSignaleUneJourneePropre(): void
    {
        $this->vendre(new \DateTimeImmutable('today 10:00'), 100000);
        static::getContainer()->get(SessionCaisseService::class)->cloturer($this->session, 100000);

        $texte = static::getContainer()->get(RapportQuotidienTexte::class)
            ->construire($this->syntheses()->construire());

        $this->assertStringContainsString('RAS, journée propre', $texte);
    }
}
