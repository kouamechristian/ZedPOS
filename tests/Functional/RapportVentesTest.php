<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\FamilleProduit;
use App\Entity\LigneVente;
use App\Entity\Reglement;
use App\Entity\SessionCaisse;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Enum\ModeReglement;
use App\Enum\ModeVente;
use App\Service\Rapport\RapportVentesJournee;
use App\Service\SessionCaisseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Rapport de journée par caissière, ventilé par famille — écran et PDF.
 *
 * Ce que ce test protège, dans l'ordre : que les familles somment bien au total
 * affiché (remises comprises), qu'une vente annulée ne se glisse dans aucun
 * montant sans pour autant disparaître de la page, que le filtre par caissière
 * isole réellement, et que le PDF sort.
 */
class RapportVentesTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Utilisateur $gerant;
    private Utilisateur $fatou;
    private Utilisateur $yao;
    private Article $baguette;
    private Article $croissant;
    private Article $jus;
    private SessionCaisse $sessionFatou;
    private SessionCaisse $sessionYao;
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

        $this->gerant = new Utilisateur('koffi@test.ci', 'Koffi N\'Guessan');
        $this->gerant->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        $this->em->persist($this->gerant);

        $this->fatou = new Utilisateur('fatou@test.ci', 'Fatou Traoré');
        $this->fatou->setRoles(['ROLE_CAISSIER'])->setCodePin('x');
        $this->em->persist($this->fatou);

        $this->yao = new Utilisateur('yao@test.ci', 'Yao Kouassi');
        $this->yao->setRoles(['ROLE_CAISSIER'])->setCodePin('y');
        $this->em->persist($this->yao);

        // Deux familles, pour que la ventilation ait quelque chose à ventiler.
        $pains = (new FamilleProduit('Pains'))->setPosition(1);
        $this->em->persist($pains);
        $boissons = (new FamilleProduit('Boissons'))->setPosition(2);
        $this->em->persist($boissons);

        $this->baguette = new Article('Baguette', 15000, 'pièce');
        $this->baguette->setFamilleProduit($pains)->setTauxTva(0);
        $this->em->persist($this->baguette);

        $this->croissant = new Article('Croissant', 25000, 'pièce');
        $this->croissant->setFamilleProduit($pains)->setTauxTva(0);
        $this->em->persist($this->croissant);

        $this->jus = new Article('Jus de bissap', 50000, 'pièce');
        $this->jus->setFamilleProduit($boissons)->setTauxTva(0);
        $this->em->persist($this->jus);

        $this->em->flush();

        $sessions = static::getContainer()->get(SessionCaisseService::class);
        $this->sessionFatou = $sessions->ouvrir($this->fatou, 0);
        $this->sessionYao = $sessions->ouvrir($this->yao, 0);

        $this->client->loginUser($this->gerant);
    }

    private function rapports(): RapportVentesJournee
    {
        return static::getContainer()->get(RapportVentesJournee::class);
    }

    /**
     * Vente d'un seul article, réglée en espèces.
     *
     * @param int $prixUnitaire prix unitaire TTC en centimes
     * @param int $quantite     quantité en millièmes d'unité
     */
    private function vendre(
        SessionCaisse $session,
        Article $article,
        int $prixUnitaire,
        int $quantite = 1000,
        int $remise = 0,
    ): Vente {
        $brut = intdiv($quantite * $prixUnitaire + 500, 1000);
        $net = $brut - $remise;

        $vente = new Vente($session, ModeVente::BOULANGERIE, \sprintf('VT-%05d', ++$this->sequence), $net, 0, $net);
        new LigneVente($vente, $article, $quantite, $prixUnitaire);
        new Reglement($vente, ModeReglement::ESPECES, $net);
        if ($remise > 0) {
            $vente->enregistrerRemiseEtRendu($remise, 'Client fidèle', 0);
        }

        $this->em->persist($vente);
        $this->em->flush();

        return $vente;
    }

    public function testLeRapportVentileParFamilleEtTotaliseEnBas(): void
    {
        $this->vendre($this->sessionFatou, $this->baguette, 15000, 3000); // 3 × 150 = 450 F
        $this->vendre($this->sessionFatou, $this->croissant, 25000);      // 1 × 250 = 250 F
        $this->vendre($this->sessionFatou, $this->jus, 50000, 2000);      // 2 × 500 = 1 000 F

        $rapport = $this->rapports()->pour(new \DateTimeImmutable('today'));

        $this->assertSame(3, $rapport->tickets);
        $this->assertCount(2, $rapport->familles);

        // L'ordre est celui de la caisse (position de la famille), pas l'ordre des ventes.
        $this->assertSame('Pains', $rapport->familles[0]->nom);
        $this->assertSame('Boissons', $rapport->familles[1]->nom);

        // Pains : 45 000 + 25 000 centimes ; Boissons : 100 000 centimes.
        $this->assertSame(70000, $rapport->familles[0]->montant);
        $this->assertSame(100000, $rapport->familles[1]->montant);

        // Le total du bas est exactement la somme des familles.
        $this->assertSame(170000, $rapport->brutTtc);
        $this->assertSame(170000, $rapport->totalTtc);

        $this->client->request('GET', '/admin/ventes/rapport');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Pains');
        $this->assertSelectorTextContains('body', 'Boissons');
        $this->assertSelectorTextContains('body', '1 700 FCFA');
    }

    /**
     * Sans la ligne de remise, la somme des familles ne retomberait pas sur le
     * total encaissé et le rapport paraîtrait faux — alors qu'il ne le serait pas.
     */
    public function testLesRemisesSeparentLeBrutDesFamillesDuTotalEncaisse(): void
    {
        $this->vendre($this->sessionFatou, $this->baguette, 15000, 10000, remise: 20000); // 1 500 F − 200 F

        $rapport = $this->rapports()->pour(new \DateTimeImmutable('today'));

        $this->assertSame(150000, $rapport->familles[0]->montant, 'La famille porte le brut de ses lignes.');
        $this->assertSame(150000, $rapport->brutTtc);
        $this->assertSame(20000, $rapport->remises);
        $this->assertSame(130000, $rapport->totalTtc);
        $this->assertSame($rapport->brutTtc - $rapport->remises, $rapport->totalTtc);
    }

    public function testUneVenteAnnuleeEstExclueDesMontantsMaisAnnonceeAuRapport(): void
    {
        $this->vendre($this->sessionFatou, $this->baguette, 15000);
        $annulee = $this->vendre($this->sessionFatou, $this->croissant, 25000);
        $annulee->annuler('Erreur de saisie');
        $this->em->flush();

        $rapport = $this->rapports()->pour(new \DateTimeImmutable('today'));

        $this->assertSame(1, $rapport->tickets);
        $this->assertSame(1, $rapport->annulations);
        $this->assertSame(25000, $rapport->montantAnnule);
        $this->assertSame(15000, $rapport->totalTtc, 'Le ticket annulé ne compte dans aucun montant.');

        // Le croissant n'a pas été vendu : il ne figure dans aucune famille.
        $this->assertCount(1, $rapport->familles);
        $this->assertCount(1, $rapport->familles[0]->articles);
        $this->assertSame('Baguette', $rapport->familles[0]->articles[0]->nom);

        $this->client->request('GET', '/admin/ventes/rapport');
        $this->assertSelectorTextContains('body', 'annulé');
    }

    public function testLeFiltreParCaissiereIsoleReellementSesVentes(): void
    {
        $this->vendre($this->sessionFatou, $this->baguette, 15000);
        $this->vendre($this->sessionYao, $this->jus, 50000);

        $toutes = $this->rapports()->pour(new \DateTimeImmutable('today'));
        $this->assertSame(65000, $toutes->totalTtc);

        $deFatou = $this->rapports()->pour(new \DateTimeImmutable('today'), $this->fatou);
        $this->assertSame(1, $deFatou->tickets);
        $this->assertSame(15000, $deFatou->totalTtc);
        $this->assertCount(1, $deFatou->familles);
        $this->assertSame('Pains', $deFatou->familles[0]->nom);
        $this->assertSame('Fatou Traoré', $deFatou->libelleCaissier());

        $crawler = $this->client->request('GET', '/admin/ventes/rapport?caissier='.$this->fatou->getId());
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('Jus de bissap', $crawler->filter('body')->text());
    }

    /**
     * Le sélecteur ne propose que les caissières ayant encaissé ce jour-là :
     * proposer toute l'équipe reviendrait à proposer des rapports vides.
     */
    public function testLeSelecteurNeProposeQueLesCaissieresDeLaJournee(): void
    {
        $this->vendre($this->sessionFatou, $this->baguette, 15000);

        $crawler = $this->client->request('GET', '/admin/ventes/rapport');
        $options = $crawler->filter('select[name="caissier"] option')->extract(['_text']);
        $options = array_map('trim', $options);

        $this->assertContains('Fatou Traoré', $options);
        $this->assertNotContains('Yao Kouassi', $options);
    }

    /**
     * Un article vendu à deux prix dans la journée n'a plus « un » prix unitaire :
     * en afficher un serait un mensonge, sur l'écran comme sur le papier.
     */
    public function testUnArticleVenduADeuxPrixNAffichePlusDePrixUnitaire(): void
    {
        $this->vendre($this->sessionFatou, $this->baguette, 15000);
        $this->vendre($this->sessionFatou, $this->baguette, 20000);

        $rapport = $this->rapports()->pour(new \DateTimeImmutable('today'));

        $this->assertCount(1, $rapport->familles[0]->articles);
        $this->assertNull($rapport->familles[0]->articles[0]->prixUnitaire);
        $this->assertSame(35000, $rapport->familles[0]->articles[0]->montant);
    }

    public function testUneJourneeSansVenteNeCasseRien(): void
    {
        $this->client->request('GET', '/admin/ventes/rapport?jour=2020-01-01');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Aucune vente');
    }

    /** Une date illisible ne renvoie pas une erreur : elle retombe sur aujourd'hui. */
    public function testUneDateIllisibleRetombeSurAujourdHui(): void
    {
        $this->client->request('GET', '/admin/ventes/rapport?jour=pas-une-date');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', (new \DateTimeImmutable('today'))->format('d/m/Y'));
    }

    public function testLeRapportSortEnPdf(): void
    {
        $this->vendre($this->sessionFatou, $this->baguette, 15000, 2000);

        $this->client->request('GET', '/admin/ventes/rapport.pdf?caissier='.$this->fatou->getId());

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/pdf');

        $contenu = (string) $this->client->getResponse()->getContent();
        $this->assertStringStartsWith('%PDF-', $contenu, 'La réponse doit être un vrai fichier PDF.');
        $this->assertGreaterThan(1000, \strlen($contenu));

        $disposition = (string) $this->client->getResponse()->headers->get('Content-Disposition');
        $this->assertStringContainsString('.pdf', $disposition);
        $this->assertStringContainsString((new \DateTimeImmutable('today'))->format('Y-m-d'), $disposition);
    }

    /** Un caissier n'a rien à faire dans un rapport de gestion — écran comme PDF. */
    public function testUnCaissierNAccedePasAuRapport(): void
    {
        $this->client->loginUser($this->fatou);

        $this->client->request('GET', '/admin/ventes/rapport');
        $this->assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/admin/ventes/rapport.pdf');
        $this->assertResponseStatusCodeSame(403);
    }
}
