<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\FamilleProduit;
use App\Entity\Utilisateur;
use App\Repository\VenteRepository;
use App\Service\SessionCaisseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Reprise après coupure réseau, côté serveur.
 *
 * Le test rejoue le trafic exact que produit la file de synchronisation de la
 * caisse au retour de la connexion : une rafale de ventes accumulées hors ligne,
 * avec des rejeux (réponses perdues, relances). L'invariant vérifié est celui
 * annoncé au caissier : **aucune vente perdue, aucune vente dupliquée**.
 *
 * Le filet de sécurité est l'idempotence de POST /api/vente sur l'uuid client.
 */
class SynchronisationHorsLigneTest extends WebTestCase
{
    private const NOMBRE_VENTES = 20;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Utilisateur $caissier;
    private int $articleId;

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

        $this->caissier = new Utilisateur('fatou@test.ci', 'Fatou');
        $this->caissier->setRoles(['ROLE_CAISSIER'])->setCodePin('x');
        $this->em->persist($this->caissier);

        $famille = new FamilleProduit('Pains');
        $this->em->persist($famille);

        $article = new Article('Baguette', 15000, 'pièce'); // 150 FCFA, TVA 0
        $article->setFamilleProduit($famille)->setTauxTva(0);
        $this->em->persist($article);

        $this->em->flush();
        $this->articleId = $article->getId();

        static::getContainer()->get(SessionCaisseService::class)->ouvrir($this->caissier, 0);
        $this->client->loginUser($this->caissier);
    }

    /**
     * Charge d'une vente telle que la file la stocke en IndexedDB.
     *
     * @return array<string, mixed>
     */
    private function charge(string $uuid, int $quantite): array
    {
        return [
            'uuid' => $uuid,
            'mode' => 'BOULANGERIE',
            'lignes' => [['articleId' => $this->articleId, 'quantite' => $quantite, 'commentaire' => '']],
            'reglements' => [['mode' => 'ESPECES', 'montant' => 15000 * $quantite]],
        ];
    }

    /**
     * @param array<string, mixed> $charge
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function transmettre(array $charge): array
    {
        $this->client->request('POST', '/api/vente', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($charge));
        $reponse = $this->client->getResponse();

        return [$reponse->getStatusCode(), json_decode($reponse->getContent(), true) ?? []];
    }

    private function ventes(): VenteRepository
    {
        return static::getContainer()->get(VenteRepository::class);
    }

    /**
     * 20 ventes accumulées hors ligne, transmises d'un bloc à la reconnexion.
     */
    public function testVingtVentesHorsLignePuisReconnexion(): void
    {
        // Hors ligne : la caisse a généré 20 uuid et 20 charges. Rien n'est parti.
        $charges = [];
        for ($i = 1; $i <= self::NOMBRE_VENTES; ++$i) {
            $charges[] = $this->charge((string) Uuid::v4(), $i);
        }
        $this->assertCount(0, $this->ventes()->findAll(), 'Aucune vente enregistrée hors ligne.');

        // Reconnexion : la file se vide.
        $numeros = [];
        foreach ($charges as $charge) {
            [$code, $donnees] = $this->transmettre($charge);

            $this->assertSame(201, $code, 'Chaque vente de la file est créée.');
            $this->assertTrue($donnees['ok']);
            $numeros[] = $donnees['numero'];
        }

        // Aucune perte : les 20 ventes existent.
        $this->em->clear();
        $this->assertCount(self::NOMBRE_VENTES, $this->ventes()->findAll());

        // Aucun doublon : 20 numéros de ticket distincts.
        $this->assertCount(self::NOMBRE_VENTES, array_unique($numeros));

        // Le total encaissé correspond exactement à la somme des tickets.
        $attendu = 0;
        for ($i = 1; $i <= self::NOMBRE_VENTES; ++$i) {
            $attendu += 15000 * $i;
        }
        $this->assertSame($attendu, $this->totalEncaisse());
    }

    /**
     * Cas le plus dangereux : le serveur enregistre, mais la réponse n'atteint
     * jamais la caisse. La file rejoue tout — l'idempotence doit absorber.
     */
    public function testRejeuIntegralNeCreeAucunDoublon(): void
    {
        $charges = [];
        for ($i = 1; $i <= self::NOMBRE_VENTES; ++$i) {
            $charges[] = $this->charge((string) Uuid::v4(), $i);
        }

        // Premier passage : tout est créé (mais la caisse n'a rien reçu).
        $premiers = [];
        foreach ($charges as $charge) {
            [$code, $donnees] = $this->transmettre($charge);
            $this->assertSame(201, $code);
            $premiers[$donnees['uuid']] = $donnees['numero'];
        }

        // Rejeu complet de la file, à l'identique.
        foreach ($charges as $charge) {
            [$code, $donnees] = $this->transmettre($charge);

            $this->assertSame(200, $code, 'Un rejeu renvoie 200, pas 201.');
            $this->assertSame($premiers[$donnees['uuid']], $donnees['numero'], 'Le même ticket est renvoyé.');
        }

        // Et un troisième passage, pour la route.
        foreach ($charges as $charge) {
            [$code] = $this->transmettre($charge);
            $this->assertSame(200, $code);
        }

        $this->em->clear();
        $this->assertCount(self::NOMBRE_VENTES, $this->ventes()->findAll(), 'Toujours 20 ventes après 60 requêtes.');
        $this->assertSame(self::NOMBRE_VENTES, $this->nombreNumerosDistincts());
    }

    /**
     * Vidage partiel : la connexion retombe au milieu de la file, puis revient.
     * Les ventes déjà transmises sont rejouées, les autres créées.
     */
    public function testReprisePartielleApresSecondeCoupure(): void
    {
        $charges = [];
        for ($i = 1; $i <= self::NOMBRE_VENTES; ++$i) {
            $charges[] = $this->charge((string) Uuid::v4(), $i);
        }

        // Première fenêtre de connexion : 8 ventes passent.
        foreach (\array_slice($charges, 0, 8) as $charge) {
            [$code] = $this->transmettre($charge);
            $this->assertSame(201, $code);
        }

        // Coupure. La file conserve les 20 entrées : les 8 premières n'ont pas
        // été confirmées côté caisse (réponses perdues), les 12 autres non plus.
        // Seconde fenêtre : la file rejoue tout depuis le début.
        $codes = [];
        foreach ($charges as $charge) {
            [$code] = $this->transmettre($charge);
            $codes[] = $code;
        }

        $this->assertSame(8, \count(array_filter($codes, static fn (int $c): bool => 200 === $c)), '8 rejeux.');
        $this->assertSame(12, \count(array_filter($codes, static fn (int $c): bool => 201 === $c)), '12 créations.');

        $this->em->clear();
        $this->assertCount(self::NOMBRE_VENTES, $this->ventes()->findAll());
        $this->assertSame(self::NOMBRE_VENTES, $this->nombreNumerosDistincts());
    }

    /**
     * Une vente refusée pour un motif métier ne crée rien et n'empêche pas les
     * suivantes de passer : la file la met de côté et poursuit son vidage.
     */
    public function testUneVenteRefuseeNeBloquePasLaFile(): void
    {
        $bonnes = [$this->charge((string) Uuid::v4(), 1), $this->charge((string) Uuid::v4(), 2)];

        // Vente portant un article inexistant (catalogue modifié depuis la coupure).
        $mauvaise = $this->charge((string) Uuid::v4(), 1);
        $mauvaise['lignes'][0]['articleId'] = 999999;

        [$code1] = $this->transmettre($bonnes[0]);
        [$codeRefus, $refus] = $this->transmettre($mauvaise);
        [$code2] = $this->transmettre($bonnes[1]);

        $this->assertSame(201, $code1);
        $this->assertSame(400, $codeRefus, 'Refus définitif : la file marquera l\'entrée « bloquée ».');
        $this->assertFalse($refus['ok']);
        $this->assertSame(201, $code2, 'La vente suivante passe normalement.');

        $this->em->clear();
        $this->assertCount(2, $this->ventes()->findAll(), 'Seules les ventes valides sont enregistrées.');
    }

    /**
     * Le catalogue servi à la caisse pour son cache hors ligne.
     */
    public function testCatalogueJsonPourLeCacheHorsLigne(): void
    {
        $this->client->request('GET', '/caisse/catalogue.json');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/json');

        $donnees = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('genereA', $donnees);
        $this->assertCount(1, $donnees['familles']);
        $this->assertSame('Pains', $donnees['familles'][0]['nom']);

        $article = $donnees['familles'][0]['articles'][0];
        $this->assertSame('Baguette', $article['nom']);
        $this->assertSame(15000, $article['prix'], 'Prix en centimes, comme partout ailleurs.');
        $this->assertSame($this->articleId, $article['id']);
    }

    public function testCatalogueRefuseAuxNonCaissiers(): void
    {
        $comptable = new Utilisateur('compta@test.ci', 'Comptable');
        $comptable->setRoles(['ROLE_COMPTABLE'])->setMotDePasse('x');
        $this->em->persist($comptable);
        $this->em->flush();

        $this->client->loginUser($comptable);
        $this->client->request('GET', '/caisse/catalogue.json');

        $this->assertResponseStatusCodeSame(403);
    }

    /**
     * Le Service Worker doit être servi à la racine : c'est ce qui lui donne une
     * portée couvrant /caisse.
     */
    public function testServiceWorkerPresentALaRacine(): void
    {
        $chemin = \dirname(__DIR__, 2).'/public/sw.js';

        $this->assertFileExists($chemin, 'Le Service Worker doit être servi depuis /sw.js.');

        $source = file_get_contents($chemin);
        $this->assertStringContainsString("requete.method !== 'GET'", $source, 'Les POST ne doivent jamais être interceptés.');
    }

    private function totalEncaisse(): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            "SELECT COALESCE(SUM(total_ttc), 0) FROM vente WHERE statut = 'VALIDEE'",
        );
    }

    private function nombreNumerosDistincts(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(DISTINCT numero) FROM vente');
    }
}
