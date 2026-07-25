<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\FamilleProduit;
use App\Entity\LigneVente;
use App\Entity\Reglement;
use App\Entity\SessionCaisse;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Enum\CategorieDepense;
use App\Enum\ModeReglement;
use App\Enum\ModeVente;
use App\Enum\StatutSessionCaisse;
use App\Enum\TypeMouvementCaisse;
use App\Repository\SessionCaisseRepository;
use App\Service\RapportCaisseService;
use App\Service\SessionCaisseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Cycle de caisse complet : ouverture, dépenses, ticket X, clôture Z, immuabilité.
 */
class SessionCaisseTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Utilisateur $caissier;
    private Utilisateur $gerant;
    private Article $baguette; // 150 FCFA TTC, TVA 0

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

        $this->caissier = new Utilisateur('caissier@test.ci', 'Fatou');
        $this->caissier->setRoles(['ROLE_CAISSIER'])->setCodePin('x');
        $this->em->persist($this->caissier);

        $this->gerant = new Utilisateur('gerant@test.ci', 'Koffi');
        $this->gerant->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        $this->em->persist($this->gerant);

        $famille = new FamilleProduit('Pains');
        $this->em->persist($famille);

        $this->baguette = new Article('Baguette', 15000, 'pièce');
        $this->baguette->setFamilleProduit($famille)->setTauxTva(0);
        $this->em->persist($this->baguette);

        $this->em->flush();
        $this->client->loginUser($this->caissier);
    }

    private function service(): SessionCaisseService
    {
        return static::getContainer()->get(SessionCaisseService::class);
    }

    /**
     * Vente réglée en espèces, rattachée à la session (sans passer par HTTP).
     */
    private function vendre(SessionCaisse $session, int $totalTtc, int $encaisse = 0): Vente
    {
        static $sequence = 0;

        $vente = new Vente($session, ModeVente::BOULANGERIE, \sprintf('VTEST-%05d', ++$sequence), $totalTtc, 0, $totalTtc);
        $encaisse = 0 !== $encaisse ? $encaisse : $totalTtc;
        $vente->enregistrerRemiseEtRendu(0, null, $encaisse - $totalTtc);

        new LigneVente($vente, $this->baguette, 1000, $totalTtc);
        new Reglement($vente, ModeReglement::ESPECES, $encaisse);

        $this->em->persist($vente);
        $this->em->flush();

        return $vente;
    }

    // ---------------------------------------------------------------- Ouverture

    public function testOuvertureDeSessionAvecFondDeCaisse(): void
    {
        $crawler = $this->client->request('GET', '/caisse/session/ouverture');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Ouvrir la caisse')->form();
        $form['ouverture_caisse[fondCaisse]'] = '30000'; // 30 000 FCFA
        $this->client->submit($form);

        $this->assertResponseRedirects('/caisse');

        $session = static::getContainer()->get(SessionCaisseRepository::class)->ouvertePour($this->caissier);
        $this->assertInstanceOf(SessionCaisse::class, $session);
        $this->assertSame(3000000, $session->getFondCaisse(), 'Le fond est stocké en centimes.');
        $this->assertSame(StatutSessionCaisse::OUVERTE, $session->getStatut());
    }

    public function testUneSeuleSessionActiveParCaissier(): void
    {
        $this->service()->ouvrir($this->caissier, 3000000);

        $this->expectException(\DomainException::class);
        $this->service()->ouvrir($this->caissier, 5000000);
    }

    public function testLaCaisseRedirigeVersLOuvertureSansSession(): void
    {
        $this->client->request('GET', '/caisse');
        $this->assertResponseRedirects('/caisse/session/ouverture');
    }

    public function testVenteRefuseeSansSessionOuverte(): void
    {
        $this->client->request('POST', '/api/vente', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'uuid' => (string) \Symfony\Component\Uid\Uuid::v4(),
            'mode' => 'BOULANGERIE',
            'lignes' => [['articleId' => $this->baguette->getId(), 'quantite' => 1]],
            'reglements' => [['mode' => 'ESPECES', 'montant' => 15000]],
        ]));

        $this->assertResponseStatusCodeSame(409);
    }

    // ------------------------------------------------------- Dépenses de caisse

    public function testSaisieDUneDepenseDeCaisse(): void
    {
        $this->service()->ouvrir($this->caissier, 3000000);

        $crawler = $this->client->request('GET', '/caisse/session/depense');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Enregistrer')->form();
        $form['mouvement_caisse[type]'] = TypeMouvementCaisse::DEPENSE->value;
        $form['mouvement_caisse[categorie]'] = CategorieDepense::TRANSPORT->value;
        $form['mouvement_caisse[montant]'] = '2000'; // 2 000 FCFA
        $form['mouvement_caisse[commentaire]'] = 'Course taxi';
        $this->client->submit($form);

        $this->assertResponseRedirects('/caisse/session/depense');

        $mouvements = static::getContainer()->get(\App\Repository\MouvementCaisseRepository::class)->findAll();
        $this->assertCount(1, $mouvements);
        $this->assertSame(200000, $mouvements[0]->getMontant(), 'Le montant est stocké en centimes.');
        $this->assertSame(CategorieDepense::TRANSPORT, $mouvements[0]->getCategorie());
        $this->assertSame('Course taxi', $mouvements[0]->getCommentaire());
    }

    public function testUneDepenseExigeUneCategorie(): void
    {
        $session = $this->service()->ouvrir($this->caissier, 3000000);

        $this->expectException(\DomainException::class);
        $this->service()->enregistrerMouvement($session, $this->caissier, TypeMouvementCaisse::DEPENSE, 100000);
    }

    // -------------------------------------------------------------- Théorique

    public function testCalculDuTheorique(): void
    {
        $session = $this->service()->ouvrir($this->caissier, 3000000); // fond 30 000
        $this->vendre($session, 100000);                               // + 1 000 espèces
        $this->vendre($session, 50000, 100000);                        // + 500 (rendu 500)
        $this->service()->enregistrerMouvement($session, $this->caissier, TypeMouvementCaisse::DEPENSE, 20000, CategorieDepense::TRANSPORT);
        $this->service()->enregistrerMouvement($session, $this->caissier, TypeMouvementCaisse::SORTIE, 30000);

        // 30 000 + 1 000 + 500 − 200 − 300 = 31 000 FCFA
        $this->assertSame(3100000, static::getContainer()->get(RapportCaisseService::class)->theorique($session));
    }

    // ---------------------------------------------------------------- Ticket X

    public function testTicketXNeClotureRien(): void
    {
        $session = $this->service()->ouvrir($this->caissier, 3000000);
        $this->vendre($session, 100000);

        $this->client->request('GET', '/caisse/session/x');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'TICKET X');
        $this->assertSelectorTextContains('body', 'LA CAISSE RESTE OUVERTE');

        $this->em->clear();
        $session = static::getContainer()->get(SessionCaisseRepository::class)->find($session->getId());
        $this->assertSame(StatutSessionCaisse::OUVERTE, $session->getStatut(), 'Le ticket X ne clôture pas la session.');
        $this->assertNull($session->getClotureAt());
    }

    // --------------------------------------------------------------- Clôture Z

    public function testClotureSansEcart(): void
    {
        $session = $this->service()->ouvrir($this->caissier, 3000000);
        $this->vendre($session, 100000);

        $crawler = $this->client->request('GET', '/caisse/session/cloture');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Clôturer la caisse (Z)')->form();
        $form['cloture_caisse[montantCompte]'] = '31000'; // 30 000 + 1 000
        $this->client->submit($form);

        $this->assertResponseRedirects('/caisse/session/z/'.$session->getId());

        $this->em->clear();
        $session = static::getContainer()->get(SessionCaisseRepository::class)->find($session->getId());
        $this->assertSame(StatutSessionCaisse::CLOTUREE, $session->getStatut());
        $this->assertSame(3100000, $session->getTheorique());
        $this->assertSame(3100000, $session->getMontantCompte());
        $this->assertSame(0, $session->getEcart());
    }

    public function testClotureAvecEcartExigeUnCommentaire(): void
    {
        $session = $this->service()->ouvrir($this->caissier, 3000000);
        $this->vendre($session, 100000); // théorique = 31 000 FCFA

        // Sans commentaire : refusé.
        try {
            $this->service()->cloturer($session, 3050000);
            $this->fail("La clôture avec écart et sans commentaire aurait dû être refusée.");
        } catch (\DomainException $e) {
            $this->assertStringContainsString('commentaire', $e->getMessage());
        }

        $this->assertSame(StatutSessionCaisse::OUVERTE, $session->getStatut(), 'La session reste ouverte après un refus.');

        // Avec commentaire : accepté, écart figé (manquant de 500 FCFA).
        $rapport = $this->service()->cloturer($session, 3050000, 'Billet manquant, à vérifier');

        $this->assertSame(StatutSessionCaisse::CLOTUREE, $session->getStatut());
        $this->assertSame(-50000, $session->getEcart());
        $this->assertSame('Billet manquant, à vérifier', $session->getCommentaireCloture());
        $this->assertTrue($rapport->aUnEcart());
    }

    public function testClotureAvecExcedent(): void
    {
        $session = $this->service()->ouvrir($this->caissier, 3000000);
        $this->vendre($session, 100000);

        $this->service()->cloturer($session, 3120000, 'Excédent constaté');
        $this->assertSame(20000, $session->getEcart(), 'Un excédent est un écart positif.');
    }

    // ------------------------------------------------------------- Rapport Z

    public function testRapportZ(): void
    {
        $session = $this->service()->ouvrir($this->caissier, 3000000);

        $this->vendre($session, 100000);
        $this->vendre($session, 60000);
        $annulee = $this->vendre($session, 40000);
        $annulee->annuler('Erreur de saisie');

        $remisee = $this->vendre($session, 100000);
        $remisee->enregistrerRemiseEtRendu(10000, 'Client fidèle', 0);

        $this->service()->enregistrerMouvement($session, $this->caissier, TypeMouvementCaisse::DEPENSE, 20000, CategorieDepense::TRANSPORT, 'Taxi');
        $this->em->flush();

        $rapport = $this->service()->cloturer($session, 3240000, 'Contrôle');

        // 3 ventes validées (la 4e est annulée) : 1 000 + 600 + 1 000 = 2 600 FCFA
        $this->assertSame(3, $rapport->nombreTickets);
        $this->assertSame(260000, $rapport->caTotal);
        $this->assertSame(86666, $rapport->panierMoyen);

        // Ventilation par règlement : tout en espèces.
        $this->assertCount(1, $rapport->parReglement);
        $this->assertSame(ModeReglement::ESPECES->value, $rapport->parReglement[0]['mode']);
        $this->assertSame(260000, $rapport->parReglement[0]['montant']);

        // Ventilation par famille.
        $this->assertCount(1, $rapport->parFamille);
        $this->assertSame('Pains', $rapport->parFamille[0]['famille']);

        $this->assertSame(10000, $rapport->remisesMontant);
        $this->assertSame(1, $rapport->remisesNombre);
        $this->assertSame(1, $rapport->annulationsNombre);
        $this->assertSame(40000, $rapport->annulationsMontant);
        $this->assertSame(20000, $rapport->depenses);
        $this->assertSame(0, $rapport->sorties);

        // Théorique = 30 000 + 2 600 − 200 = 32 400 FCFA
        $this->assertSame(3240000, $rapport->theorique);
        $this->assertSame(0, $rapport->ecart);
        $this->assertTrue($rapport->definitif);
    }

    public function testRapportZConsultableParLeGerant(): void
    {
        $session = $this->service()->ouvrir($this->caissier, 3000000);
        $this->vendre($session, 100000);
        $this->service()->cloturer($session, 3100000);

        $this->client->loginUser($this->gerant);
        $this->client->request('GET', '/admin/clotures/'.$session->getId());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Rapport Z');
        $this->assertSelectorTextContains('body', '31 000 FCFA');
    }

    public function testUnCaissierNeVoitPasLeZDUnAutre(): void
    {
        $autre = new Utilisateur('autre@test.ci', 'Yao');
        $autre->setRoles(['ROLE_CAISSIER'])->setCodePin('x');
        $this->em->persist($autre);
        $this->em->flush();

        $session = $this->service()->ouvrir($autre, 3000000);
        $this->service()->cloturer($session, 3000000);

        $this->client->request('GET', '/caisse/session/z/'.$session->getId());
        $this->assertResponseStatusCodeSame(403);
    }

    // ------------------------------------------- Immuabilité d'une journée close

    public function testUneJourneeClotureeNAcceptePlusDeVente(): void
    {
        $session = $this->service()->ouvrir($this->caissier, 3000000);
        $this->service()->cloturer($session, 3000000);

        $this->expectException(\DomainException::class);
        new Vente($session, ModeVente::BOULANGERIE, 'VTEST-APRES', 10000, 0, 10000);
    }

    public function testUneJourneeClotureeNAcceptePlusDeDepense(): void
    {
        $session = $this->service()->ouvrir($this->caissier, 3000000);
        $this->service()->cloturer($session, 3000000);

        $this->expectException(\DomainException::class);
        $this->service()->enregistrerMouvement($session, $this->caissier, TypeMouvementCaisse::SORTIE, 50000);
    }

    public function testUneVenteDUneJourneeClotureeNestPlusAnnulable(): void
    {
        $session = $this->service()->ouvrir($this->caissier, 3000000);
        $vente = $this->vendre($session, 100000);
        $this->service()->cloturer($session, 3100000);

        // Contrôle métier direct…
        try {
            $vente->annuler('Trop tard');
            $this->fail('Une vente rattachée à une journée clôturée ne doit plus être annulable.');
        } catch (\DomainException) {
        }

        // …et par l'API (le gérant reçoit un 409, pas une erreur 500).
        $this->client->loginUser($this->gerant);
        $this->client->request(
            'POST',
            '/api/vente/'.$vente->getUuid().'/annuler',
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['motif' => 'Trop tard']),
        );
        $this->assertResponseStatusCodeSame(409);
    }

    public function testUneSessionNestClotureeQuUneFois(): void
    {
        $session = $this->service()->ouvrir($this->caissier, 3000000);
        $this->service()->cloturer($session, 3000000);

        $this->expectException(\DomainException::class);
        $this->service()->cloturer($session, 9900000, 'Nouvelle tentative');
    }

    public function testApresClotureLeCaissierPeutRouvrirUneNouvelleSession(): void
    {
        $premiere = $this->service()->ouvrir($this->caissier, 3000000);
        $this->service()->cloturer($premiere, 3000000);

        $seconde = $this->service()->ouvrir($this->caissier, 5000000);

        $this->assertNotSame($premiere->getId(), $seconde->getId());
        $this->assertSame(StatutSessionCaisse::OUVERTE, $seconde->getStatut());
    }
}
