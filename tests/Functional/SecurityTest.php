<?php

namespace App\Tests\Functional;

use App\Entity\Utilisateur;
use App\Repository\JournalAuditRepository;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class SecurityTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        // Base de test propre (indépendante de l'ordre d'exécution des tests).
        $connection = $this->em->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['ligne_fiche_technique', 'fiche_technique', 'ligne_vente', 'reglement', 'vente', 'mouvement_caisse', 'session_caisse', 'mouvement_stock', 'perte', 'article', 'matiere_premiere', 'fournisseur', 'famille_produit', 'journal_audit', 'utilisateur'] as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        // Les compteurs de tentatives ne sont pas en base : ils vivent dans un
        // cache sur disque, qui survit au vidage des tables. Sans ce nettoyage,
        // un test qui échoue à se connecter lègue sa dette au suivant et c'est
        // l'ordre d'exécution qui décide du résultat.
        static::getContainer()->get('test.cache.rate_limiter')->clear();
    }

    /** Soumet un code PIN sur le pavé numérique, jeton CSRF compris. */
    private function saisirPin(string $pin): void
    {
        $crawler = $this->client->request('GET', '/caisse/login');

        $this->client->request('POST', '/caisse/login', [
            'code_pin' => $pin,
            '_csrf_token' => $crawler->filter('input[name="_csrf_token"]')->attr('value'),
        ]);
    }

    private function creerUtilisateur(string $email, string $role, ?string $motDePasse = null, ?string $codePin = null): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $utilisateur = new Utilisateur($email, 'Test '.$role);
        $utilisateur->setRoles([$role]);

        if (null !== $motDePasse) {
            $utilisateur->setMotDePasse($hasher->hashPassword($utilisateur, $motDePasse));
        }
        if (null !== $codePin) {
            $utilisateur->setCodePin($hasher->hashPassword($utilisateur, $codePin));
        }

        $this->em->persist($utilisateur);
        $this->em->flush();
    }

    public function testPagesDeConnexionAccessibles(): void
    {
        // Il faut un compte : sur une base vierge, toute l'application mène à
        // l'écran d'installation — un écran de connexion sur lequel aucun
        // identifiant ne marche n'aurait aucun sens. Voir InstallationTest.
        $this->creerUtilisateur('dirigeante@zedpos.ci', 'ROLE_DIRIGEANTE', motDePasse: 'secret123');

        $this->client->request('GET', '/login');
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/caisse/login');
        $this->assertResponseIsSuccessful();
    }

    public function testConnexionClassiqueRedirigeSelonRole(): void
    {
        $this->creerUtilisateur('dirigeante@zedpos.ci', 'ROLE_DIRIGEANTE', motDePasse: 'secret123');

        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->selectButton('Se connecter')->form([
            '_username' => 'dirigeante@zedpos.ci',
            '_password' => 'secret123',
        ]);
        $this->client->submit($form);

        $this->assertResponseRedirects('/pilotage');

        $audit = static::getContainer()->get(JournalAuditRepository::class)->findOneBy(['action' => 'CONNEXION']);
        $this->assertNotNull($audit, 'La connexion doit être journalisée.');
        $this->assertSame('dirigeante@zedpos.ci', $audit->getApres()['identifiant']);
    }

    public function testConnexionCaissierParCodePin(): void
    {
        $this->creerUtilisateur('caisse@zedpos.ci', 'ROLE_CAISSIER', codePin: '4321');

        $crawler = $this->client->request('GET', '/caisse/login');
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $this->client->request('POST', '/caisse/login', [
            'code_pin' => '4321',
            '_csrf_token' => $token,
        ]);

        $this->assertResponseRedirects('/caisse');
    }

    public function testMauvaisCodePinEchoueEtEstJournalise(): void
    {
        $this->creerUtilisateur('caisse@zedpos.ci', 'ROLE_CAISSIER', codePin: '4321');

        $crawler = $this->client->request('GET', '/caisse/login');
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $this->client->request('POST', '/caisse/login', [
            'code_pin' => '0000',
            '_csrf_token' => $token,
        ]);

        $this->assertResponseRedirects('/caisse/login');

        $audit = static::getContainer()->get(JournalAuditRepository::class)->findOneBy(['action' => 'ECHEC_CONNEXION']);
        $this->assertNotNull($audit, 'Un échec de connexion doit être journalisé.');
    }

    /**
     * Un code PIN, ce sont 10 000 combinaisons : sans limite, on les épuise en
     * quelques minutes. Et comme la caisse cherche à quel caissier appartient le
     * code saisi, une seule série d'essais ouvre n'importe quel compte de caisse
     * — il n'est pas même nécessaire d'en viser un.
     *
     * Le onzième essai est donc refusé **même s'il est juste** : c'est ce qui
     * prouve que le barrage précède la vérification, et non l'inverse.
     */
    public function testLeCodePinNeSeBalayePasCombinaisonParCombinaison(): void
    {
        $this->creerUtilisateur('caisse@zedpos.ci', 'ROLE_CAISSIER', codePin: '4321');

        for ($essai = 0; $essai < 10; ++$essai) {
            $this->saisirPin('0000');
            $this->assertResponseRedirects('/caisse/login', message: "L'essai $essai devait échouer.");
        }

        $this->saisirPin('4321');
        $this->assertResponseRedirects(
            '/caisse/login',
            message: 'Passé le quota, le bon code lui-même doit être écarté sans être vérifié.',
        );
    }

    /**
     * Le comptoir passe avant le pare-feu : une caissière qui tape juste ne doit
     * jamais être arrêtée, même après une matinée de connexions. Seuls les échecs
     * consomment un essai — sinon la file du matin s'arrêterait d'elle-même.
     */
    public function testUneConnexionReussieNeConsommeAucunEssai(): void
    {
        $this->creerUtilisateur('caisse@zedpos.ci', 'ROLE_CAISSIER', codePin: '4321');

        // Bien au-delà du quota de dix : aucune de ces connexions ne le grignote.
        for ($essai = 0; $essai < 15; ++$essai) {
            $this->saisirPin('4321');
            $this->assertResponseRedirects('/caisse', message: "La connexion $essai devait aboutir.");
        }
    }

    /**
     * Même barrage sur la connexion classique, celui-là porté par le
     * `login_throttling` du pare-feu — le compte de la dirigeante ouvre le
     * pilotage, l'audit et les prix de vente.
     */
    public function testLeMotDePasseNeSeDevinePasNonPlus(): void
    {
        $this->creerUtilisateur('dirigeante@zedpos.ci', 'ROLE_DIRIGEANTE', motDePasse: 'secret123');

        for ($essai = 0; $essai < 5; ++$essai) {
            $crawler = $this->client->request('GET', '/login');
            $this->client->submit($crawler->selectButton('Se connecter')->form([
                '_username' => 'dirigeante@zedpos.ci',
                '_password' => 'faux'.$essai,
            ]));
        }

        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Se connecter')->form([
            '_username' => 'dirigeante@zedpos.ci',
            '_password' => 'secret123',
        ]));

        $this->assertResponseRedirects(
            '/login',
            message: 'Passé le quota, le bon mot de passe ne doit plus ouvrir la session.',
        );
    }

    public function testAccesRefuseSansRole(): void
    {
        $this->creerUtilisateur('caisse@zedpos.ci', 'ROLE_CAISSIER', codePin: '4321');

        // Un caissier ne doit pas accéder à l'espace pilotage (ROLE_DIRIGEANTE).
        $this->client->loginUser(
            static::getContainer()->get(UtilisateurRepository::class)->findOneBy(['email' => 'caisse@zedpos.ci'])
        );
        $this->client->request('GET', '/pilotage');
        $this->assertResponseStatusCodeSame(403);
    }
}
