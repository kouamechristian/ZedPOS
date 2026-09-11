<?php

namespace App\Tests\Functional;

use App\Entity\DetteVendeur;
use App\Entity\Emplacement;
use App\Entity\Utilisateur;
use App\Entity\Vendeur;
use App\Enum\CleParametre;
use App\Enum\ModeReglement;
use App\Enum\StatutDette;
use App\Enum\TypeDette;
use App\Enum\TypeEmplacement;
use App\Service\DetteService;
use App\Service\ParametresBoutique;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Fiche du vendeur (solde, historique, remboursement, avance) et alerte de dette à
 * la dotation.
 */
class VendeurFicheTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Utilisateur $gerante;
    private Vendeur $awa;
    private Vendeur $kone;
    private Emplacement $stand;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $connexion = $this->em->getConnection();
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['remboursement_dette', 'dette_vendeur', 'ligne_retour', 'bon_retour', 'ligne_dotation', 'bon_dotation', 'arrete', 'vendeur', 'journal_audit', 'parametre', 'utilisateur'] as $table) {
            $connexion->executeStatement('DELETE FROM '.$table);
        }
        $connexion->executeStatement("DELETE FROM emplacement WHERE code <> 'DEPOT'");
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $this->gerante = (new Utilisateur('mariam@test.ci', 'Mariam'))->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        $this->awa = new Vendeur('Awa');
        $this->kone = new Vendeur('Koné');
        $this->stand = (new Emplacement('STAND-GARE', 'Stand de la gare', TypeEmplacement::STAND))->setVendeurHabituel($this->awa);

        foreach ([$this->gerante, $this->awa, $this->kone, $this->stand] as $entite) {
            $this->em->persist($entite);
        }
        $this->em->flush();
    }

    private function dette(Vendeur $vendeur, int $fcfa, TypeDette $type = TypeDette::AVANCE): DetteVendeur
    {
        return static::getContainer()->get(DetteService::class)->ouvrirManuelle($vendeur, $type, $fcfa * 100, 'Avance sur commission', $this->gerante);
    }

    private function url(Vendeur $vendeur): string
    {
        return '/admin/vendeurs/'.$vendeur->getId();
    }

    public function testLaFicheEstReserveeALaGestionDesStands(): void
    {
        $caissier = (new Utilisateur('fatou@test.ci', 'Fatou'))->setRoles(['ROLE_CAISSIER'])->setCodePin('x');
        $this->em->persist($caissier);
        $this->em->flush();

        $this->client->loginUser($caissier);
        foreach (['GET' => $this->url($this->awa), 'POST' => $this->url($this->awa).'/remboursement'] as $methode => $url) {
            $this->client->request($methode, $url);
            $this->assertResponseStatusCodeSame(403, $methode.' '.$url);
        }
    }

    public function testLaListeEtLaFicheMontrentLeSoldeDu(): void
    {
        $this->dette($this->awa, 8000);
        $this->dette($this->awa, 1000, TypeDette::AUTRE);
        $this->dette($this->kone, 1500);
        $this->client->loginUser($this->gerante);

        // Seuil par défaut : 5 000 F. Awa (9 000 F) au-delà, Koné (1 500 F) en deçà.
        $crawler = $this->client->request('GET', '/admin/vendeurs');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('9 000 FCFA', $crawler->filter('tr:contains("Awa") .badge-rouge')->text());
        $this->assertCount(0, $crawler->filter('tr:contains("Koné") .badge-rouge'));
        $this->assertCount(1, $crawler->filter('a[href="'.$this->url($this->awa).'"]:contains("Fiche")'));

        $crawler = $this->client->request('GET', $this->url($this->awa));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('9 000 FCFA', $crawler->filter('[data-solde-dette]')->text());
        $this->assertStringContainsString('au-delà du seuil', $crawler->filter('[data-solde-dette]')->text());
        $this->assertCount(2, $crawler->filter('table tbody tr'));

        foreach (['points' => 'Aucun point validé.', 'remboursements' => 'Aucun remboursement.'] as $onglet => $vide) {
            $this->client->request('GET', $this->url($this->awa).'?onglet='.$onglet);
            $this->assertResponseIsSuccessful();
            $this->assertSelectorTextContains('table', $vide);
        }
    }

    public function testEnregistrerUnRemboursementDepuisLaFiche(): void
    {
        $ancienne = $this->dette($this->awa, 1000);
        $this->dette($this->awa, 3000);
        $this->client->loginUser($this->gerante);

        $crawler = $this->client->request('GET', $this->url($this->awa));
        $this->client->submit($crawler->selectButton('Encaisser le versement')->form([
            'remboursement_dette[montant]' => '1500',
            'remboursement_dette[moyen]' => ModeReglement::ORANGE_MONEY->value,
        ]));

        $this->assertResponseRedirects($this->url($this->awa), 303);
        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'Versement de 1 500 FCFA enregistré sur 2 dettes. Reste dû : 2 500 FCFA.');

        $this->em->clear();
        $this->assertSame(StatutDette::SOLDEE, $this->em->find(DetteVendeur::class, $ancienne->getId())->getStatut());

        $crawler = $this->client->request('GET', $this->url($this->awa).'?onglet=remboursements');
        $this->assertCount(2, $crawler->filter('table tbody tr:contains("Orange Money")'));
    }

    /** Au-delà du solde : refusé, formulaire réaffiché en 422 avec le message, rien d'écrit. */
    public function testUnVersementAuDelaDuSoldeReafficheLaFicheEn422(): void
    {
        $dette = $this->dette($this->awa, 1000);
        $this->client->loginUser($this->gerante);

        $crawler = $this->client->request('GET', $this->url($this->awa));
        $this->client->submit($crawler->selectButton('Encaisser le versement')->form(['remboursement_dette[montant]' => '2000']));

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'ne doit que 1 000 FCFA');
        $this->assertSame('2000', $this->client->getCrawler()->filter('input[name="remboursement_dette[montant]"]')->attr('value'));

        $this->em->clear();
        $this->assertSame(0, $this->em->find(DetteVendeur::class, $dette->getId())->getMontantRembourse());
    }

    public function testInscrireUneAvanceDepuisLaFiche(): void
    {
        $this->client->loginUser($this->gerante);

        $crawler = $this->client->request('GET', $this->url($this->kone));
        $this->assertCount(0, $crawler->filter('select[name="dette_manuelle[type]"] option[value="ECART_CAISSE"]'), 'Un manquant ne se saisit pas à la main.');

        // Sans objet : 422.
        $this->client->submit($crawler->selectButton('Inscrire la dette')->form(['dette_manuelle[montant]' => '2500', 'dette_manuelle[commentaire]' => '']));
        $this->assertResponseStatusCodeSame(422);

        $crawler = $this->client->request('GET', $this->url($this->kone));
        $this->client->submit($crawler->selectButton('Inscrire la dette')->form([
            'dette_manuelle[type]' => TypeDette::AVANCE->value,
            'dette_manuelle[montant]' => '2500',
            'dette_manuelle[commentaire]' => 'Avance pour le taxi',
        ]));
        $this->assertResponseRedirects($this->url($this->kone), 303);

        $this->assertSame(250000, static::getContainer()->get(DetteService::class)->soldeDe($this->kone));
    }

    // ------------------------------------------------------- Alerte à la dotation

    /** Au-delà du seuil paramétré, la dotation du vendeur l'annonce — sans rien bloquer. */
    public function testLaDotationAlerteSurUnVendeurEndetteAuDelaDuSeuil(): void
    {
        $this->dette($this->awa, 6000);
        $this->dette($this->kone, 4000);
        $this->client->loginUser($this->gerante);
        $url = '/admin/dotations/nouvelle?stand='.$this->stand->getId();

        $crawler = $this->client->request('GET', $url);
        $this->assertResponseIsSuccessful();
        $alertes = $crawler->filter('[data-dotation-target="alerteDette"]');
        $this->assertCount(1, $alertes, 'Koné est sous le seuil de 5 000 F.');
        $this->assertSame((string) $this->awa->getId(), $alertes->attr('data-vendeur'));
        $this->assertNull($alertes->attr('hidden'), 'Awa, vendeuse habituelle, est pré-sélectionnée : l\'alerte se voit.');
        $this->assertStringContainsString('Awa doit 6 000 FCFA', $alertes->text());

        // Seuil relevé : plus d'alerte.
        static::getContainer()->get(ParametresBoutique::class)->enregistrer([CleParametre::SEUIL_ALERTE_DETTE->value => '6000']);
        $crawler = $this->client->request('GET', $url);
        $this->assertCount(0, $crawler->filter('[data-dotation-target="alerteDette"]'), 'Au seuil exact, pas au-delà.');

        // Seuil à zéro : toute dette alerte, celle d'un vendeur non choisi reste masquée.
        static::getContainer()->get(ParametresBoutique::class)->enregistrer([CleParametre::SEUIL_ALERTE_DETTE->value => '0']);
        $crawler = $this->client->request('GET', $url);
        $this->assertCount(2, $crawler->filter('[data-dotation-target="alerteDette"]'));
        $this->assertNotNull($crawler->filter('[data-dotation-target="alerteDette"][data-vendeur="'.$this->kone->getId().'"]')->attr('hidden'));
    }

    public function testLeSeuilSeRegleDansLesParametres(): void
    {
        $this->client->loginUser($this->gerante);
        $crawler = $this->client->request('GET', '/admin/parametres');
        $champ = 'parametres_boutique[revendeurs_seuil_alerte_dette]';
        $this->assertSame('5000', $crawler->filter('input[name="'.$champ.'"]')->attr('value'));

        $this->client->submit($crawler->selectButton('Enregistrer')->form([$champ => '5 000']));
        $this->assertResponseStatusCodeSame(422);

        $crawler = $this->client->request('GET', '/admin/parametres');
        $this->client->submit($crawler->selectButton('Enregistrer')->form([$champ => '7500']));
        $this->assertResponseRedirects('/admin/parametres', 303);
        $this->assertSame(750000, static::getContainer()->get(ParametresBoutique::class)->seuilAlerteDette());
    }
}
