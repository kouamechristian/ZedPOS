<?php

namespace App\Tests\Functional;

use App\Entity\Utilisateur;
use App\Enum\ActionAudit;
use App\Entity\JournalAudit;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Amorçage d'une base vierge.
 *
 * C'est l'œuf et la poule : personne ne peut créer de compte sans être déjà
 * gérant, et personne ne peut se connecter sans compte. L'écran d'installation
 * est la seule porte d'entrée — et il doit se refermer derrière lui, sinon
 * n'importe qui s'ouvrirait un accès dirigeante à tout moment.
 */
class InstallationTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $connexion = $this->em->getConnection();
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['ligne_inventaire', 'inventaire', 'ligne_fiche_technique', 'fiche_technique', 'ligne_vente', 'reglement', 'vente', 'mouvement_caisse', 'session_caisse', 'mouvement_stock', 'perte', 'article', 'matiere_premiere', 'fournisseur', 'famille_produit', 'journal_audit', 'notification', 'utilisateur'] as $table) {
            $connexion->executeStatement('DELETE FROM '.$table);
        }
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function creerLaDirigeante(string $email = 'aya@boulangerie.ci', string $motDePasse = 'secret123'): void
    {
        $crawler = $this->client->request('GET', '/installation');
        $this->assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Créer le compte dirigeante')->form([
            'installation[nom]' => 'Aya Koné',
            'installation[email]' => $email,
            'installation[motDePasse][first]' => $motDePasse,
            'installation[motDePasse][second]' => $motDePasse,
        ]));
    }

    // ------------------------------------------------- Tant que la base est vide

    /**
     * Sans cette redirection, une base vierge n'offrirait qu'un écran de connexion
     * sur lequel aucun identifiant ne marche : porte close, sans indication.
     */
    #[DataProvider('urlsDeLApplication')]
    public function testToutMeneALInstallationTantQuAucunCompteNExiste(string $url): void
    {
        $this->client->request('GET', $url);

        $this->assertResponseRedirects('/installation', null, 'Depuis '.$url);
    }

    public static function urlsDeLApplication(): array
    {
        return [
            'accueil' => ['/'],
            'connexion' => ['/login'],
            'caisse' => ['/caisse'],
            'connexion caisse' => ['/caisse/login'],
            'back-office' => ['/admin'],
            'pilotage' => ['/pilotage'],
            'comptabilité' => ['/comptabilite'],
        ];
    }

    /**
     * Le pare-feu enverrait `/admin` vers `/login` : la redirection d'amorçage
     * passe donc **avant** lui, pour éviter un détour par un écran de connexion
     * inutilisable.
     */
    public function testLaRedirectionPrecedeLeParefeu(): void
    {
        $this->client->request('GET', '/admin');

        $this->assertResponseRedirects('/installation');
        $this->assertStringNotContainsString('login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testLEcranDInstallationNeSeRedirigePasVersLuiMeme(): void
    {
        $this->client->request('GET', '/installation');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'dirigeante');
    }

    // ------------------------------------------------------------- La création

    public function testLePremierCompteEstUneDirigeante(): void
    {
        $this->creerLaDirigeante();

        $this->assertResponseRedirects('/login');

        $utilisateur = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => 'aya@boulangerie.ci']);
        $this->assertInstanceOf(Utilisateur::class, $utilisateur);
        $this->assertContains('ROLE_DIRIGEANTE', $utilisateur->getRoles());
        $this->assertSame('Aya Koné', $utilisateur->getNom());
        $this->assertTrue($utilisateur->isActif());
    }

    public function testLeMotDePasseEstHacheEtPermetDeSeConnecter(): void
    {
        $this->creerLaDirigeante();

        $utilisateur = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => 'aya@boulangerie.ci']);
        $this->assertNotSame('secret123', $utilisateur->getMotDePasse(), 'Le mot de passe ne doit jamais être stocké en clair.');
        $this->assertNull($utilisateur->getCodePin(), 'Une dirigeante ne se connecte pas au pavé numérique.');

        $hasher = static::getContainer()->get('security.user_password_hasher');
        $this->assertTrue($hasher->isPasswordValid($utilisateur, 'secret123'));
    }

    /** Le premier compte est une distribution d'accès comme une autre : il se trace. */
    public function testLaCreationEstTraceeAuJournalDAudit(): void
    {
        $this->creerLaDirigeante();

        $entrees = $this->em->getRepository(JournalAudit::class)
            ->findBy(['action' => ActionAudit::UTILISATEUR_CREE]);

        $this->assertCount(1, $entrees);
        $this->assertSame('aya@boulangerie.ci', $entrees[0]->getApres()['email']);
        // Personne n'était connecté : l'auteur est nul, et c'est exact.
        $this->assertNull($entrees[0]->getUtilisateur());
    }

    /**
     * C'est le seul mot de passe du système : une faute de frappe et
     * l'installation serait perdue, sans second compte pour la rattraper.
     */
    public function testUneConfirmationQuiNeCorrespondPasEstRefusee(): void
    {
        $crawler = $this->client->request('GET', '/installation');

        $this->client->submit($crawler->selectButton('Créer le compte dirigeante')->form([
            'installation[nom]' => 'Aya Koné',
            'installation[email]' => 'aya@boulangerie.ci',
            'installation[motDePasse][first]' => 'secret123',
            'installation[motDePasse][second]' => 'secret124',
        ]));

        // 422 : Turbo ne remplace pas la page sur un 200.
        $this->assertResponseStatusCodeSame(422);
        $this->assertNull($this->em->getRepository(Utilisateur::class)->findOneBy(['email' => 'aya@boulangerie.ci']));
    }

    public function testUnMotDePasseTropCourtEstRefuse(): void
    {
        $crawler = $this->client->request('GET', '/installation');

        $this->client->submit($crawler->selectButton('Créer le compte dirigeante')->form([
            'installation[nom]' => 'Aya Koné',
            'installation[email]' => 'aya@boulangerie.ci',
            'installation[motDePasse][first]' => 'court',
            'installation[motDePasse][second]' => 'court',
        ]));

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame(0, $this->em->getRepository(Utilisateur::class)->count([]));
    }

    // ----------------------------------------------- La porte se referme derrière

    /**
     * **Le point de sécurité du module.** La route est publique par nécessité ;
     * si elle restait ouverte, n'importe quel visiteur s'ouvrirait un accès
     * dirigeante — la caisse, les prix, les comptes de l'établissement.
     */
    public function testLaRouteSeFermeDesQuUnCompteExiste(): void
    {
        $this->creerLaDirigeante();

        $this->client->request('GET', '/installation');
        $this->assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/installation');
        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * Elle se ferme même si le seul compte n'est **pas** une dirigeante : c'est
     * l'existence d'un compte qui compte, pas son rôle. Sinon un caissier créé en
     * console rouvrirait la porte à tout le monde.
     */
    public function testLaRouteSeFermeMemeSiLeSeulCompteEstUnCaissier(): void
    {
        $caissier = new Utilisateur('fatou@test.ci', 'Fatou');
        $caissier->setRoles(['ROLE_CAISSIER'])->setCodePin('x');
        $this->em->persist($caissier);
        $this->em->flush();

        $this->client->request('GET', '/installation');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testLaRedirectionCesseDesQuUnCompteExiste(): void
    {
        $this->creerLaDirigeante();

        $this->client->request('GET', '/login');
        $this->assertResponseIsSuccessful('L\'écran de connexion reprend sa place.');

        $this->client->request('GET', '/admin');
        $this->assertResponseRedirects('/login', null, 'Le pare-feu reprend la main.');
    }
}
