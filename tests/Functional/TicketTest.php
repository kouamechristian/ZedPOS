<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\FamilleProduit;
use App\Entity\LigneVente;
use App\Entity\Reglement;
use App\Entity\SessionCaisse;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Enum\ModeReglement;
use App\Enum\ModeVente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class TicketTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $uuid;

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

        $caissier = new Utilisateur('caissier@test.ci', 'Fatou Traoré');
        $caissier->setRoles(['ROLE_CAISSIER'])->setCodePin('x');
        $this->em->persist($caissier);

        $famille = new FamilleProduit('Divers');
        $this->em->persist($famille);

        $a = new Article('Baguette', 100000, 'pièce');
        $a->setFamilleProduit($famille)->setTauxTva(0);
        $b = new Article('Sandwich', 50000, 'pièce');
        $b->setFamilleProduit($famille)->setTauxTva(1800);
        $this->em->persist($a);
        $this->em->persist($b);

        $session = new SessionCaisse($caissier, 0);
        $this->em->persist($session);

        // 100 000 (TVA 0) + 50 000 (TVA 18 %) = 150 000 ; TVA = 7 628.
        $vente = new Vente($session, ModeVente::BOULANGERIE, 'V260725-00001', 142372, 7628, 150000);
        $vente->enregistrerRemiseEtRendu(0, null, 50000);
        new LigneVente($vente, $a, 1000, 100000, 0, null);
        new LigneVente($vente, $b, 1000, 50000, 0, 'Sans oignon');
        new Reglement($vente, ModeReglement::ESPECES, 200000, null);
        $this->em->persist($vente);
        $this->em->flush();

        $this->uuid = (string) $vente->getUuid();
        $this->client->loginUser($caissier);
    }

    public function testTicketHtmlAfficheLesInformations(): void
    {
        $this->client->request('GET', '/caisse/ticket/'.$this->uuid);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'ZedPOS');            // raison sociale (.env)
        $this->assertSelectorTextContains('body', 'Abengourou');        // adresse
        $this->assertSelectorTextContains('body', 'V260725-00001');     // numéro
        $this->assertSelectorTextContains('body', 'TVA 18');            // ventilation
        $this->assertSelectorTextContains('body', 'Rendu');             // rendu de monnaie
        $this->assertSelectorTextContains('body', 'QR RNE/DGI');        // emplacement réservé
    }

    public function testEndpointEscPosRenvoieUneCommandeBinaire(): void
    {
        $this->client->request('GET', '/caisse/ticket/'.$this->uuid.'/escpos');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['ok']);

        $commande = base64_decode($data['base64']);
        $this->assertStringStartsWith("\x1B@", $commande);          // init
        $this->assertStringContainsString("\x1DV\x00", $commande);  // coupe
        $this->assertStringContainsString("\x1Bp\x00", $commande);  // tiroir
        $this->assertStringContainsString('ZedPOS', $commande);
    }

    public function testTicketIntrouvableRenvoie404(): void
    {
        $this->client->request('GET', '/caisse/ticket/'.$this->uuid.'x/escpos');
        $this->assertResponseStatusCodeSame(404);
    }
}
