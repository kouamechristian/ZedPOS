<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\FamilleProduit;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Enum\StatutVente;
use App\Repository\NotificationRepository;
use App\Repository\VenteRepository;
use App\Service\SessionCaisseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class VenteApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Utilisateur $caissier;
    private Utilisateur $gerant;
    private int $articleA; // 1000 FCFA, TVA 0
    private int $articleB; // 500 FCFA, TVA 18 %

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

        $this->caissier = new Utilisateur('caissier@test.ci', 'Caissier');
        $this->caissier->setRoles(['ROLE_CAISSIER'])->setCodePin('x');
        $this->em->persist($this->caissier);

        $this->gerant = new Utilisateur('gerant@test.ci', 'Gérant');
        $this->gerant->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        $this->em->persist($this->gerant);

        $famille = new FamilleProduit('Divers');
        $this->em->persist($famille);

        $a = new Article('Article A', 100000, 'pièce');
        $a->setFamilleProduit($famille)->setTauxTva(0);
        $this->em->persist($a);

        $b = new Article('Article B', 50000, 'pièce');
        $b->setFamilleProduit($famille)->setTauxTva(1800);
        $this->em->persist($b);

        $this->em->flush();
        $this->articleA = $a->getId();
        $this->articleB = $b->getId();

        // Une vente exige une session ouverte pour l'encaisseur (caissier ou gérant).
        $sessions = static::getContainer()->get(SessionCaisseService::class);
        $sessions->ouvrir($this->caissier, 3000000);
        // Hors service : le service n'en laisse ouvrir qu'une à la fois.
        $this->em->persist(new \App\Entity\SessionCaisse($this->gerant, 3000000));
        $this->em->flush();
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function poster(string $url, array $charge): array
    {
        $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($charge));
        $reponse = $this->client->getResponse();

        return [$reponse->getStatusCode(), json_decode($reponse->getContent(), true)];
    }

    private function ventes(): VenteRepository
    {
        return static::getContainer()->get(VenteRepository::class);
    }

    public function testVenteSimple(): void
    {
        $this->client->loginUser($this->caissier);
        [$code, $data] = $this->poster('/api/vente', [
            'uuid' => (string) Uuid::v4(),
            'mode' => 'BOULANGERIE',
            'lignes' => [['articleId' => $this->articleA, 'quantite' => 2]],
            'reglements' => [['mode' => 'ESPECES', 'montant' => 200000]],
        ]);

        $this->assertSame(201, $code);
        $this->assertTrue($data['ok']);
        $this->assertSame(200000, $data['totalTtc']);
        $this->assertSame(0, $data['rendu']);

        $vente = $this->ventes()->findOneBy(['numero' => $data['numero']]);
        $this->assertInstanceOf(Vente::class, $vente);
        $this->assertSame(StatutVente::VALIDEE, $vente->getStatut());
        $this->assertCount(1, $vente->getLignes());
        $this->assertCount(1, $vente->getReglements());
    }

    public function testRenduDeMonnaie(): void
    {
        $this->client->loginUser($this->caissier);
        [$code, $data] = $this->poster('/api/vente', [
            'uuid' => (string) Uuid::v4(),
            'mode' => 'BOULANGERIE',
            'lignes' => [['articleId' => $this->articleA, 'quantite' => 2]], // 200 000
            'reglements' => [['mode' => 'ESPECES', 'montant' => 250000]],
        ]);

        $this->assertSame(201, $code);
        $this->assertSame(50000, $data['rendu']); // 2500 − 2000 = 500 FCFA
    }

    public function testPaiementMixte(): void
    {
        $this->client->loginUser($this->caissier);
        [$code, $data] = $this->poster('/api/vente', [
            'uuid' => (string) Uuid::v4(),
            'mode' => 'FASTFOOD',
            'lignes' => [
                ['articleId' => $this->articleA, 'quantite' => 1], // 1000, TVA 0
                ['articleId' => $this->articleB, 'quantite' => 1], // 500, TVA 18 %
            ],
            'reglements' => [
                ['mode' => 'WAVE', 'montant' => 100000, 'reference' => 'WAVE-1'],
                ['mode' => 'ESPECES', 'montant' => 50000],
            ],
        ]);

        $this->assertSame(201, $code);
        $this->assertSame(150000, $data['totalTtc']);
        $this->assertSame(142372, $data['totalHt']);
        $this->assertSame(7628, $data['totalTva']);
        $this->assertSame(0, $data['rendu']);

        $vente = $this->ventes()->findOneBy(['numero' => $data['numero']]);
        $this->assertCount(2, $vente->getReglements());
    }

    public function testPaiementInsuffisantRefuse(): void
    {
        $this->client->loginUser($this->caissier);
        [$code, $data] = $this->poster('/api/vente', [
            'uuid' => (string) Uuid::v4(),
            'mode' => 'BOULANGERIE',
            'lignes' => [['articleId' => $this->articleA, 'quantite' => 2]], // 200 000
            'reglements' => [['mode' => 'ESPECES', 'montant' => 150000]],
        ]);

        $this->assertSame(400, $code);
        $this->assertFalse($data['ok']);
    }

    public function testIdempotenceSurUuid(): void
    {
        $this->client->loginUser($this->caissier);
        $charge = [
            'uuid' => (string) Uuid::v4(),
            'mode' => 'BOULANGERIE',
            'lignes' => [['articleId' => $this->articleA, 'quantite' => 1]],
            'reglements' => [['mode' => 'ESPECES', 'montant' => 100000]],
        ];

        [$code1, $data1] = $this->poster('/api/vente', $charge);
        [$code2, $data2] = $this->poster('/api/vente', $charge);

        $this->assertSame(201, $code1);
        $this->assertSame(200, $code2, 'La deuxième requête est un rejeu idempotent.');
        $this->assertSame($data1['numero'], $data2['numero']);
        $this->assertCount(1, $this->ventes()->findAll(), 'Aucun doublon ne doit être créé.');
    }

    public function testRemiseRefuseePourUnCaissier(): void
    {
        $this->client->loginUser($this->caissier);
        [$code, $data] = $this->poster('/api/vente', [
            'uuid' => (string) Uuid::v4(),
            'mode' => 'BOULANGERIE',
            'lignes' => [['articleId' => $this->articleA, 'quantite' => 2]],
            'remise' => ['type' => 'POURCENTAGE', 'valeur' => 10],
            'reglements' => [['mode' => 'ESPECES', 'montant' => 180000]],
        ]);

        $this->assertSame(403, $code);
        $this->assertFalse($data['ok']);
    }

    public function testRemiseGerantAvecMotifObligatoire(): void
    {
        $this->client->loginUser($this->gerant);
        $base = [
            'mode' => 'BOULANGERIE',
            'lignes' => [['articleId' => $this->articleA, 'quantite' => 6]], // brut 600 000
            'remise' => ['type' => 'POURCENTAGE', 'valeur' => 10],           // 60 000 = 600 FCFA > 500
            'reglements' => [['mode' => 'ESPECES', 'montant' => 540000]],
        ];

        // Sans motif : refusé (remise > 500 FCFA).
        [$codeSans] = $this->poster('/api/vente', ['uuid' => (string) Uuid::v4()] + $base);
        $this->assertSame(400, $codeSans);

        // Avec motif : accepté, total remisé.
        $base['remise']['motif'] = 'Client fidèle';
        [$codeAvec, $data] = $this->poster('/api/vente', ['uuid' => (string) Uuid::v4()] + $base);
        $this->assertSame(201, $codeAvec);
        $this->assertSame(60000, $data['remise']);
        $this->assertSame(540000, $data['totalTtc']);
    }

    public function testRemiseAuDelaDuPlafondGerantRefusee(): void
    {
        $this->client->loginUser($this->gerant);
        [$code] = $this->poster('/api/vente', [
            'uuid' => (string) Uuid::v4(),
            'mode' => 'BOULANGERIE',
            'lignes' => [['articleId' => $this->articleA, 'quantite' => 2]],
            'remise' => ['type' => 'POURCENTAGE', 'valeur' => 11], // > plafond 10 %
            'reglements' => [['mode' => 'ESPECES', 'montant' => 178000]],
        ]);

        $this->assertSame(403, $code);
    }

    public function testAnnulationParGerant(): void
    {
        $this->client->loginUser($this->caissier);
        [, $data] = $this->poster('/api/vente', [
            'uuid' => (string) Uuid::v4(),
            'mode' => 'BOULANGERIE',
            'lignes' => [['articleId' => $this->articleA, 'quantite' => 1]],
            'reglements' => [['mode' => 'ESPECES', 'montant' => 100000]],
        ]);
        $uuid = $data['uuid'];

        $this->client->loginUser($this->gerant);
        [$code, $annulation] = $this->poster('/api/vente/'.$uuid.'/annuler', ['motif' => 'Erreur de saisie']);

        $this->assertSame(200, $code);
        $this->assertSame('ANNULEE', $annulation['statut']);

        // La vente existe toujours (jamais supprimée) et porte le motif.
        $this->em->clear();
        $vente = $this->ventes()->findOneBy(['uuid' => Uuid::fromString($uuid)]);
        $this->assertInstanceOf(Vente::class, $vente);
        $this->assertSame(StatutVente::ANNULEE, $vente->getStatut());
        $this->assertSame('Erreur de saisie', $vente->getMotifAnnulation());
    }

    /**
     * @return array<string, mixed> La vente créée, telle que renvoyée par l'API
     */
    private function encaisser(): array
    {
        [, $data] = $this->poster('/api/vente', [
            'uuid' => (string) Uuid::v4(),
            'mode' => 'BOULANGERIE',
            'lignes' => [['articleId' => $this->articleA, 'quantite' => 1]],
            'reglements' => [['mode' => 'ESPECES', 'montant' => 100000]],
        ]);

        return $data;
    }

    /**
     * Ticket corrigé : Article A seul devient A + B, payé 2 000 FCFA en espèces.
     *
     * @return array<string, mixed>
     */
    private function correction(?string $uuid = null): array
    {
        return [
            'uuid' => $uuid ?? (string) Uuid::v4(),
            'mode' => 'BOULANGERIE',
            'lignes' => [
                ['articleId' => $this->articleA, 'quantite' => 1],
                ['articleId' => $this->articleB, 'quantite' => 1],
            ],
            'reglements' => [['mode' => 'ESPECES', 'montant' => 200000]],
        ];
    }

    /**
     * Le caissier n'annule plus : c'est le gérant qui annule. Même son propre
     * dernier ticket lui est refusé.
     */
    public function testLeCaissierNAnnulePlusSonTicket(): void
    {
        $this->client->loginUser($this->caissier);
        $data = $this->encaisser();

        [$code] = $this->poster('/api/vente/'.$data['uuid'].'/annuler', ['motif' => 'Erreur de saisie']);
        $this->assertSame(403, $code);

        $this->em->clear();
        $this->assertSame(StatutVente::VALIDEE, $this->ventes()->findOneBy(['uuid' => Uuid::fromString($data['uuid'])])->getStatut());
    }

    /**
     * Il modifie en revanche le ticket qu'il vient d'encaisser : l'original est
     * annulé, jamais supprimé, et le remplaçant est recalculé côté serveur dans
     * la même session. Tracé et notifié à la dirigeante.
     */
    public function testLeCaissierModifieLeTicketQuIlVientDEncaisser(): void
    {
        $this->client->loginUser($this->caissier);
        $data = $this->encaisser();

        [$code, $modifie] = $this->poster('/api/vente/'.$data['uuid'].'/modifier', $this->correction());

        $this->assertSame(201, $code);
        $this->assertSame(150000, $modifie['totalTtc'], 'Prix recalculés côté serveur.');
        $this->assertSame(50000, $modifie['rendu']);
        $this->assertNotSame($data['numero'], $modifie['numero'], 'Le remplaçant a son propre numéro.');

        $this->em->clear();
        $originale = $this->ventes()->findOneBy(['uuid' => Uuid::fromString($data['uuid'])]);
        $remplacante = $this->ventes()->findOneBy(['uuid' => Uuid::fromString($modifie['uuid'])]);

        $this->assertSame(StatutVente::ANNULEE, $originale->getStatut(), 'Annulé, jamais supprimé.');
        $this->assertStringContainsString($modifie['numero'], (string) $originale->getMotifAnnulation());
        $this->assertSame(StatutVente::VALIDEE, $remplacante->getStatut());
        $this->assertSame($originale->getId(), $remplacante->getVenteRemplacee()?->getId());
        $this->assertSame($originale->getSessionCaisse()->getId(), $remplacante->getSessionCaisse()->getId());
        $this->assertCount(2, $remplacante->getLignes());

        $connexion = $this->em->getConnection();
        $this->assertSame(1, (int) $connexion->fetchOne("SELECT COUNT(*) FROM journal_audit WHERE action = 'VENTE_MODIFIEE'"));
        $this->assertSame(1, (int) $connexion->fetchOne("SELECT COUNT(*) FROM notification WHERE type = 'VENTE_MODIFIEE'"));
    }

    /**
     * La dirigeante lit une modification en « avant → après » : les deux montants,
     * l'écart, les deux numéros — et, sur la fiche, l'article retiré. Un ticket qui
     * baisse est ce qu'elle vient chercher.
     */
    public function testLaDirigeanteLitLaModificationEnAvantApres(): void
    {
        $this->client->loginUser($this->caissier);
        [, $data] = $this->poster('/api/vente', [
            'uuid' => (string) Uuid::v4(),
            'mode' => 'BOULANGERIE',
            'lignes' => [
                ['articleId' => $this->articleA, 'quantite' => 2],
                ['articleId' => $this->articleB, 'quantite' => 1],
            ],
            'reglements' => [['mode' => 'ESPECES', 'montant' => 250000]],
        ]);
        [$code, $modifie] = $this->poster('/api/vente/'.$data['uuid'].'/modifier', [
            'uuid' => (string) Uuid::v4(),
            'mode' => 'BOULANGERIE',
            'lignes' => [['articleId' => $this->articleA, 'quantite' => 1]],
            'reglements' => [['mode' => 'ESPECES', 'montant' => 100000]],
        ]);
        $this->assertSame(201, $code);

        $notification = static::getContainer()->get(NotificationRepository::class)->nonLuesPour('ROLE_DIRIGEANTE')[0];
        $this->assertSame($modifie['uuid'], (string) $notification->getVente()?->getUuid(), 'Rattachée au remplaçant.');

        $dirigeante = new Utilisateur('dirigeante@test.ci', 'Dirigeante');
        $dirigeante->setRoles(['ROLE_DIRIGEANTE'])->setMotDePasse('x');
        $this->em->persist($dirigeante);
        $this->em->flush();
        $this->client->loginUser($dirigeante);

        $crawler = $this->client->request('GET', '/pilotage');
        $this->assertResponseIsSuccessful();
        $alerte = $crawler->filter('[aria-labelledby="titre-alertes"]')->text();
        foreach (['Ticket modifié', '2 500 FCFA', '1 000 FCFA', '− 1 500 FCFA', $data['numero'], $modifie['numero'], 'Voir avant / après'] as $attendu) {
            $this->assertStringContainsString($attendu, $alerte);
        }
        $this->assertCount(0, $crawler->filter('.alerte-rouge'), 'Une modification n\'est pas affichée comme une annulation.');

        $crawler = $this->client->request('GET', '/pilotage/ventes/'.$modifie['uuid']);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Ticket baissé de 1 500 FCFA');
        $this->assertSelectorTextContains('body', 'retiré');
        $this->assertCount(1, $crawler->filter('a[href="/pilotage/ventes/'.$data['uuid'].'"]'), 'L\'avant mène à l\'original.');

        $crawler = $this->client->request('GET', '/pilotage/ventes/'.$data['uuid']);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Remplacée');
        $this->assertCount(1, $crawler->filter('a[href="/pilotage/ventes/'.$modifie['uuid'].'"]'), 'L\'original mène au remplaçant.');
    }

    /**
     * Une modification, une seule : ni le remplaçant, ni l'original déjà repris
     * ne se modifient à nouveau.
     */
    public function testUnTicketNeSeModifieQuUneFois(): void
    {
        $this->client->loginUser($this->caissier);
        $data = $this->encaisser();
        [, $modifie] = $this->poster('/api/vente/'.$data['uuid'].'/modifier', $this->correction());

        [$code] = $this->poster('/api/vente/'.$modifie['uuid'].'/modifier', $this->correction());
        $this->assertSame(403, $code, 'Le ticket né de la modification ne se modifie plus.');

        [$code] = $this->poster('/api/vente/'.$data['uuid'].'/modifier', $this->correction());
        $this->assertSame(403, $code, "L'original déjà repris ne se modifie plus.");

        $this->assertSame(2, $this->ventes()->count([]), 'Aucune vente de plus.');
    }

    /**
     * Original et remplaçant partent dans le même flush : les sorties de l'un
     * sont inversées pendant que l'autre est déstocké, sans rien compter deux fois.
     */
    public function testLaModificationRectifieLeStock(): void
    {
        $b = $this->em->getRepository(Article::class)->find($this->articleB);
        $b->setSuiviStock(true)->setStockActuel(10000); // 10 pièces
        $this->em->flush();

        $this->client->loginUser($this->caissier);
        [, $data] = $this->poster('/api/vente', [
            'uuid' => (string) Uuid::v4(),
            'mode' => 'BOULANGERIE',
            'lignes' => [['articleId' => $this->articleB, 'quantite' => 3]],
            'reglements' => [['mode' => 'ESPECES', 'montant' => 150000]],
        ]);
        [$code] = $this->poster('/api/vente/'.$data['uuid'].'/modifier', [
            'uuid' => (string) Uuid::v4(),
            'mode' => 'BOULANGERIE',
            'lignes' => [['articleId' => $this->articleB, 'quantite' => 1]],
            'reglements' => [['mode' => 'ESPECES', 'montant' => 50000]],
        ]);
        $this->assertSame(201, $code);

        $this->em->clear();
        $this->assertSame(9000, $this->em->getRepository(Article::class)->find($this->articleB)->getStockActuel(), '10 − 3 + 3 − 1 = 9');
    }

    /** Réponse perdue en route : le même essai se rejoue sans créer de doublon. */
    public function testLaModificationSeRejoueSansDoublon(): void
    {
        $this->client->loginUser($this->caissier);
        $data = $this->encaisser();
        $correction = $this->correction();

        [$code, $premier] = $this->poster('/api/vente/'.$data['uuid'].'/modifier', $correction);
        $this->assertSame(201, $code);

        [$code, $rejeu] = $this->poster('/api/vente/'.$data['uuid'].'/modifier', $correction);
        $this->assertSame(200, $code);
        $this->assertSame($premier['numero'], $rejeu['numero']);
        $this->assertSame(2, $this->ventes()->count([]));
    }

    /**
     * Le dernier ticket seulement : dès qu'une vente suivante est encaissée, la
     * précédente redevient l'affaire du gérant. Sans cette borne, un caissier
     * pourrait remonter sa journée et effacer ses écarts au fil de l'eau — le Z
     * ne signalerait plus rien.
     */
    public function testLeCaissierNeModifiePlusUnTicketQuUneAutreVenteADepasse(): void
    {
        $this->client->loginUser($this->caissier);
        $premier = $this->encaisser();
        $second = $this->encaisser();

        [$code] = $this->poster('/api/vente/'.$premier['uuid'].'/modifier', $this->correction());
        $this->assertSame(403, $code, 'Le ticket a été dépassé par un autre.');

        [$code] = $this->poster('/api/vente/'.$second['uuid'].'/modifier', $this->correction());
        $this->assertSame(201, $code);
    }

    /**
     * Après le Z, la journée est arrêtée. Le refus vient de l'habilitation (403),
     * pas de l'exception métier levée plus loin — une porte fermée vaut mieux
     * qu'une porte qui casse au passage.
     */
    public function testLeCaissierNeModifiePlusRienUneFoisSaCaisseCloturee(): void
    {
        $this->client->loginUser($this->caissier);
        $data = $this->encaisser();

        $sessions = static::getContainer()->get(SessionCaisseService::class);
        $session = $sessions->exigerSessionOuverte($this->caissier);
        $sessions->cloturer($session, 3100000);

        [$code] = $this->poster('/api/vente/'.$data['uuid'].'/modifier', $this->correction());
        $this->assertSame(403, $code);
    }

    /** Un ticket corrigé reste un ticket : vide ou mal réglé, il est refusé et l'original reste valide. */
    public function testUneModificationInvalideLaisseLeTicketIntact(): void
    {
        $this->client->loginUser($this->caissier);
        $data = $this->encaisser();

        $correction = $this->correction();
        $correction['reglements'] = [['mode' => 'ESPECES', 'montant' => 100000]]; // < 1 500 FCFA
        [$code] = $this->poster('/api/vente/'.$data['uuid'].'/modifier', $correction);
        $this->assertSame(400, $code);

        $this->em->clear();
        $this->assertSame(StatutVente::VALIDEE, $this->ventes()->findOneBy(['uuid' => Uuid::fromString($data['uuid'])])->getStatut());
        $this->assertSame(1, $this->ventes()->count([]));
    }

    public function testAnnulationMotifObligatoire(): void
    {
        $this->client->loginUser($this->caissier);
        [, $data] = $this->poster('/api/vente', [
            'uuid' => (string) Uuid::v4(),
            'mode' => 'BOULANGERIE',
            'lignes' => [['articleId' => $this->articleA, 'quantite' => 1]],
            'reglements' => [['mode' => 'ESPECES', 'montant' => 100000]],
        ]);

        $this->client->loginUser($this->gerant);
        [$code] = $this->poster('/api/vente/'.$data['uuid'].'/annuler', ['motif' => '']);
        $this->assertSame(400, $code);
    }
}
