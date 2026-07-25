<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\FamilleProduit;
use App\Entity\MatierePremiere;
use App\Entity\Perte;
use App\Entity\Utilisateur;
use App\Enum\MotifPerte;
use App\Enum\TypeMouvementStock;
use App\Repository\MouvementStockRepository;
use App\Repository\PerteRepository;
use App\Service\PerteService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PerteTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
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

        $gerant = new Utilisateur('gerant@test.ci', 'Gérant');
        $gerant->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        $this->em->persist($gerant);

        $this->farine = (new MatierePremiere('Farine', 'kg'))->setCoutMoyenPondere(45000)->setStockActuel(100000);
        $this->em->persist($this->farine);

        $this->em->flush();
        $this->client->loginUser($gerant);
    }

    public function testSaisieDePerteCreeMouvementEtValorise(): void
    {
        $crawler = $this->client->request('GET', '/admin/pertes/saisie');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Enregistrer la perte')->form();
        $form['perte[matierePremiere]'] = (string) $this->farine->getId();
        $form['perte[quantite]'] = '2';        // 2 kg
        $form['perte[motif]'] = MotifPerte::CASSE->value;
        $this->client->submit($form);

        $this->assertResponseRedirects('/admin/pertes/saisie');

        $this->em->clear();
        $perte = static::getContainer()->get(PerteRepository::class)->findOneBy([]);
        $this->assertInstanceOf(Perte::class, $perte);
        $this->assertSame(2000, $perte->getQuantite());
        $this->assertSame(90000, $perte->getValorisation()); // 2 kg × 450 FCFA
        $this->assertSame(MotifPerte::CASSE, $perte->getMotif());

        // Mouvement de stock PERTE + décrément.
        $mouvement = static::getContainer()->get(MouvementStockRepository::class)->findOneBy(['type' => TypeMouvementStock::PERTE]);
        $this->assertNotNull($mouvement);
        $this->assertSame(-2000, $mouvement->getQuantite());

        $farine = $this->em->getRepository(MatierePremiere::class)->find($this->farine->getId());
        $this->assertSame(98000, $farine->getStockActuel()); // 100 − 2 kg
    }

    public function testSyntheseMensuelle(): void
    {
        $service = static::getContainer()->get(PerteService::class);
        $service->enregistrer(MotifPerte::CASSE, $this->farine, null, 2000);
        $service->enregistrer(MotifPerte::PERIME, $this->farine, null, 1000);

        $mois = (new \DateTimeImmutable())->format('Y-m');
        $this->client->request('GET', '/admin/pertes?mois='.$mois);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Casse');
        $this->assertSelectorTextContains('body', 'Farine');       // top produit
        $this->assertSelectorTextContains('body', 'Total des pertes valorisées');
        // Total = 3 kg × 450 = 1 350 FCFA
        $this->assertSelectorTextContains('body', '1 350 FCFA');
    }
}
