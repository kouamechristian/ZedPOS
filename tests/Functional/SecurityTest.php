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

        // Base de test propre : on vide l'audit (FK) puis les utilisateurs.
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM journal_audit');
        $connection->executeStatement('DELETE FROM utilisateur');
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
