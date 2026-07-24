<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\FamilleProduit;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Enum\StatutVente;
use App\Repository\VenteRepository;
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
        foreach (['ligne_fiche_technique', 'fiche_technique', 'ligne_vente', 'reglement', 'vente', 'session_caisse', 'mouvement_stock', 'perte', 'article', 'matiere_premiere', 'fournisseur', 'famille_produit', 'journal_audit', 'utilisateur'] as $table) {
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

    public function testAnnulationRefuseePourUnCaissier(): void
    {
        $this->client->loginUser($this->caissier);
        [, $data] = $this->poster('/api/vente', [
            'uuid' => (string) Uuid::v4(),
            'mode' => 'BOULANGERIE',
            'lignes' => [['articleId' => $this->articleA, 'quantite' => 1]],
            'reglements' => [['mode' => 'ESPECES', 'montant' => 100000]],
        ]);

        [$code] = $this->poster('/api/vente/'.$data['uuid'].'/annuler', ['motif' => 'Test']);
        $this->assertSame(403, $code);
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
