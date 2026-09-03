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
        foreach (['ligne_fiche_technique', 'fiche_technique', 'ligne_vente', 'reglement', 'vente', 'mouvement_caisse', 'session_caisse', 'mouvement_stock', 'perte', 'article', 'matiere_premiere', 'fournisseur', 'famille_produit', 'journal_audit', 'utilisateur'] as $table) {
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
        // Raison sociale : la table `parametre` est vide ici, on lit donc la valeur
        // par défaut du catalogue (CleParametre) — pas une variable d'environnement.
        $this->assertSelectorTextContains('body', 'ZedPOS');
        $this->assertSelectorTextContains('body', 'Abengourou');        // adresse
        $this->assertSelectorTextContains('body', 'V260725-00001');     // numéro
        $this->assertSelectorTextContains('body', 'TVA 18');            // ventilation
        $this->assertSelectorTextContains('body', 'Rendu');             // rendu de monnaie
    }

    /**
     * Le numéro de ticket est imprimé en code-barres, pour retrouver une vente en
     * la scannant. Le SVG est vérifié pour ce qu'il est — des barres — et non par
     * sa seule présence : un `<svg>` vide passerait inaperçu à l'écran comme au
     * test, et ne se scannerait jamais.
     */
    public function testLeTicketPorteLeCodeBarresDuNumero(): void
    {
        $this->client->request('GET', '/caisse/ticket/'.$this->uuid);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('svg.code-barres');
        $this->assertGreaterThan(20, $this->client->getCrawler()->filter('svg.code-barres rect')->count());

        // Numéro répété en clair : si le lecteur refuse, la caissière le saisit.
        $this->assertSelectorTextContains('.code-barres-valeur', 'V260725-00001');

        // L'ancien emplacement réservé n'a plus lieu d'être.
        $this->assertSelectorNotExists('.qr');
    }

    /**
     * Le reçu affiché à la caissière juste après l'encaissement passe par ce
     * fragment. C'est le même gabarit que la page imprimable — le code-barres
     * qu'elle voit à l'écran est donc celui qu'elle tend au client.
     */
    public function testLApercuApresEncaissementPorteLeMemeCodeBarres(): void
    {
        $this->client->request('GET', '/caisse/ticket/'.$this->uuid.'/apercu');

        $this->assertResponseIsSuccessful();

        $fragment = $this->client->getCrawler();
        $this->assertGreaterThan(20, $fragment->filter('svg.code-barres rect')->count());
        $this->assertSame('V260725-00001', trim($fragment->filter('.code-barres-valeur')->text()));
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

    // ------------------------------------------------- Agent matériel local

    /**
     * La charge utile destinée à la route /print de l'agent matériel.
     *
     * Le point qui compte : **des FCFA entiers**, alors que tout le reste de
     * l'application manipule des centimes. L'agent imprime ce qu'on lui donne, il
     * n'interprète rien — un montant en centimes sortirait multiplié par cent sur
     * le papier, et une décimale sortirait telle quelle dans une devise qui n'en
     * a pas.
     */
    public function testLeTicketMaterielSortEnFcfaEntiers(): void
    {
        $this->client->request('GET', '/caisse/ticket/'.$this->uuid.'/materiel');

        $this->assertResponseIsSuccessful();
        $ticket = json_decode($this->client->getResponse()->getContent(), true)['ticket'];

        // 150 000 centimes = 1 500 FCFA ; tendu 200 000 = 2 000 ; rendu 50 000 = 500.
        $this->assertSame(1500, $ticket['total']);
        $this->assertSame(2000, $ticket['paid']);
        $this->assertSame(500, $ticket['change']);

        foreach (['total', 'paid', 'change'] as $champ) {
            $this->assertIsInt($ticket[$champ], \sprintf('%s doit être un entier, jamais un flottant.', $champ));
        }

        $this->assertSame(
            [
                ['label' => 'Baguette', 'qty' => '1', 'price' => 1000],
                ['label' => 'Sandwich (Sans oignon)', 'qty' => '1', 'price' => 500],
            ],
            $ticket['lines'],
            'Une ligne par article, commentaire compris, en FCFA entiers.',
        );

        // L'en-tête porte l'identité de la boutique et les repères de la vente.
        $this->assertContains('Ticket : V260725-00001', $ticket['header']);
        $this->assertStringContainsString('ZedPOS', implode(' ', $ticket['header']));
        $this->assertStringContainsString('Abengourou', implode(' ', $ticket['header']));

        // Le pied reprend la ventilation de TVA, le règlement et la phrase de fin.
        $pied = implode(' | ', $ticket['footer']);
        $this->assertStringContainsString('TVA 18%', $pied);
        $this->assertStringContainsString('Espèces : 2000 FCFA', $pied);
    }

    /**
     * Une réimpression ne fait pas entrer d'argent : le tiroir s'est ouvert quand
     * le client a payé. La règle est portée par le serveur et non par l'écran —
     * sinon un bouton de réimpression deviendrait un moyen commode d'ouvrir le
     * tiroir sans vente.
     */
    public function testUneReimpressionNOuvrePasLeTiroir(): void
    {
        $this->client->request('GET', '/caisse/ticket/'.$this->uuid.'/materiel');

        $this->assertResponseIsSuccessful();
        $ticket = json_decode($this->client->getResponse()->getContent(), true)['ticket'];

        $this->assertFalse($ticket['openDrawer'], 'La route de réimpression n\'ouvre jamais le tiroir.');

        // …alors que la vente est bien réglée en espèces : c'est donc le chemin
        // qui décide, pas le mode de règlement. À l'encaissement, le même service
        // ouvre le tiroir.
        $vente = static::getContainer()->get(\App\Repository\VenteRepository::class)
            ->findOneBy(['uuid' => \Symfony\Component\Uid\Uuid::fromString($this->uuid)]);

        $this->assertTrue(
            static::getContainer()->get(\App\Service\TicketMateriel::class)->pour($vente)['openDrawer'],
            'À l\'encaissement en espèces, le tiroir s\'ouvre.',
        );
    }

    /**
     * Le ticket matériel suit le même contrôle d'accès que le ticket papier : un
     * caissier ne réimprime pas la vente d'un collègue, même en connaissant son
     * uuid.
     */
    public function testLeTicketMaterielDUnCollegueEstRefuse(): void
    {
        $autre = new Utilisateur('collegue@test.ci', 'Yao Kouassi');
        $autre->setRoles(['ROLE_CAISSIER'])->setCodePin('y');
        $this->em->persist($autre);
        $this->em->flush();

        $this->client->loginUser($autre);
        $this->client->request('GET', '/caisse/ticket/'.$this->uuid.'/materiel');

        $this->assertResponseStatusCodeSame(403);
    }
}
