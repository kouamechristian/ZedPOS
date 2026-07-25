<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\FamilleProduit;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Repository\VenteRepository;
use App\Service\SessionCaisseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CaisseTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
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

        $caissier = new Utilisateur('caissier@test.ci', 'Caissier Test');
        $caissier->setRoles(['ROLE_CAISSIER'])->setCodePin('x');
        $this->em->persist($caissier);

        $famille = (new FamilleProduit('Pains'))->setActif(true);
        $this->em->persist($famille);

        $article = new Article('Baguette', 15000, 'pièce'); // 150 FCFA, TVA 0
        $article->setFamilleProduit($famille)->setActif(true)->setTauxTva(0);
        $this->em->persist($article);

        $this->em->flush();
        $this->articleId = $article->getId();

        // Aucune vente n'est possible sans session de caisse ouverte.
        static::getContainer()->get(SessionCaisseService::class)->ouvrir($caissier, 3000000);

        $this->client->loginUser($caissier);
    }

    /**
     * L'écran de caisse enregistre ses ventes via POST /api/vente, seul point
     * d'entrée idempotent (il n'existe plus de second chemin d'écriture).
     */
    private function encaisser(array $charge): array
    {
        $this->client->request('POST', '/api/vente', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($charge));

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    public function testPageCaisseAfficheLeCatalogue(): void
    {
        $this->client->request('GET', '/caisse');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Baguette');
        $this->assertSelectorExists('[data-controller="caisse"]');
    }

    public function testBandeauDeSynchronisationPresent(): void
    {
        $this->client->request('GET', '/caisse');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-controller="synchronisation"]', "Le bandeau d'état est permanent.");
    }

    public function testEncaissementCreeUneVente(): void
    {
        $reponse = $this->encaisser([
            'uuid' => (string) Uuid::v4(),
            'mode' => 'BOULANGERIE',
            'lignes' => [['articleId' => $this->articleId, 'quantite' => 2, 'commentaire' => '']],
            'reglements' => [['mode' => 'ESPECES', 'montant' => 30000]],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertTrue($reponse['ok'], 'Encaissement attendu réussi.');
        $this->assertSame(30000, $reponse['totalTtc']); // 2 × 150 FCFA

        $vente = static::getContainer()->get(VenteRepository::class)->findOneBy(['numero' => $reponse['numero']]);
        $this->assertInstanceOf(Vente::class, $vente);
        $this->assertSame(30000, $vente->getTotalTtc());
        $this->assertCount(1, $vente->getLignes());
        $this->assertSame(2000, $vente->getLignes()->first()->getQuantite()); // 2 unités en millièmes
        $this->assertCount(1, $vente->getReglements());
        $this->assertSame(30000, $vente->getReglements()->first()->getMontant());
    }

    public function testEncaissementRefuseUnTicketVide(): void
    {
        $reponse = $this->encaisser([
            'uuid' => (string) Uuid::v4(),
            'mode' => 'BOULANGERIE',
            'lignes' => [],
            'reglements' => [['mode' => 'ESPECES', 'montant' => 30000]],
        ]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertFalse($reponse['ok']);
    }

    public function testAncienPointDEntreeNonIdempotentSupprime(): void
    {
        // Un second chemin d'écriture ruinerait la garantie « aucun doublon ».
        $this->client->request('POST', '/caisse/encaisser', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testCaisseInaccessibleSansRoleCaissier(): void
    {
        $comptable = new Utilisateur('compta@test.ci', 'Comptable Test');
        $comptable->setRoles(['ROLE_COMPTABLE'])->setMotDePasse('x');
        $this->em->persist($comptable);
        $this->em->flush();

        $this->client->loginUser($comptable);
        $this->client->request('GET', '/caisse');
        $this->assertResponseStatusCodeSame(403);
    }
}
