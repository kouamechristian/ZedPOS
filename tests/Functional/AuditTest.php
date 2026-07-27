<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\FamilleProduit;
use App\Entity\JournalAudit;
use App\Entity\MatierePremiere;
use App\Entity\Utilisateur;
use App\Enum\ActionAudit;
use App\Enum\MotifPerte;
use App\Repository\JournalAuditRepository;
use App\Service\AuditLogger;
use App\Service\PerteService;
use App\Service\SessionCaisseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Journal d'audit : déclencheurs, inaltérabilité et écran de consultation.
 */
class AuditTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Utilisateur $dirigeante;
    private Utilisateur $gerant;
    private Utilisateur $caissier;
    private Article $article; // 1 000 FCFA, TVA 0
    private MatierePremiere $farine;

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

        $this->dirigeante = new Utilisateur('aya@test.ci', 'Aya');
        $this->dirigeante->setRoles(['ROLE_DIRIGEANTE'])->setMotDePasse('x');
        $this->em->persist($this->dirigeante);

        $this->gerant = new Utilisateur('koffi@test.ci', 'Koffi');
        $this->gerant->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        $this->em->persist($this->gerant);

        $this->caissier = new Utilisateur('fatou@test.ci', 'Fatou');
        $this->caissier->setRoles(['ROLE_CAISSIER'])->setCodePin('x');
        $this->em->persist($this->caissier);

        $famille = new FamilleProduit('Pains');
        $this->em->persist($famille);

        $this->article = new Article('Baguette', 100000, 'pièce');
        $this->article->setFamilleProduit($famille)->setTauxTva(0);
        $this->em->persist($this->article);

        $this->farine = (new MatierePremiere('Farine', 'kg'))->setCoutMoyenPondere(45000)->setStockActuel(100000);
        $this->em->persist($this->farine);

        $this->em->flush();
    }

    private function journal(): JournalAuditRepository
    {
        return static::getContainer()->get(JournalAuditRepository::class);
    }

    /** @return list<JournalAudit> */
    private function entrees(ActionAudit $action): array
    {
        return $this->journal()->findBy(['action' => $action->value], ['id' => 'ASC']);
    }

    private function poster(string $url, array $charge): array
    {
        $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($charge));

        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    /** Encaisse une vente et renvoie son UUID. */
    private function encaisser(array $extra = []): string
    {
        $uuid = (string) Uuid::v4();
        $this->poster('/api/vente', array_merge([
            'uuid' => $uuid,
            'mode' => 'BOULANGERIE',
            'lignes' => [['articleId' => $this->article->getId(), 'quantite' => 1]],
            'reglements' => [['mode' => 'ESPECES', 'montant' => 100000]],
        ], $extra));

        return $uuid;
    }

    // ------------------------------------------------------------ Déclencheurs

    public function testAnnulationDeVenteTracee(): void
    {
        static::getContainer()->get(SessionCaisseService::class)->ouvrir($this->caissier, 0);
        $this->client->loginUser($this->caissier);
        $uuid = $this->encaisser();

        $this->client->loginUser($this->gerant);
        $this->poster('/api/vente/'.$uuid.'/annuler', ['motif' => 'Erreur de saisie']);

        $entrees = $this->entrees(ActionAudit::VENTE_ANNULEE);
        $this->assertCount(1, $entrees);

        $entree = $entrees[0];
        $this->assertSame('Vente', $entree->getEntite());
        $this->assertSame($this->gerant->getId(), $entree->getUtilisateur()?->getId(), "L'auteur est le gérant qui annule.");
        $this->assertSame('VALIDEE', $entree->getAvant()['statut']);
        $this->assertSame('ANNULEE', $entree->getApres()['statut']);
        $this->assertSame('Erreur de saisie', $entree->getApres()['motif']);
        $this->assertNotNull($entree->getIp());
    }

    public function testRemiseAccordeeTracee(): void
    {
        static::getContainer()->get(SessionCaisseService::class)->ouvrir($this->gerant, 0);
        $this->client->loginUser($this->gerant);

        $this->encaisser([
            'lignes' => [['articleId' => $this->article->getId(), 'quantite' => 10]], // brut 10 000 FCFA
            'remise' => ['type' => 'POURCENTAGE', 'valeur' => 10, 'motif' => 'Client fidèle'],
            'reglements' => [['mode' => 'ESPECES', 'montant' => 900000]],
        ]);

        $entrees = $this->entrees(ActionAudit::REMISE_ACCORDEE);
        $this->assertCount(1, $entrees);
        $this->assertSame(1000000, $entrees[0]->getAvant()['totalTtc'], 'Avant remise : 10 000 FCFA.');
        $this->assertSame(900000, $entrees[0]->getApres()['totalTtc']);
        $this->assertSame(100000, $entrees[0]->getApres()['remise']);
        $this->assertSame('Client fidèle', $entrees[0]->getApres()['motif']);
    }

    public function testVenteSansRemiseNestPasTracee(): void
    {
        static::getContainer()->get(SessionCaisseService::class)->ouvrir($this->caissier, 0);
        $this->client->loginUser($this->caissier);
        $this->encaisser();

        $this->assertCount(0, $this->entrees(ActionAudit::REMISE_ACCORDEE));
    }

    public function testModificationDePrixTracee(): void
    {
        // Seule la dirigeante peut fixer un prix : c'est donc elle qui déclenche
        // cette trace (voir HabilitationsTest).
        $this->client->loginUser($this->dirigeante);

        $crawler = $this->client->request('GET', '/admin/articles/'.$this->article->getId().'/modifier');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Enregistrer')->form();
        $form['article[prixVenteTtc]'] = '1500'; // 1 000 → 1 500 FCFA
        $this->client->submit($form);

        $entrees = $this->entrees(ActionAudit::PRIX_MODIFIE);
        $this->assertCount(1, $entrees);
        $this->assertSame('Article', $entrees[0]->getEntite());
        $this->assertSame($this->article->getId(), $entrees[0]->getEntiteId());
        $this->assertSame(100000, $entrees[0]->getAvant()['prixVenteTtc']);
        $this->assertSame(150000, $entrees[0]->getApres()['prixVenteTtc']);
        $this->assertSame($this->dirigeante->getId(), $entrees[0]->getUtilisateur()?->getId());
    }

    public function testModificationSansChangementDePrixNestPasTracee(): void
    {
        $this->client->loginUser($this->gerant);

        $crawler = $this->client->request('GET', '/admin/articles/'.$this->article->getId().'/modifier');
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['article[nom]'] = 'Baguette tradition'; // le prix ne bouge pas
        $this->client->submit($form);

        $this->assertCount(0, $this->entrees(ActionAudit::PRIX_MODIFIE));
    }

    public function testSaisieDePerteTracee(): void
    {
        $this->client->loginUser($this->gerant);
        static::getContainer()->get(PerteService::class)
            ->enregistrer(MotifPerte::CASSE, $this->farine, null, 2000, 'Sac déchiré');

        $entrees = $this->entrees(ActionAudit::PERTE_SAISIE);
        $this->assertCount(1, $entrees);
        $this->assertSame('Farine', $entrees[0]->getApres()['support']);
        $this->assertSame(90000, $entrees[0]->getApres()['valorisation']);
        $this->assertSame('CASSE', $entrees[0]->getApres()['motif']);
        $this->assertSame('Sac déchiré', $entrees[0]->getApres()['commentaire']);
    }

    public function testValidationDInventaireTracee(): void
    {
        $this->client->loginUser($this->gerant);

        static::getContainer()->get(AuditLogger::class)
            ->inventaireValide($this->farine, 100000, 97500, 'Comptage mensuel');

        $entrees = $this->entrees(ActionAudit::INVENTAIRE_VALIDE);
        $this->assertCount(1, $entrees);
        $this->assertSame(100000, $entrees[0]->getAvant()['stockActuel']);
        $this->assertSame(97500, $entrees[0]->getApres()['stockActuel']);
        $this->assertSame(-2500, $entrees[0]->getApres()['ecart']);
    }

    public function testClotureDeCaisseSansEcartTraceeUneSeuleFois(): void
    {
        $sessions = static::getContainer()->get(SessionCaisseService::class);
        $session = $sessions->ouvrir($this->caissier, 3000000);
        $this->client->loginUser($this->caissier);

        $sessions->cloturer($session, 3000000);

        $this->assertCount(1, $this->entrees(ActionAudit::CAISSE_CLOTUREE));
        $this->assertCount(0, $this->entrees(ActionAudit::ECART_CAISSE), 'Sans écart, aucune entrée ECART_CAISSE.');
    }

    public function testEcartDeCaisseTraceSeparement(): void
    {
        $sessions = static::getContainer()->get(SessionCaisseService::class);
        $session = $sessions->ouvrir($this->caissier, 3000000);
        $this->client->loginUser($this->caissier);

        $sessions->cloturer($session, 2950000, 'Manquant constaté');

        $this->assertCount(1, $this->entrees(ActionAudit::CAISSE_CLOTUREE));

        $ecarts = $this->entrees(ActionAudit::ECART_CAISSE);
        $this->assertCount(1, $ecarts);
        $this->assertSame(-50000, $ecarts[0]->getApres()['ecart']);
        $this->assertSame('Manquant constaté', $ecarts[0]->getApres()['commentaire']);
        $this->assertSame('SessionCaisse', $ecarts[0]->getEntite());
    }

    public function testCreationDUtilisateurTracee(): void
    {
        static::getContainer()->get(AuditLogger::class)->utilisateurCree($this->caissier);

        $entrees = $this->entrees(ActionAudit::UTILISATEUR_CREE);
        $this->assertCount(1, $entrees);
        $this->assertSame('fatou@test.ci', $entrees[0]->getApres()['email']);
        $this->assertContains('ROLE_CAISSIER', $entrees[0]->getApres()['roles']);
    }

    /**
     * Bascule un compte depuis son bouton dans la liste : le formulaire porte le
     * jeton CSRF, qu'un POST nu n'aurait pas — et sans lui l'action est ignorée
     * en silence, ce qui ferait passer le test pour de mauvaises raisons.
     */
    private function basculer(Utilisateur $cible): void
    {
        $crawler = $this->client->request('GET', '/admin/utilisateurs');
        $this->client->submit(
            $crawler->filter('form[action$="/admin/utilisateurs/'.$cible->getId().'/basculer"] button')->form()
        );
    }

    public function testDesactivationDUtilisateurTracee(): void
    {
        $this->client->loginUser($this->dirigeante);

        $crawler = $this->client->request('GET', '/admin/utilisateurs');
        $this->assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form[action$="/admin/utilisateurs/'.$this->caissier->getId().'/basculer"] button')->form());
        $this->assertResponseRedirects('/admin/utilisateurs');

        $entrees = $this->entrees(ActionAudit::UTILISATEUR_DESACTIVE);
        $this->assertCount(1, $entrees);
        $this->assertTrue($entrees[0]->getAvant()['actif']);
        $this->assertFalse($entrees[0]->getApres()['actif']);
        $this->assertSame($this->dirigeante->getId(), $entrees[0]->getUtilisateur()?->getId());

        $this->em->clear();
        $this->assertFalse($this->em->getRepository(Utilisateur::class)->find($this->caissier->getId())->isActif());
    }

    /**
     * Le gérant désactive un caissier — c'est de la gestion d'équipe — et la
     * trace d'audit porte **son** nom, pas celui de la dirigeante.
     */
    public function testDesactivationParLeGerantTraceeASonNom(): void
    {
        $this->client->loginUser($this->gerant);
        $this->basculer($this->caissier);

        $entrees = $this->entrees(ActionAudit::UTILISATEUR_DESACTIVE);
        $this->assertCount(1, $entrees);
        $this->assertSame($this->gerant->getId(), $entrees[0]->getUtilisateur()?->getId());
    }

    /**
     * En revanche il n'a pas la main sur un compte dirigeante : il pourrait sinon
     * couper l'établissement de son seul accès au pilotage et à l'audit.
     */
    public function testDesactivationDUneDirigeanteInterditeAuGerant(): void
    {
        $this->client->loginUser($this->gerant);
        $this->client->request('POST', '/admin/utilisateurs/'.$this->dirigeante->getId().'/basculer');

        $this->assertResponseStatusCodeSame(403);
        $this->assertEmpty($this->entrees(ActionAudit::UTILISATEUR_DESACTIVE));
    }

    public function testConnexionEtEchecTraces(): void
    {
        $this->client->request('POST', '/caisse/login', [
            '_csrf_token' => 'invalide',
            'code_pin' => '9999',
        ]);

        $this->assertGreaterThanOrEqual(1, \count($this->entrees(ActionAudit::ECHEC_CONNEXION)));
    }

    // -------------------------------------------------------- Inaltérabilité

    public function testUneEntreeNePeutPasEtreModifiee(): void
    {
        $entree = static::getContainer()->get(AuditLogger::class)
            ->enregistrer(ActionAudit::PRIX_MODIFIE, 'Article', 1, ['prixVenteTtc' => 1], ['prixVenteTtc' => 2]);

        // Aucun setter n'existe : on force la valeur par réflexion pour prouver que
        // même ainsi, l'ORM refuse d'écrire la modification.
        $propriete = new \ReflectionProperty(JournalAudit::class, 'action');
        $propriete->setValue($entree, ActionAudit::CONNEXION->value);

        $this->expectException(\DomainException::class);
        $this->em->flush();
    }

    public function testUneEntreeNePeutPasEtreSupprimee(): void
    {
        $entree = static::getContainer()->get(AuditLogger::class)
            ->enregistrer(ActionAudit::PERTE_SAISIE, 'Perte', 1, null, ['valorisation' => 100]);

        $this->em->remove($entree);

        $this->expectException(\DomainException::class);
        $this->em->flush();
    }

    public function testJournalAuditNExposeAucunSetter(): void
    {
        foreach ((new \ReflectionClass(JournalAudit::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $methode) {
            $this->assertStringStartsNotWith('set', $methode->getName(), 'Le journal doit rester immuable.');
        }
    }

    public function testAucuneRouteDEcritureSurLeJournal(): void
    {
        $routes = static::getContainer()->get('router')->getRouteCollection();

        foreach ($routes as $nom => $route) {
            if (str_contains($route->getPath(), '/pilotage/audit')) {
                $this->assertSame(['GET'], $route->getMethods(), \sprintf('La route "%s" doit être en lecture seule.', $nom));
            }
        }
    }

    // ------------------------------------------------------------ Consultation

    public function testAccueilPilotageRenvoieVersLeJournal(): void
    {
        $this->client->loginUser($this->dirigeante);
        $crawler = $this->client->request('GET', '/pilotage');

        $this->assertResponseIsSuccessful();
        $this->assertGreaterThanOrEqual(
            1,
            $crawler->filter('a[href="/pilotage/audit"]')->count(),
            "L'accueil pilotage donne accès au journal.",
        );
    }

    public function testEcranReserveALaDirigeante(): void
    {
        $this->client->loginUser($this->gerant);
        $this->client->request('GET', '/pilotage/audit');
        $this->assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->dirigeante);
        $this->client->request('GET', '/pilotage/audit');
        $this->assertResponseIsSuccessful();
    }

    public function testFiltreParTypeDAction(): void
    {
        $audit = static::getContainer()->get(AuditLogger::class);
        $audit->enregistrer(ActionAudit::PRIX_MODIFIE, 'Article', 1, ['prixVenteTtc' => 100], ['prixVenteTtc' => 200]);
        $audit->enregistrer(ActionAudit::PERTE_SAISIE, 'Perte', 1, null, ['valorisation' => 500]);

        $this->client->loginUser($this->dirigeante);
        $this->client->request('GET', '/pilotage/audit?action='.ActionAudit::PRIX_MODIFIE->value);

        // Ciblé sur le tableau : les libellés figurent aussi dans le filtre déroulant.
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('table tbody', 'Modification de prix');
        $this->assertSelectorTextNotContains('table tbody', 'Saisie de perte');
    }

    public function testFiltreParUtilisateurEtParDate(): void
    {
        $audit = static::getContainer()->get(AuditLogger::class);
        $audit->enregistrer(ActionAudit::PRIX_MODIFIE, 'Article', 1, null, ['nom' => 'Baguette'], $this->gerant);
        $audit->enregistrer(ActionAudit::PRIX_MODIFIE, 'Article', 2, null, ['nom' => 'Croissant'], $this->caissier);

        $this->client->loginUser($this->dirigeante);

        // Par utilisateur.
        $this->client->request('GET', '/pilotage/audit?utilisateur='.$this->gerant->getId());
        $this->assertSelectorTextContains('body', 'Baguette');
        $this->assertSelectorTextNotContains('body', 'Croissant');

        // Période sans aucune entrée.
        $hier = (new \DateTimeImmutable('yesterday'))->format('Y-m-d');
        $this->client->request('GET', '/pilotage/audit?du=2020-01-01&au='.$hier);
        $this->assertSelectorTextContains('body', 'Aucune entrée pour ces critères');

        // Période incluant aujourd'hui.
        $aujourdhui = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $this->client->request('GET', '/pilotage/audit?du='.$aujourdhui.'&au='.$aujourdhui);
        $this->assertSelectorTextContains('body', 'Baguette');
    }

    public function testPagination(): void
    {
        $audit = static::getContainer()->get(AuditLogger::class);
        for ($i = 1; $i <= JournalAuditRepository::PAR_PAGE + 5; ++$i) {
            $audit->enregistrer(ActionAudit::PRIX_MODIFIE, 'Article', $i, null, ['rang' => $i], $this->gerant);
        }

        $resultat = $this->journal()->rechercher(page: 1);
        $this->assertCount(JournalAuditRepository::PAR_PAGE, $resultat->items);
        $this->assertSame(JournalAuditRepository::PAR_PAGE + 5, $resultat->total);
        $this->assertSame(2, $resultat->pages);

        $this->assertCount(5, $this->journal()->rechercher(page: 2)->items);

        $this->client->loginUser($this->dirigeante);
        $this->client->request('GET', '/pilotage/audit?page=2');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Page 2 / 2');
    }
}
