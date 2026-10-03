<?php

namespace App\Tests\Functional\Magasin;

use App\Entity\Fournisseur;
use App\Entity\Magasin\MagasinEmplacement;
use App\Entity\Magasin\MagasinProduit;
use App\Entity\Magasin\MagasinReception;
use App\Entity\Utilisateur;
use App\Enum\Magasin\CategorieProduitMagasin;
use App\Enum\Magasin\StatutReceptionMagasin;
use App\Service\Magasin\MagasinStockService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Les écrans de la réception, au doigt : une étape par écran, frise en tête,
 * erreurs en 422 avec la saisie, prix réservés à la dirigeante.
 */
class ReceptionEcranTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Utilisateur $gerante;
    private Utilisateur $dirigeante;
    private Fournisseur $fournisseur;
    private MagasinProduit $farine;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ReceptionMagasinServiceTest::viderTables($this->em);
        $this->em->getConnection()->executeStatement("UPDATE magasin_emplacement SET actif = 1, libelle = 'Réserve principale' WHERE code = 'RESERVE'");

        $this->gerante = (new Utilisateur('mariam@test.ci', 'Mariam'))->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        $this->dirigeante = (new Utilisateur('aya@test.ci', 'Aya'))->setRoles(['ROLE_DIRIGEANTE'])->setMotDePasse('x');
        $this->fournisseur = new Fournisseur('Grands Moulins');
        $this->farine = (new MagasinProduit('Farine de blé', CategorieProduitMagasin::MATIERE, 'kg'))->setUniteAchat('sac')->setContenanceAchat(50000);
        foreach ([$this->gerante, $this->dirigeante, $this->fournisseur, $this->farine] as $entite) {
            $this->em->persist($entite);
        }
        $this->em->flush();
    }

    private function jeton(string $url): string
    {
        $crawler = $this->client->request('GET', $url);
        $this->assertResponseIsSuccessful($url);

        return (string) $crawler->filter('input[name="_token"]')->attr('value');
    }

    /** @param array<string, mixed> $donnees */
    private function poster(string $url, array $donnees): void
    {
        $this->client->request('POST', $url, ['_token' => $this->jeton($url)] + $donnees);
    }

    private function reception(): MagasinReception
    {
        $this->em->clear();
        $reception = $this->em->getRepository(MagasinReception::class)->findOneBy([]);
        $this->assertNotNull($reception);

        return $reception;
    }

    private function nouvelle(string $annoncee = '20', array $ligne = []): MagasinReception
    {
        $this->poster('/magasin/receptions/nouvelle', [
            'fournisseur' => $this->fournisseur->getId(),
            'date' => '2026-10-02',
            'bon_livraison' => 'BL-778',
            'commentaire' => '',
            'lignes' => [0 => ['produit' => $this->farine->getId(), 'annoncee' => $annoncee] + $ligne],
        ]);

        return $this->reception();
    }

    private function stockFarine(): string
    {
        $stock = static::getContainer()->get(MagasinStockService::class);
        $farine = $this->em->getRepository(MagasinProduit::class)->find($this->farine->getId());

        return \App\Service\Magasin\QuantiteMagasin::formater($farine, $stock->stockTotal($farine));
    }

    /** La recette, écran par écran, jusqu'à « 20 sacs (1 000 kg) » sur l'écran de stock. */
    public function testLaRecetteSeDerouleEcranParEcran(): void
    {
        $this->client->loginUser($this->gerante);

        $reception = $this->nouvelle();
        $this->assertResponseRedirects('/magasin/receptions/'.$reception->getId().'/controle', 303);
        $this->assertSame('REC-2026-0001', $reception->getNumero());
        $id = $reception->getId();
        $ligne = $reception->getLignes()->first()->getId();

        $crawler = $this->client->followRedirect();
        $this->assertSame('a-faire', $crawler->filter('#frise-reception [data-etape="CONTROLEE"]')->attr('data-etat'));
        $this->assertSame('faite', $crawler->filter('#frise-reception [data-etape="RECUE"]')->attr('data-etat'));
        $this->assertSame('a-venir', $crawler->filter('#frise-reception [data-etape="STOCKEE"]')->attr('data-etat'));

        $this->poster("/magasin/receptions/$id/controle", ['comptees' => [$ligne => '20'], 'commentaire' => '']);
        $this->assertResponseRedirects("/magasin/receptions/$id/inspection", 303);

        $this->poster("/magasin/receptions/$id/inspection", ['decisions' => [$ligne => ['rejetee' => '', 'motif' => '']]]);
        $this->assertResponseRedirects("/magasin/receptions/$id/stockage", 303);
        $this->assertSame(20000, $this->reception()->getLignes()->first()->getQteAcceptee(), 'Accepté laissé vide = tout le compté.');

        $crawler = $this->client->request('GET', "/magasin/receptions/$id/stockage");
        $this->assertCount(0, $crawler->filter('select[name^="emplacements"]'), 'Une seule zone : pas de question.');
        $this->assertStringContainsString('20 sacs (1 000 kg)', $crawler->filter('#lignes-stockage')->text());

        $this->poster("/magasin/receptions/$id/stockage", []);
        $this->assertResponseRedirects("/magasin/receptions/$id", 303);
        $this->assertSame(StatutReceptionMagasin::STOCKEE, $this->reception()->getStatut());
        $this->assertSame('20 sacs (1 000 kg)', $this->stockFarine());

        $crawler = $this->client->request('GET', '/magasin/stock');
        $this->assertStringContainsString('20 sacs (1 000 kg)', $crawler->filter('#stock-magasin')->text());
    }

    public function testUnEcartSansCommentaireReafficheLeControleEn422(): void
    {
        $this->client->loginUser($this->gerante);
        $reception = $this->nouvelle();
        $id = $reception->getId();
        $ligne = $reception->getLignes()->first()->getId();

        $this->poster("/magasin/receptions/$id/controle", ['comptees' => [$ligne => '19'], 'commentaire' => '']);
        $this->assertResponseStatusCodeSame(422);
        $crawler = $this->client->getCrawler();
        $this->assertStringContainsString('expliquez l\'écart', $crawler->filter('[role="alert"]')->text());
        $this->assertSame('19', $crawler->filter('input[name="comptees['.$ligne.']"]')->attr('value'), 'La saisie est conservée.');

        $this->poster("/magasin/receptions/$id/controle", ['comptees' => [$ligne => '19'], 'commentaire' => 'Un sac manquant']);
        $this->assertResponseRedirects();
        $this->poster("/magasin/receptions/$id/inspection", ['decisions' => [$ligne => ['rejetee' => '1', 'motif' => '']]]);
        $this->assertResponseStatusCodeSame(422, 'Rejet sans motif.');
        $this->poster("/magasin/receptions/$id/inspection", ['decisions' => [$ligne => ['rejetee' => '1', 'motif' => 'AVARIE']]]);
        $this->poster("/magasin/receptions/$id/stockage", []);

        $this->assertSame('18 sacs (900 kg)', $this->stockFarine());

        $crawler = $this->client->request('GET', "/magasin/receptions/$id/bon");
        $this->assertResponseIsSuccessful();
        $texte = $crawler->filter('body')->text();
        $this->assertStringContainsString('Avarié', $texte);
        $this->assertStringContainsString('Un sac manquant', $texte);
    }

    public function testOnNeSautePasDEtapeParLAdresse(): void
    {
        $this->client->loginUser($this->gerante);
        $id = $this->nouvelle()->getId();

        foreach (['inspection', 'stockage'] as $etape) {
            $this->client->request('GET', "/magasin/receptions/$id/$etape");
            $this->assertResponseRedirects("/magasin/receptions/$id", 303, $etape);
        }
    }

    public function testLePrixNExisteQuePourLaDirigeante(): void
    {
        $this->client->loginUser($this->gerante);
        $crawler = $this->client->request('GET', '/magasin/receptions/nouvelle');
        $this->assertCount(0, $crawler->filter('input[name$="[prix]"]'));

        // Prix forgé par la gérante : ignoré.
        $reception = $this->nouvelle('20', ['prix' => '25000']);
        $this->assertNull($reception->getLignes()->first()->getPrixUnitaire());

        $this->client->loginUser($this->dirigeante);
        $crawler = $this->client->request('GET', '/magasin/receptions/nouvelle');
        $this->assertCount(1, $crawler->filter('input[name="lignes[0][prix]"]'));
    }

    public function testLaDirigeanteFixeLePrixAuStockageEtLeCoutMoyenSuit(): void
    {
        $this->client->loginUser($this->dirigeante);
        $reception = $this->nouvelle();
        $id = $reception->getId();
        $ligne = $reception->getLignes()->first()->getId();
        $this->poster("/magasin/receptions/$id/controle", ['comptees' => [$ligne => '20']]);
        $this->poster("/magasin/receptions/$id/inspection", ['decisions' => [$ligne => ['rejetee' => '']]]);
        $this->poster("/magasin/receptions/$id/stockage", ['prix' => [$ligne => '25 000']]);
        $this->assertResponseRedirects();

        $this->em->clear();
        $this->assertSame(50000, $this->em->getRepository(MagasinProduit::class)->find($this->farine->getId())->getCoutMoyen(), '25 000 F le sac de 50 kg = 500 F le kg.');
    }

    public function testPlusieursZonesFontApparaitreLeChoixDeLEmplacement(): void
    {
        $froid = new MagasinEmplacement('FROID', 'Chambre froide');
        $this->em->persist($froid);
        $this->em->flush();

        $this->client->loginUser($this->gerante);
        $reception = $this->nouvelle();
        $id = $reception->getId();
        $ligne = $reception->getLignes()->first()->getId();
        $this->poster("/magasin/receptions/$id/controle", ['comptees' => [$ligne => '20']]);
        $this->poster("/magasin/receptions/$id/inspection", ['decisions' => [$ligne => ['rejetee' => '']]]);

        $crawler = $this->client->request('GET', "/magasin/receptions/$id/stockage");
        $this->assertCount(1, $crawler->filter('select[name="emplacements['.$ligne.']"]'));

        $this->poster("/magasin/receptions/$id/stockage", ['emplacements' => [$ligne => (string) $froid->getId()]]);
        $this->assertSame('Chambre froide', $this->reception()->getLignes()->first()->getEmplacement()?->getLibelle());
    }

    public function testLAnnulationDepuisLaFiche(): void
    {
        $this->client->loginUser($this->gerante);
        $id = $this->nouvelle()->getId();

        $this->client->request('POST', "/magasin/receptions/$id/annuler", ['_token' => $this->jeton("/magasin/receptions/$id"), 'motif' => '']);
        $this->assertResponseRedirects("/magasin/receptions/$id", 303);
        $this->assertSame(StatutReceptionMagasin::RECUE, $this->reception()->getStatut(), 'Sans motif : refusé.');

        $this->client->request('POST', "/magasin/receptions/$id/annuler", ['_token' => $this->jeton("/magasin/receptions/$id"), 'motif' => 'Commande annulée']);
        $this->assertSame(StatutReceptionMagasin::ANNULEE, $this->reception()->getStatut());

        $crawler = $this->client->request('GET', '/magasin/receptions?statut=ANNULEE');
        $this->assertStringContainsString('REC-2026-0001', $crawler->filter('#receptions-magasin')->text());
        $crawler = $this->client->request('GET', '/magasin/receptions?statut=RECUE');
        $this->assertStringNotContainsString('REC-2026-0001', $crawler->filter('#receptions-magasin')->text());
    }

    public function testUneSaisieInvalideALaCreationEstReafficheeEn422(): void
    {
        $this->client->loginUser($this->gerante);
        $this->poster('/magasin/receptions/nouvelle', [
            'fournisseur' => $this->fournisseur->getId(),
            'date' => '2026-10-02',
            'lignes' => [0 => ['produit' => $this->farine->getId(), 'annoncee' => 'vingt']],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame('vingt', $this->client->getCrawler()->filter('input[name="lignes[0][annoncee]"]')->attr('value'));
        $this->assertSame(0, $this->em->getRepository(MagasinReception::class)->count([]));
    }

    public function testLeCaissierNEntrePasDansLesReceptions(): void
    {
        $caissier = (new Utilisateur('fatou@test.ci', 'Fatou'))->setRoles(['ROLE_CAISSIER'])->setCodePin('x');
        $this->em->persist($caissier);
        $this->em->flush();

        $this->client->loginUser($caissier);
        $this->client->request('GET', '/magasin/receptions');
        $this->assertResponseStatusCodeSame(403);
    }
}
