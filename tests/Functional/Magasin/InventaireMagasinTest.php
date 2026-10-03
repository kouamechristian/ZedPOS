<?php

namespace App\Tests\Functional\Magasin;

use App\Entity\JournalAudit;
use App\Entity\Magasin\MagasinEmplacement;
use App\Entity\Magasin\MagasinInventaire;
use App\Entity\Magasin\MagasinLigneInventaire;
use App\Entity\Magasin\MagasinMouvement;
use App\Entity\Magasin\MagasinProduit;
use App\Entity\Utilisateur;
use App\Enum\ActionAudit;
use App\Enum\Magasin\CategorieProduitMagasin;
use App\Enum\Magasin\StatutInventaireMagasin;
use App\Enum\Magasin\TypeMouvementMagasin;
use App\Enum\RoleUtilisateur;
use App\Service\Magasin\DemandeMouvementMagasin;
use App\Service\Magasin\InventaireMagasinService;
use App\Service\Magasin\MagasinStockService;
use App\Service\Magasin\QuantiteMagasin;
use App\Service\Magasin\StockMagasinInsuffisantException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Inventaire du magasin : théorique figé à l'ouverture, écart appliqué en
 * delta, lignes non comptées ignorées, commentaire et dirigeante exigés dès
 * qu'il y a un écart, une seule feuille par portée, feuille à l'aveugle, et le
 * premier inventaire qui fait le stock de départ.
 */
class InventaireMagasinTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private InventaireMagasinService $service;
    private MagasinStockService $stock;
    private Utilisateur $gerante;
    private Utilisateur $dirigeante;
    private MagasinProduit $farine;
    private MagasinProduit $sucre;
    private MagasinEmplacement $reserve;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(InventaireMagasinService::class);
        $this->stock = static::getContainer()->get(MagasinStockService::class);
        ReceptionMagasinServiceTest::viderTables($this->em);
        $this->em->getConnection()->executeStatement("UPDATE magasin_emplacement SET actif = 1, libelle = 'Réserve principale' WHERE code = 'RESERVE'");

        $this->gerante = (new Utilisateur('mariam@test.ci', 'Mariam'))->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        $this->dirigeante = (new Utilisateur('aya@test.ci', 'Aya'))->setRoles(['ROLE_DIRIGEANTE'])->setMotDePasse('x');
        $this->farine = (new MagasinProduit('Farine de blé', CategorieProduitMagasin::MATIERE, 'kg'))->setUniteAchat('sac')->setContenanceAchat(50000);
        $this->sucre = new MagasinProduit('Sucre', CategorieProduitMagasin::MATIERE, 'kg');
        foreach ([$this->gerante, $this->dirigeante, $this->farine, $this->sucre] as $entite) {
            $this->em->persist($entite);
        }
        $this->em->flush();
        $this->reserve = $this->stock->reserveParDefaut();
    }

    private function entrer(MagasinProduit $produit, int $millimes, ?MagasinEmplacement $ou = null): void
    {
        $this->stock->appliquer([new DemandeMouvementMagasin($produit, $ou ?? $this->reserve, $millimes, $millimes > 0 ? TypeMouvementMagasin::ENTREE_RECEPTION : TypeMouvementMagasin::SORTIE, $millimes > 0 ? 'reception' : 'sortie', 1)], $this->gerante);
    }

    private function ligne(MagasinInventaire $inventaire, MagasinProduit $produit): MagasinLigneInventaire
    {
        foreach ($inventaire->getLignes() as $ligne) {
            // Par identifiant : après une requête du client de test, Doctrine a rechargé les entités.
            if ($ligne->getProduit()->getId() === $produit->getId()) {
                return $ligne;
            }
        }
        $this->fail('Ligne absente : '.$produit->getNom());
    }

    /** @param array<string, array{0: ?int, 1?: bool}> $comptes par nom de produit */
    private function compter(MagasinInventaire $inventaire, array $comptes, ?string $commentaire = null): void
    {
        $saisie = [];
        foreach ([$this->farine, $this->sucre] as $produit) {
            if (\array_key_exists($produit->getNom(), $comptes)) {
                $c = $comptes[$produit->getNom()];
                $saisie[(int) $this->ligne($inventaire, $produit)->getId()] = ['quantite' => $c[0], 'enUniteAchat' => $c[1] ?? true];
            }
        }
        $this->service->enregistrerComptage($inventaire, $saisie, $commentaire);
    }

    private function stockFarine(): string
    {
        return QuantiteMagasin::formater($this->farine, $this->stock->stockTotal($this->farine));
    }

    /** Recette : on compte autant que le théorique → écart 0, la gérante valide, rien ne bouge. */
    public function testUnInventaireJusteSeValideSansMouvement(): void
    {
        $this->entrer($this->farine, 850000); // 17 sacs
        $inventaire = $this->service->ouvrir(null, $this->gerante);
        $this->assertSame('INV-'.date('Y').'-0001', $inventaire->getNumero());
        $this->assertSame(850000, $this->ligne($inventaire, $this->farine)->getQteTheorique());

        $this->compter($inventaire, ['Farine de blé' => [17000]]);
        $this->assertSame(0, $this->ligne($inventaire, $this->farine)->getEcart());
        $this->service->valider($inventaire, false, $this->gerante);

        $this->assertSame(StatutInventaireMagasin::VALIDE, $inventaire->getStatut());
        $this->assertCount(0, $this->em->getRepository(MagasinMouvement::class)->findBy(['type' => TypeMouvementMagasin::AJUSTEMENT_INVENTAIRE]));
        $this->assertSame('17 sacs (850 kg)', $this->stockFarine());
    }

    /** Le premier inventaire fait le stock de départ, sur une base vierge. */
    public function testLePremierInventaireFaitLeStockDeDepart(): void
    {
        $inventaire = $this->service->ouvrir(null, $this->gerante);
        $this->assertSame(0, $this->ligne($inventaire, $this->farine)->getQteTheorique());

        $this->compter($inventaire, ['Farine de blé' => [20000], 'Sucre' => [12500, false]], 'Stock de départ');
        $this->service->valider($inventaire, true, $this->dirigeante);

        $this->assertSame('20 sacs (1 000 kg)', $this->stockFarine());
        $this->assertSame(12500, $this->stock->stockTotal($this->sucre));
        $this->assertSame([], $this->stock->verifier());
    }

    public function testUnEcartExigeUnCommentaireEtLaDirigeante(): void
    {
        $this->entrer($this->farine, 1000000);
        $inventaire = $this->service->ouvrir(null, $this->gerante);
        $this->compter($inventaire, ['Farine de blé' => [18000]]);

        try {
            $this->service->valider($inventaire, true, $this->dirigeante);
            $this->fail('Un écart sans commentaire aurait dû être refusé.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('expliquez-le', $e->getMessage());
        }

        $this->compter($inventaire, ['Farine de blé' => [18000]], 'Deux sacs éventrés');
        try {
            $this->service->valider($inventaire, false, $this->gerante);
            $this->fail('La gérante ne valide pas un écart.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('revient à la dirigeante', $e->getMessage());
        }
        $this->assertSame(StatutInventaireMagasin::EN_COURS, $inventaire->getStatut());
        $this->assertSame('20 sacs (1 000 kg)', $this->stockFarine());

        $this->service->valider($inventaire, true, $this->dirigeante);
        $this->assertSame('18 sacs (900 kg)', $this->stockFarine());

        $actions = array_map(static fn (JournalAudit $j): string => $j->getAction(), $this->em->getRepository(JournalAudit::class)->findBy(['entite' => 'MagasinInventaire'], ['id' => 'ASC']));
        $this->assertSame([ActionAudit::MAGASIN_INVENTAIRE_OUVERT->value, ActionAudit::MAGASIN_INVENTAIRE_VALIDE->value, ActionAudit::MAGASIN_ECART_INVENTAIRE->value], $actions);
    }

    /** Une sortie entre l'ouverture et la validation n'est pas effacée : l'écart est un delta. */
    public function testLEcartEstAppliqueEnDelta(): void
    {
        $this->entrer($this->farine, 1000000); // 20 sacs
        $inventaire = $this->service->ouvrir(null, $this->gerante);
        $this->compter($inventaire, ['Farine de blé' => [19000]], 'Un sac manquant'); // écart −1 sac
        $this->entrer($this->farine, -150000); // 3 sacs sortent pendant ce temps

        $this->service->valider($inventaire, true, $this->dirigeante);
        $this->assertSame('16 sacs (800 kg)', $this->stockFarine(), '20 − 3 − 1, et non 19.');
    }

    public function testUneLigneNonCompteeEstIgnoree(): void
    {
        $this->entrer($this->farine, 1000000);
        $this->entrer($this->sucre, 5000);
        $inventaire = $this->service->ouvrir(null, $this->gerante);
        $this->compter($inventaire, ['Farine de blé' => [20000]]); // sucre non compté

        $this->service->valider($inventaire, false, $this->gerante);
        $this->assertSame(5000, $this->stock->stockTotal($this->sucre), 'Le sucre n\'a pas été remis à zéro.');
    }

    public function testRienDeCompteNeSeValidePas(): void
    {
        $inventaire = $this->service->ouvrir(null, $this->gerante);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Aucune ligne n\'est comptée');
        $this->service->valider($inventaire, true, $this->dirigeante);
    }

    public function testUnEcartQuiRendraitLeStockNegatifEstRefuse(): void
    {
        $this->entrer($this->farine, 1000000);
        $inventaire = $this->service->ouvrir(null, $this->gerante);
        $this->compter($inventaire, ['Farine de blé' => [5000]], 'Vol'); // écart −15 sacs
        $this->entrer($this->farine, -900000); // il ne reste que 2 sacs

        $this->expectException(StockMagasinInsuffisantException::class);
        try {
            $this->service->valider($inventaire, true, $this->dirigeante);
        } finally {
            $this->assertSame(StatutInventaireMagasin::EN_COURS, $inventaire->getStatut());
        }
    }

    public function testUneSeuleFeuilleParPortee(): void
    {
        $froid = new MagasinEmplacement('FROID', 'Chambre froide');
        $this->em->persist($froid);
        $this->em->flush();

        $parFroid = $this->service->ouvrir($froid, $this->gerante);
        $this->assertSame(['Chambre froide'], array_values(array_unique(array_map(static fn ($l): string => $l->getEmplacement()->getLibelle(), $parFroid->getLignes()->toArray()))));

        $this->service->ouvrir($this->reserve, $this->gerante); // autre emplacement : permis
        foreach ([null, $froid] as $portee) {
            try {
                $this->service->ouvrir($portee, $this->gerante);
                $this->fail('Une feuille en cours couvre déjà cette portée.');
            } catch (\DomainException $e) {
                $this->assertStringContainsString('encore en cours', $e->getMessage());
            }
        }

        $this->service->abandonner($parFroid, $this->gerante);
        $this->assertSame(StatutInventaireMagasin::ABANDONNE, $parFroid->getStatut());
        $this->service->ouvrir($froid, $this->gerante); // de nouveau permis
    }

    public function testUneFeuilleValideeNeSeModifiePlus(): void
    {
        $inventaire = $this->service->ouvrir(null, $this->gerante);
        $this->compter($inventaire, ['Farine de blé' => [0]]);
        $this->service->valider($inventaire, false, $this->gerante);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('ne se modifie plus');
        $this->compter($inventaire, ['Farine de blé' => [3000]]);
    }

    // ------------------------------------------------------------------ Écrans

    public function testLaFeuilleImprimeeNeMontrePasLeTheorique(): void
    {
        $this->entrer($this->farine, 850000);
        $inventaire = $this->service->ouvrir(null, $this->gerante);
        $this->client->loginUser($this->gerante);

        $texte = $this->client->request('GET', '/magasin/inventaires/'.$inventaire->getId().'/feuille')->filter('body')->text();
        $this->assertStringContainsString('Farine de blé', $texte);
        $this->assertStringContainsString('sac ou kg', $texte);
        $this->assertStringNotContainsString('17 sac', $texte, 'Le théorique (17 sacs) ne doit pas figurer.');
        $this->assertStringNotContainsString('850 kg', $texte);

        $ecran = $this->client->request('GET', '/magasin/inventaires/'.$inventaire->getId())->filter('#lignes-inventaire')->text();
        $this->assertStringContainsString('17 sacs (850 kg)', $ecran, 'L\'écran, lui, montre le théorique.');
    }

    public function testLeComptageALEcranPuisLaValidationParLaDirigeante(): void
    {
        $this->entrer($this->farine, 1000000);
        $this->client->loginUser($this->gerante);

        $page = $this->client->request('GET', '/magasin/inventaires');
        $this->client->request('POST', '/magasin/inventaires/ouvrir', ['_token' => $page->filter('input[name="_token"]')->attr('value')]);
        $inventaire = $this->em->getRepository(MagasinInventaire::class)->findOneBy([]);
        $id = $inventaire->getId();
        $this->assertResponseRedirects("/magasin/inventaires/$id", 303);
        $ligne = $this->ligne($inventaire, $this->farine)->getId();

        // La gérante saisit 18 sacs et tente de valider : refusé, mais le comptage reste.
        $jeton = $this->client->request('GET', "/magasin/inventaires/$id")->filter('form input[name="_token"]')->attr('value');
        $this->client->request('POST', "/magasin/inventaires/$id", ['_token' => $jeton, 'action' => 'valider', 'commentaire' => 'Deux sacs éventrés',
            'comptes' => [$ligne => ['quantite' => '18', 'unite' => 'achat']]]);
        $page = $this->client->followRedirect();
        $this->assertStringContainsString('revient à la dirigeante', $page->text());
        $this->assertSame('18', $page->filter('input[name="comptes['.$ligne.'][quantite]"]')->attr('value'));
        $this->assertStringContainsString('−2 sacs (100 kg)', $page->filter('#lignes-inventaire')->text());

        $this->client->loginUser($this->dirigeante);
        $jeton = $this->client->request('GET', "/magasin/inventaires/$id")->filter('form input[name="_token"]')->attr('value');
        $this->client->request('POST', "/magasin/inventaires/$id", ['_token' => $jeton, 'action' => 'valider', 'commentaire' => 'Deux sacs éventrés',
            'comptes' => [$ligne => ['quantite' => '18', 'unite' => 'achat']]]);
        $this->assertStringContainsString('1 écart(s) reporté(s)', $this->client->followRedirect()->text());
        $this->assertSame('18 sacs (900 kg)', $this->stockFarine());
    }

    public function testUneSaisieIllisibleEstReafficheeEn422(): void
    {
        $inventaire = $this->service->ouvrir(null, $this->gerante);
        $ligne = $this->ligne($inventaire, $this->farine)->getId();
        $this->client->loginUser($this->gerante);
        $jeton = $this->client->request('GET', '/magasin/inventaires/'.$inventaire->getId())->filter('form input[name="_token"]')->attr('value');

        $this->client->request('POST', '/magasin/inventaires/'.$inventaire->getId(), ['_token' => $jeton, 'action' => 'enregistrer',
            'comptes' => [$ligne => ['quantite' => 'vingt', 'unite' => 'achat']]]);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSame('vingt', $this->client->getCrawler()->filter('input[name="comptes['.$ligne.'][quantite]"]')->attr('value'));
    }

    public function testLeMagasinierConsulteSansPouvoirCompter(): void
    {
        $inventaire = $this->service->ouvrir(null, $this->gerante);
        $magasinier = (new Utilisateur('kouadio@test.ci', 'Kouadio'))->setRoles([RoleUtilisateur::MAGASIN->value])->setMotDePasse('x');
        $this->em->persist($magasinier);
        $this->em->flush();
        $this->client->loginUser($magasinier);

        foreach (['/magasin/inventaires', '/magasin/inventaires/'.$inventaire->getId(), '/magasin/inventaires/'.$inventaire->getId().'/feuille'] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful($url);
        }
        $page = $this->client->request('GET', '/magasin/inventaires/'.$inventaire->getId());
        $this->assertCount(0, $page->filter('input[name^="comptes"]'));
        $this->assertStringNotContainsString('Valider l\'inventaire', $page->filter('main')->text());

        foreach (['/magasin/inventaires/ouvrir', '/magasin/inventaires/'.$inventaire->getId(), '/magasin/inventaires/'.$inventaire->getId().'/abandonner'] as $url) {
            $this->client->request('POST', $url);
            $this->assertResponseStatusCodeSame(403, 'POST '.$url);
        }
    }

    public function testLAjustementSeLitALAnalyseEtSurLaFicheDeStock(): void
    {
        $inventaire = $this->service->ouvrir(null, $this->gerante);
        $this->compter($inventaire, ['Farine de blé' => [20000]], 'Stock de départ');
        $this->service->valider($inventaire, true, $this->dirigeante);
        $this->client->loginUser($this->gerante);

        $ligne = $this->client->request('GET', '/magasin/analyse?periode=jour')->filter('#analyse-magasin tr[data-produit="Farine de blé"]');
        $this->assertStringContainsString('+20 sacs (1 000 kg)', $ligne->filter('[data-colonne="ajustements"]')->text());

        $fiche = $this->client->request('GET', '/magasin/stock/'.$this->farine->getId().'/fiche?periode=jour');
        $this->assertCount(1, $fiche->filter('#fiche-stock a[href="/magasin/inventaires/'.$inventaire->getId().'"]'));
        $this->assertStringContainsString($inventaire->getNumero(), $fiche->filter('#fiche-stock')->text());
    }
}
