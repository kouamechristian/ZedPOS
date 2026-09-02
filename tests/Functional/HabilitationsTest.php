<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\FamilleProduit;
use App\Entity\LigneVente;
use App\Entity\Notification;
use App\Entity\Reglement;
use App\Entity\SessionCaisse;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Enum\ModeReglement;
use App\Enum\ModeVente;
use App\Enum\StatutVente;
use App\Repository\NotificationRepository;
use App\Security\Permission;
use App\Service\NotificateurDirigeante;
use App\Service\SessionCaisseService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Matrice d'habilitations : ce que chaque rôle peut et, surtout, ne peut pas.
 *
 * Le fil conducteur est le cloisonnement du caissier — il encaisse, il ne voit ni
 * les coûts, ni les marges, ni le résultat de la boutique, ni les ventes de ses
 * collègues.
 */
class HabilitationsTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Utilisateur $caissier;
    private Utilisateur $autreCaissier;
    private Utilisateur $gerant;
    private Utilisateur $dirigeante;
    private Utilisateur $comptable;
    private Article $article;
    private Vente $venteDuCaissier;
    private Vente $venteDeLAutreCaissier;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $connexion = $this->em->getConnection();
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['ligne_fiche_technique', 'fiche_technique', 'ligne_vente', 'reglement', 'vente', 'mouvement_caisse', 'session_caisse', 'mouvement_stock', 'perte', 'article', 'matiere_premiere', 'fournisseur', 'famille_produit', 'journal_audit', 'notification', 'utilisateur'] as $table) {
            $connexion->executeStatement('DELETE FROM '.$table);
        }
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $this->caissier = $this->creer('fatou@test.ci', 'Fatou', ['ROLE_CAISSIER']);
        $this->autreCaissier = $this->creer('yao@test.ci', 'Yao', ['ROLE_CAISSIER']);
        $this->gerant = $this->creer('koffi@test.ci', 'Koffi', ['ROLE_GERANT']);
        $this->dirigeante = $this->creer('aya@test.ci', 'Aya', ['ROLE_DIRIGEANTE']);
        $this->comptable = $this->creer('compta@test.ci', 'Comptable', ['ROLE_COMPTABLE']);

        $famille = new FamilleProduit('Pains');
        $this->em->persist($famille);

        $this->article = new Article('Baguette', 15000, 'pièce');
        $this->article->setFamilleProduit($famille)->setTauxTva(0);
        $this->em->persist($this->article);
        $this->em->flush();

        $sessions = static::getContainer()->get(SessionCaisseService::class);
        $this->venteDuCaissier = $this->vendre($sessions->ouvrir($this->caissier, 0), 'V-00001');
        $this->venteDeLAutreCaissier = $this->vendre($sessions->ouvrir($this->autreCaissier, 0), 'V-00002');
    }

    private function creer(string $email, string $nom, array $roles): Utilisateur
    {
        $utilisateur = new Utilisateur($email, $nom);
        $utilisateur->setRoles($roles)->setMotDePasse('x')->setCodePin('x');
        $this->em->persist($utilisateur);
        $this->em->flush();

        return $utilisateur;
    }

    private function vendre(SessionCaisse $session, string $numero): Vente
    {
        $vente = new Vente($session, ModeVente::BOULANGERIE, $numero, 15000, 0, 15000);
        new LigneVente($vente, $this->article, 1000, 15000);
        new Reglement($vente, ModeReglement::ESPECES, 15000);
        $this->em->persist($vente);
        $this->em->flush();

        return $vente;
    }

    /**
     * Routes interdites à un caissier, avec le motif tiré de la matrice.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function routesInterditesAuCaissier(): array
    {
        return [
            'CA global (back-office)' => ['GET', '/admin', 'CA du jour, CA 30 j, panier moyen'],
            'toutes les ventes' => ['GET', '/admin/ventes', 'ventes de tous les caissiers'],
            'coûts et marges' => ['GET', '/admin/production', 'coût matières et marge par fiche'],
            'catalogue avec coûts' => ['GET', '/admin/articles', 'colonne coût / marge'],
            'clôtures de caisse' => ['GET', '/admin/clotures', 'écarts et fonds de caisse'],
            'pertes valorisées' => ['GET', '/admin/pertes', 'valorisation des pertes'],
            'espace de pilotage' => ['GET', '/pilotage', 'CA, tendances, points de vigilance'],
            'tickets de pilotage' => ['GET', '/pilotage/ventes', 'ventes de tous les caissiers'],
            'journal d\'audit' => ['GET', '/pilotage/audit', 'traces de toutes les actions'],
            'modification d\'article' => ['GET', '/admin/articles/1/modifier', 'prix de vente'],
            'utilisateurs' => ['GET', '/admin/utilisateurs', 'comptes et rôles'],
        ];
    }

    #[DataProvider('routesInterditesAuCaissier')]
    public function testCaissierRecoit403SurLesRoutesInterdites(string $methode, string $url, string $motif): void
    {
        $this->client->loginUser($this->caissier);
        $this->client->request($methode, $url);

        $this->assertResponseStatusCodeSame(
            403,
            \sprintf('Un caissier ne doit pas accéder à %s (%s).', $url, $motif),
        );
    }

    public function testCaissierNeVoitPasLeTicketDunAutreCaissier(): void
    {
        $this->client->loginUser($this->caissier);

        // Son propre ticket : accessible.
        $this->client->request('GET', '/caisse/ticket/'.$this->venteDuCaissier->getUuid());
        $this->assertResponseIsSuccessful();

        // Celui d'un collègue : refusé, même en connaissant l'uuid.
        $this->client->request('GET', '/caisse/ticket/'.$this->venteDeLAutreCaissier->getUuid());
        $this->assertResponseStatusCodeSame(403);

        // Y compris par la sortie ESC/POS, qui contient les mêmes données.
        $this->client->request('GET', '/caisse/ticket/'.$this->venteDeLAutreCaissier->getUuid().'/escpos');
        $this->assertResponseStatusCodeSame(403);
    }

    /**
     * L'exception accordée au caissier s'arrête à sa propre caisse : connaître
     * l'uuid du ticket d'un collègue ne suffit pas, sinon un caissier pourrait
     * effacer les ventes d'un autre et lui laisser l'écart au Z.
     */
    public function testCaissierNAnnulePasLeTicketDunAutreCaissier(): void
    {
        $this->client->loginUser($this->caissier);
        $this->client->request(
            'POST',
            '/api/vente/'.$this->venteDeLAutreCaissier->getUuid().'/annuler',
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['motif' => 'Erreur']),
        );

        $this->assertResponseStatusCodeSame(403);

        $this->em->clear();
        $vente = $this->em->getRepository(Vente::class)->find($this->venteDeLAutreCaissier->getId());
        $this->assertSame(StatutVente::VALIDEE, $vente->getStatut());
    }

    /**
     * En revanche il annule le ticket qu'il vient d'encaisser : c'est le seul
     * geste d'écriture que la matrice lui accorde, et il est notifié.
     */
    public function testCaissierAnnuleLeTicketQuIlVientDEncaisser(): void
    {
        $this->client->loginUser($this->caissier);
        $this->client->request(
            'POST',
            '/api/vente/'.$this->venteDuCaissier->getUuid().'/annuler',
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['motif' => 'Erreur de saisie']),
        );

        $this->assertResponseIsSuccessful();

        $this->em->clear();
        $vente = $this->em->getRepository(Vente::class)->find($this->venteDuCaissier->getId());
        $this->assertSame(StatutVente::ANNULEE, $vente->getStatut(), 'Annulée, jamais supprimée.');
        $this->assertSame('Erreur de saisie', $vente->getMotifAnnulation());
    }

    // --------------------------------------------------- Prix de vente

    public function testSeuleLaDirigeanteModifieUnPrixDeVente(): void
    {
        $checker = static::getContainer()->get(AuthorizationCheckerInterface::class);

        $this->client->loginUser($this->dirigeante);
        $this->assertTrue($checker->isGranted(Permission::ARTICLE_MODIFIER_PRIX, $this->article));

        foreach ([$this->gerant, $this->caissier, $this->comptable] as $utilisateur) {
            $this->client->loginUser($utilisateur);
            $this->assertFalse(
                $checker->isGranted(Permission::ARTICLE_MODIFIER_PRIX, $this->article),
                \sprintf('%s ne doit pas pouvoir fixer un prix.', $utilisateur->getNom()),
            );
        }
    }

    public function testLeChampPrixEstAbsentDuFormulairePourUnGerant(): void
    {
        $this->client->loginUser($this->gerant);
        $crawler = $this->client->request('GET', '/admin/articles/'.$this->article->getId().'/modifier');

        $this->assertResponseIsSuccessful('Le gérant garde la main sur les autres attributs.');
        $this->assertCount(0, $crawler->filter('[name="article[prixVenteTtc]"]'), 'Champ prix absent du DOM.');
        $this->assertSelectorTextContains('body', 'Seule la dirigeante peut fixer un prix de vente.');

        // Et le champ n'est pas non plus injectable : la soumission l'ignore.
        $form = $crawler->selectButton('Enregistrer')->form();
        $this->client->request('POST', $form->getUri(), [
            'article' => [
                'nom' => 'Baguette',
                'unite' => 'pièce',
                'tauxTva' => '0',
                'positionCaisse' => '0',
                'actif' => '1',
                'prixVenteTtc' => '99999', // champ forgé
                '_token' => $form->get('article[_token]')->getValue(),
            ],
        ]);

        $this->em->clear();
        $article = $this->em->getRepository(Article::class)->find($this->article->getId());
        $this->assertSame(15000, $article->getPrixVenteTtc(), 'Le prix forgé est ignoré.');
    }

    public function testLaDirigeanteModifieBienLePrix(): void
    {
        $this->client->loginUser($this->dirigeante);
        $crawler = $this->client->request('GET', '/admin/articles/'.$this->article->getId().'/modifier');

        $this->assertResponseIsSuccessful();
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['article[prixVenteTtc]'] = '200'; // 200 FCFA
        $this->client->submit($form);

        $this->em->clear();
        $article = $this->em->getRepository(Article::class)->find($this->article->getId());
        $this->assertSame(20000, $article->getPrixVenteTtc());
    }

    public function testUnArticleCreeSansPrixResteInactif(): void
    {
        // Sinon un gérant contournerait la règle en recréant l'article.
        $this->client->loginUser($this->gerant);
        $crawler = $this->client->request('GET', '/admin/articles/nouveau');

        $form = $crawler->selectButton('Enregistrer')->form();
        $form['article[nom]'] = 'Chausson';
        $form['article[unite]'] = 'pièce';
        $form['article[actif]']->tick();
        $this->client->submit($form);

        $this->em->clear();
        $article = $this->em->getRepository(Article::class)->findOneBy(['nom' => 'Chausson']);
        $this->assertNotNull($article);
        $this->assertSame(0, $article->getPrixVenteTtc());
        $this->assertFalse($article->isActif(), 'Un article sans prix ne doit pas pouvoir être vendu.');
    }

    // --------------------------------------------------- Annulation

    public function testSeulLeGerantAnnuleEtLaDirigeanteEstNotifiee(): void
    {
        $this->client->loginUser($this->gerant);
        $this->client->request(
            'POST',
            '/api/vente/'.$this->venteDuCaissier->getUuid().'/annuler',
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['motif' => 'Article rendu par le client']),
        );

        $this->assertResponseIsSuccessful();

        $this->em->clear();
        $vente = $this->em->getRepository(Vente::class)->find($this->venteDuCaissier->getId());
        $this->assertSame(StatutVente::ANNULEE, $vente->getStatut());

        // La dirigeante est prévenue, avec le motif et l'auteur.
        $notifications = static::getContainer()->get(NotificationRepository::class)
            ->nonLuesPour('ROLE_DIRIGEANTE');

        $this->assertCount(1, $notifications);
        $this->assertSame(NotificateurDirigeante::TYPE_VENTE_ANNULEE, $notifications[0]->getType());
        $this->assertStringContainsString('V-00001', $notifications[0]->getTitre());
        $this->assertStringContainsString('Article rendu par le client', $notifications[0]->getMessage());
        $this->assertStringContainsString('Koffi', $notifications[0]->getMessage(), "L'auteur de l'annulation est nommé.");
        $this->assertStringContainsString('Fatou', $notifications[0]->getMessage(), 'Le caissier concerné est nommé.');
    }

    public function testLaNotificationApparaitDansLePilotage(): void
    {
        static::getContainer()->get(NotificateurDirigeante::class)
            ->venteAnnulee($this->venteDuCaissier, 'Erreur de saisie', $this->gerant);

        $this->client->loginUser($this->dirigeante);
        $this->client->request('GET', '/pilotage');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'V-00001 annulée');
        $this->assertSelectorTextContains('body', 'Erreur de saisie');
    }

    public function testUneNotificationSAcquitte(): void
    {
        $notification = static::getContainer()->get(NotificateurDirigeante::class)
            ->venteAnnulee($this->venteDuCaissier, 'Erreur', $this->gerant);

        $this->client->loginUser($this->dirigeante);
        $crawler = $this->client->request('GET', '/pilotage');
        $this->client->submit($crawler->selectButton("J'ai vu")->form());

        $this->assertResponseRedirects('/pilotage');
        $this->em->clear();

        $relue = $this->em->getRepository(Notification::class)->find($notification->getId());
        $this->assertTrue($relue->estLue());
        $this->assertCount(0, static::getContainer()->get(NotificationRepository::class)->nonLuesPour('ROLE_DIRIGEANTE'));
    }

    // --------------------------------------------------- Comptable

    public function testComptableEnLectureSeule(): void
    {
        $checker = static::getContainer()->get(AuthorizationCheckerInterface::class);
        $this->client->loginUser($this->comptable);

        // Lecture : accordée sur les données comptables.
        $this->assertTrue($checker->isGranted(Permission::VOIR_CA_GLOBAL));
        $this->assertTrue($checker->isGranted(Permission::VOIR_TOUTES_VENTES));
        $this->assertTrue($checker->isGranted(Permission::ARTICLE_VOIR_COUT));
        $this->assertTrue($checker->isGranted(Permission::VENTE_VOIR, $this->venteDuCaissier));

        // Écriture : refusée sans exception.
        $this->assertFalse($checker->isGranted(Permission::VENTE_ANNULER, $this->venteDuCaissier));
        $this->assertFalse($checker->isGranted(Permission::ARTICLE_MODIFIER, $this->article));
        $this->assertFalse($checker->isGranted(Permission::ARTICLE_MODIFIER_PRIX, $this->article));
    }

    public function testComptableNePeutPasAnnulerUneVente(): void
    {
        $this->client->loginUser($this->comptable);
        $this->client->request(
            'POST',
            '/api/vente/'.$this->venteDuCaissier->getUuid().'/annuler',
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['motif' => 'Régularisation comptable']),
        );

        // Le pare-feu ^/api exige déjà ROLE_CAISSIER, que le comptable n'a pas.
        $this->assertResponseStatusCodeSame(403);
    }

    // --------------------------------------------------- Voters, cas directs

    public function testCaissierNAAucuneHabilitationDeGestion(): void
    {
        $checker = static::getContainer()->get(AuthorizationCheckerInterface::class);
        $this->client->loginUser($this->caissier);

        $this->assertFalse($checker->isGranted(Permission::ARTICLE_VOIR_COUT), 'Ni coût de revient…');
        $this->assertFalse($checker->isGranted(Permission::ARTICLE_VOIR_COUT, $this->article), '…ni sur un article précis.');
        $this->assertFalse($checker->isGranted(Permission::VOIR_CA_GLOBAL), 'Ni CA global…');
        $this->assertFalse($checker->isGranted(Permission::VOIR_TOUTES_VENTES), '…ni ventes des collègues.');
        $this->assertFalse($checker->isGranted(Permission::ARTICLE_MODIFIER_PRIX, $this->article));

        // Seule exception : ses propres ventes.
        $this->assertTrue($checker->isGranted(Permission::VENTE_VOIR, $this->venteDuCaissier));
        $this->assertFalse($checker->isGranted(Permission::VENTE_VOIR, $this->venteDeLAutreCaissier));

        // Et son dernier ticket, qu'il peut annuler — mais jamais celui d'un autre.
        $this->assertTrue($checker->isGranted(Permission::VENTE_ANNULER, $this->venteDuCaissier));
        $this->assertFalse($checker->isGranted(Permission::VENTE_ANNULER, $this->venteDeLAutreCaissier));
    }

    public function testGerantVoitLesCoutsMaisPasLesPrix(): void
    {
        $checker = static::getContainer()->get(AuthorizationCheckerInterface::class);
        $this->client->loginUser($this->gerant);

        $this->assertTrue($checker->isGranted(Permission::ARTICLE_VOIR_COUT));
        $this->assertTrue($checker->isGranted(Permission::VOIR_CA_GLOBAL));
        $this->assertTrue($checker->isGranted(Permission::VENTE_ANNULER, $this->venteDuCaissier));
        $this->assertTrue($checker->isGranted(Permission::VENTE_VOIR, $this->venteDeLAutreCaissier));
        $this->assertFalse($checker->isGranted(Permission::ARTICLE_MODIFIER_PRIX, $this->article));
    }

    public function testUtilisateurAnonymeNAAucuneHabilitation(): void
    {
        $checker = static::getContainer()->get(AuthorizationCheckerInterface::class);

        $this->assertFalse($checker->isGranted(Permission::VOIR_CA_GLOBAL));
        $this->assertFalse($checker->isGranted(Permission::VENTE_VOIR, $this->venteDuCaissier));
        $this->assertFalse($checker->isGranted(Permission::ARTICLE_MODIFIER_PRIX, $this->article));
    }

    public function testUuidInexistantResteUn404PourUnGerant(): void
    {
        $this->client->loginUser($this->gerant);
        $this->client->request(
            'POST',
            '/api/vente/'.Uuid::v4().'/annuler',
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['motif' => 'Test']),
        );

        $this->assertResponseStatusCodeSame(404);
    }
}
