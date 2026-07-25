<?php

namespace App\Tests\Functional;

use App\Entity\MatierePremiere;
use App\Entity\Utilisateur;
use App\Service\AlerteStockService;
use App\Service\SessionCaisseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AlerteStockTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

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

        $gerant = new Utilisateur('gerant@test.ci', 'Gérant');
        $gerant->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        $this->em->persist($gerant);

        // Sous le seuil : stock 5 kg < seuil 50 kg.
        $this->em->persist((new MatierePremiere('Levure', 'kg'))->setStockActuel(5000)->setStockMini(50000));
        // Au-dessus du seuil.
        $this->em->persist((new MatierePremiere('Sucre', 'kg'))->setStockActuel(80000)->setStockMini(20000));

        $this->em->flush();

        // L'écran de caisse exige une session ouverte pour s'afficher.
        static::getContainer()->get(SessionCaisseService::class)->ouvrir($gerant, 0);

        $this->client->loginUser($gerant);
    }

    public function testServiceListeUniquementLesMatieresSousSeuil(): void
    {
        $alertes = static::getContainer()->get(AlerteStockService::class);
        $sousSeuil = $alertes->matieresSousSeuil();

        $this->assertCount(1, $sousSeuil);
        $this->assertSame('Levure', $sousSeuil[0]->getNom());
    }

    public function testBandeauAfficheDansLeBackOffice(): void
    {
        $this->client->request('GET', '/admin');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Stock bas');
        $this->assertSelectorTextContains('body', 'Levure');
    }

    public function testBandeauAfficheEnCaisse(): void
    {
        $this->client->request('GET', '/caisse');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Stock bas');
    }
}
