<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\FamilleProduit;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Repository\VenteRepository;
use Doctrine\ORM\EntityManagerInterface;
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
        foreach (['ligne_fiche_technique', 'fiche_technique', 'ligne_vente', 'reglement', 'vente', 'session_caisse', 'mouvement_stock', 'perte', 'article', 'matiere_premiere', 'fournisseur', 'famille_produit', 'journal_audit', 'utilisateur'] as $table) {
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

        $this->client->loginUser($caissier);
    }

    private function jeton(): string
    {
        $crawler = $this->client->request('GET', '/caisse');
        return $crawler->filter('[data-controller="caisse"]')->attr('data-caisse-token-value');
    }

    private function encaisser(array $charge): array
    {
        $this->client->request('POST', '/caisse/encaisser', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($charge));

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    public function testPageCaisseAfficheLeCatalogue(): void
    {
        $this->client->request('GET', '/caisse');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Baguette');
        $this->assertSelectorExists('[data-controller="caisse"]');
    }

    public function testEncaissementCreeUneVente(): void
    {
        $reponse = $this->encaisser([
            'mode' => 'BOULANGERIE',
            'reglementMode' => 'ESPECES',
            '_token' => $this->jeton(),
            'lignes' => [['articleId' => $this->articleId, 'quantite' => 2, 'commentaire' => '']],
        ]);

        $this->assertTrue($reponse['ok'], 'Encaissement attendu réussi.');
        $this->assertSame(30000, $reponse['total']); // 2 × 150 FCFA

        $vente = static::getContainer()->get(VenteRepository::class)->findOneBy(['numero' => $reponse['numero']]);
        $this->assertInstanceOf(Vente::class, $vente);
        $this->assertSame(30000, $vente->getTotalTtc());
        $this->assertCount(1, $vente->getLignes());
        $this->assertSame(2000, $vente->getLignes()->first()->getQuantite()); // 2 unités en millièmes
        $this->assertCount(1, $vente->getReglements());
        $this->assertSame(30000, $vente->getReglements()->first()->getMontant());
    }

    public function testEncaissementRefuseUnJetonInvalide(): void
    {
        $reponse = $this->encaisser([
            'mode' => 'BOULANGERIE',
            'reglementMode' => 'ESPECES',
            '_token' => 'faux-jeton',
            'lignes' => [['articleId' => $this->articleId, 'quantite' => 1]],
        ]);

        $this->assertResponseStatusCodeSame(403);
        $this->assertFalse($reponse['ok']);
    }

    public function testEncaissementRefuseUnTicketVide(): void
    {
        $reponse = $this->encaisser([
            'mode' => 'BOULANGERIE',
            'reglementMode' => 'ESPECES',
            '_token' => $this->jeton(),
            'lignes' => [],
        ]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertFalse($reponse['ok']);
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
