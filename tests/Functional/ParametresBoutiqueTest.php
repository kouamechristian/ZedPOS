<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\FamilleProduit;
use App\Entity\Utilisateur;
use App\Enum\CleParametre;
use App\Form\ParametresBoutiqueType;
use App\Service\ParametresBoutique;
use App\Service\SessionCaisseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Paramètres de l'établissement : saisie en back-office, reprise sur le ticket.
 */
class ParametresBoutiqueTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Utilisateur $gerant;
    private Utilisateur $caissier;
    private int $articleId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $connexion = $this->em->getConnection();
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['ligne_fiche_technique', 'fiche_technique', 'ligne_vente', 'reglement', 'vente', 'mouvement_caisse', 'session_caisse', 'mouvement_stock', 'perte', 'article', 'matiere_premiere', 'fournisseur', 'famille_produit', 'journal_audit', 'notification', 'parametre', 'utilisateur'] as $table) {
            $connexion->executeStatement('DELETE FROM '.$table);
        }
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $this->gerant = new Utilisateur('koffi@test.ci', 'Koffi');
        $this->gerant->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        $this->em->persist($this->gerant);

        $this->caissier = new Utilisateur('fatou@test.ci', 'Fatou');
        $this->caissier->setRoles(['ROLE_CAISSIER'])->setCodePin('x');
        $this->em->persist($this->caissier);

        $famille = new FamilleProduit('Pains');
        $this->em->persist($famille);

        $article = new Article('Baguette', 15000, 'pièce');
        $article->setFamilleProduit($famille)->setTauxTva(0);
        $this->em->persist($article);

        $this->em->flush();
        $this->articleId = $article->getId();
    }

    private function parametres(): ParametresBoutique
    {
        return static::getContainer()->get(ParametresBoutique::class);
    }

    // ------------------------------------------------------------ Le stockage

    public function testValeursParDefautQuandLaTableEstVide(): void
    {
        // Une installation neuve doit pouvoir imprimer un ticket sans saisie préalable.
        $this->assertSame(
            CleParametre::RAISON_SOCIALE->valeurParDefaut(),
            $this->parametres()->valeur(CleParametre::RAISON_SOCIALE),
        );
        $this->assertSame('', $this->parametres()->valeur(CleParametre::NCC));
    }

    public function testEnregistrementPuisRelecture(): void
    {
        $this->parametres()->enregistrer([
            CleParametre::RAISON_SOCIALE->value => 'Boulangerie La Gerbe d\'Or',
            CleParametre::ADRESSE->value => 'Quartier Commerce',
            CleParametre::VILLE->value => 'Abengourou',
            CleParametre::NCC->value => 'CI-2026-0001',
        ]);

        $this->em->clear();

        $this->assertSame('Boulangerie La Gerbe d\'Or', $this->parametres()->valeur(CleParametre::RAISON_SOCIALE));
        $this->assertSame('CI-2026-0001', $this->parametres()->valeur(CleParametre::NCC));
    }

    public function testUneCleInconnueEstIgnoree(): void
    {
        $this->parametres()->enregistrer(['cle.inventee' => 'valeur']);

        $this->assertSame(
            0,
            (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM parametre WHERE cle = 'cle.inventee'"),
        );
    }

    public function testAdresseEtVilleSontAssembleesPourLeTicket(): void
    {
        $this->parametres()->enregistrer([
            CleParametre::ADRESSE->value => 'Quartier Commerce',
            CleParametre::VILLE->value => "Abengourou, Côte d'Ivoire",
        ]);

        $this->assertSame("Quartier Commerce, Abengourou, Côte d'Ivoire", $this->parametres()->pourTicket()->adresse);
    }

    public function testLEnseigneRemplaceLaRaisonSocialeEnTeteDeTicket(): void
    {
        $this->parametres()->enregistrer([
            CleParametre::RAISON_SOCIALE->value => 'ETS KOUAME SARL',
            CleParametre::ENSEIGNE->value => 'La Gerbe d\'Or',
        ]);

        $this->assertSame('La Gerbe d\'Or', $this->parametres()->pourTicket()->raisonSociale);
    }

    // ---------------------------------------------------------- L'écran admin

    public function testEcranDeSaisieReserveAuGerant(): void
    {
        $this->client->loginUser($this->caissier);
        $this->client->request('GET', '/admin/parametres');
        $this->assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->gerant);
        $this->client->request('GET', '/admin/parametres');
        $this->assertResponseIsSuccessful();
    }

    public function testSaisieDepuisLeFormulaire(): void
    {
        $this->client->loginUser($this->gerant);
        $crawler = $this->client->request('GET', '/admin/parametres');

        $form = $crawler->selectButton('Enregistrer')->form();
        $prefixe = 'parametres_boutique';
        $form[$prefixe.'['.ParametresBoutiqueType::champ(CleParametre::RAISON_SOCIALE).']'] = 'Boulangerie du Marché';
        $form[$prefixe.'['.ParametresBoutiqueType::champ(CleParametre::TELEPHONE).']'] = '+225 07 00 00 00 00';
        $form[$prefixe.'['.ParametresBoutiqueType::champ(CleParametre::PIED_TICKET).']'] = 'Merci et bonne journée';
        $this->client->submit($form);

        $this->assertResponseRedirects('/admin/parametres');

        $this->em->clear();
        $this->assertSame('Boulangerie du Marché', $this->parametres()->valeur(CleParametre::RAISON_SOCIALE));
        $this->assertSame('+225 07 00 00 00 00', $this->parametres()->valeur(CleParametre::TELEPHONE));
    }

    public function testRaisonSocialeObligatoire(): void
    {
        $this->client->loginUser($this->gerant);
        $crawler = $this->client->request('GET', '/admin/parametres');

        $form = $crawler->selectButton('Enregistrer')->form();
        $form['parametres_boutique['.ParametresBoutiqueType::champ(CleParametre::RAISON_SOCIALE).']'] = '';
        $this->client->submit($form);

        // 422 : Turbo exige un statut d'erreur pour réafficher un formulaire invalide.
        $this->assertResponseStatusCodeSame(422);
    }

    // ----------------------------------------------- Reprise sur le vrai ticket

    public function testLeTicketImprimeLesParametresSaisis(): void
    {
        $this->parametres()->enregistrer([
            CleParametre::RAISON_SOCIALE->value => 'Boulangerie La Gerbe d\'Or',
            CleParametre::ADRESSE->value => 'Quartier Commerce',
            CleParametre::VILLE->value => 'Abengourou',
            CleParametre::TELEPHONE->value => '+225 27 00 00 00',
            CleParametre::NCC->value => 'CI-2026-0001',
            CleParametre::RCCM->value => 'CI-ABG-2026-B-123',
            CleParametre::PIED_TICKET->value => 'Merci et à bientôt',
        ]);

        $uuid = $this->encaisser();

        $crawler = $this->client->request('GET', '/caisse/ticket/'.$uuid);
        $this->assertResponseIsSuccessful();

        // Le **texte rendu**, pas le HTML source : Twig y échappe l'apostrophe de
        // « d'Or » en `&#039;`, et une raison sociale sans apostrophe ferait passer
        // le test pour de mauvaises raisons. C'est de toute façon ce que la
        // caissière lit sur le papier qu'on veut vérifier ici.
        $ticket = $crawler->filter('.ticket')->text();

        $this->assertStringContainsString('Boulangerie La Gerbe d\'Or', $ticket);
        $this->assertStringContainsString('Quartier Commerce, Abengourou', $ticket);
        $this->assertStringContainsString('CI-2026-0001', $ticket);
        $this->assertStringContainsString('CI-ABG-2026-B-123', $ticket);
        $this->assertStringContainsString('Merci et à bientôt', $ticket);
    }

    public function testApercuRenvoieLeFragmentSansPageHote(): void
    {
        $uuid = $this->encaisser();

        $this->client->request('GET', '/caisse/ticket/'.$uuid.'/apercu');
        $this->assertResponseIsSuccessful();

        $corps = $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString('<html', $corps, 'Un fragment, pas une page.');
        $this->assertStringContainsString('class="ticket"', $corps);
        $this->assertStringContainsString('TOTAL', $corps);
    }

    public function testApercuEtTicketMontrentExactementLeMemeContenu(): void
    {
        $uuid = $this->encaisser();

        $this->client->request('GET', '/caisse/ticket/'.$uuid.'/apercu');
        $fragment = $this->client->getResponse()->getContent();

        $this->client->request('GET', '/caisse/ticket/'.$uuid);
        $page = $this->client->getResponse()->getContent();

        // Le fragment affiché à l'écran est littéralement inclus dans la page
        // imprimée : ce que voit la caissière ne peut pas différer du papier.
        $this->assertStringContainsString(trim($fragment), $page);
    }

    public function testApercuInterditSurLeTicketDunAutreCaissier(): void
    {
        $uuid = $this->encaisser();

        $autre = new Utilisateur('yao@test.ci', 'Yao');
        $autre->setRoles(['ROLE_CAISSIER'])->setCodePin('x');
        $this->em->persist($autre);
        $this->em->flush();

        $this->client->loginUser($autre);
        $this->client->request('GET', '/caisse/ticket/'.$uuid.'/apercu');

        $this->assertResponseStatusCodeSame(403);
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
