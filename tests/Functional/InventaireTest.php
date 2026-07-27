<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\FamilleProduit;
use App\Entity\Inventaire;
use App\Entity\MatierePremiere;
use App\Entity\MouvementStock;
use App\Entity\Utilisateur;
use App\Enum\ActionAudit;
use App\Enum\TypeMouvementStock;
use App\Repository\InventaireRepository;
use App\Service\InventaireService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Module d'inventaire : feuille de comptage, saisie, validation.
 *
 * L'enjeu n'est pas de savoir corriger un stock — c'est de le corriger **sans
 * que l'historique diverge**. Chaque écart doit produire un mouvement de stock et
 * une trace d'audit ; c'était précisément ce qui manquait quand on modifiait
 * `stockActuel` à la main.
 */
class InventaireTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Utilisateur $gerant;
    private MatierePremiere $farine;
    private MatierePremiere $sucre;
    private Article $coca;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $connexion = $this->em->getConnection();
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['ligne_inventaire', 'inventaire', 'ligne_fiche_technique', 'fiche_technique', 'ligne_vente', 'reglement', 'vente', 'mouvement_caisse', 'session_caisse', 'mouvement_stock', 'perte', 'article', 'matiere_premiere', 'fournisseur', 'famille_produit', 'journal_audit', 'notification', 'utilisateur'] as $table) {
            $connexion->executeStatement('DELETE FROM '.$table);
        }
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $this->gerant = new Utilisateur('koffi@test.ci', 'Koffi');
        $this->gerant->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        $this->em->persist($this->gerant);

        // 100 kg de farine à 450 FCFA le kg, 50 kg de sucre à 600.
        $this->farine = (new MatierePremiere('Farine', 'kg'))
            ->setStockActuel(100000)->setStockMini(10000)->setCoutMoyenPondere(45000);
        $this->sucre = (new MatierePremiere('Sucre', 'kg'))
            ->setStockActuel(50000)->setStockMini(5000)->setCoutMoyenPondere(60000);
        $this->em->persist($this->farine);
        $this->em->persist($this->sucre);

        $famille = new FamilleProduit('Boissons');
        $this->em->persist($famille);

        // Article revendu en l'état : suivi en stock, donc inventorié lui aussi.
        $this->coca = new Article('Coca', 50000, 'bouteille');
        $this->coca->setFamilleProduit($famille)->setTauxTva(1800)
            ->setSuiviStock(true)->setStockActuel(24000);
        $this->em->persist($this->coca);

        // Un article non suivi : il n'a rien à faire sur la feuille.
        $baguette = new Article('Baguette', 15000, 'pièce');
        $baguette->setFamilleProduit($famille)->setTauxTva(0);
        $this->em->persist($baguette);

        $this->em->flush();

        $this->client->loginUser($this->gerant);
    }

    private function service(): InventaireService
    {
        return static::getContainer()->get(InventaireService::class);
    }

    private function ouvrir(): Inventaire
    {
        return $this->service()->ouvrir($this->gerant);
    }

    /** @param array<string, int> $comptages libellé => quantité en millièmes */
    private function compter(Inventaire $inventaire, array $comptages): void
    {
        $parLigne = [];
        foreach ($inventaire->getLignes() as $ligne) {
            if (\array_key_exists($ligne->getLibelle(), $comptages)) {
                $parLigne[$ligne->getId()] = $comptages[$ligne->getLibelle()];
            }
        }

        $this->service()->saisir($inventaire, $parLigne);
    }

    /** @return list<MouvementStock> */
    private function mouvementsInventaire(): array
    {
        return $this->em->getRepository(MouvementStock::class)
            ->findBy(['type' => TypeMouvementStock::INVENTAIRE]);
    }

    // ------------------------------------------------------------ L'ouverture

    public function testLaFeuilleCouvreLesMatieresEtLesArticlesSuivis(): void
    {
        $inventaire = $this->ouvrir();

        $libelles = array_map(
            static fn ($ligne): string => $ligne->getLibelle(),
            $inventaire->getLignes()->toArray(),
        );

        $this->assertContains('Farine', $libelles);
        $this->assertContains('Sucre', $libelles);
        $this->assertContains('Coca', $libelles, 'Une boisson revendue en l\'état dérive autant qu\'un sac de farine.');
        $this->assertNotContains('Baguette', $libelles, 'Un article non suivi en stock n\'a rien à compter.');
    }

    public function testLaFeuilleFigeLeTheoriqueEtLeCout(): void
    {
        $inventaire = $this->ouvrir();
        $ligne = $this->ligne($inventaire, 'Farine');

        $this->assertSame(100000, $ligne->getQuantiteTheorique());
        $this->assertSame(45000, $ligne->getCoutUnitaire());
        $this->assertNull($ligne->getQuantiteComptee(), 'Rien n\'est compté à l\'ouverture.');
    }

    /**
     * Deux feuilles ouvertes en parallèle figeraient le même théorique, et la
     * seconde validation écraserait les écarts de la première.
     */
    public function testUneSeuleFeuilleOuverteALaFois(): void
    {
        $this->ouvrir();

        $this->expectException(\DomainException::class);
        $this->ouvrir();
    }

    // ------------------------------------------------------------ La validation

    public function testUnEcartDevientUnMouvementDeStockEtCorrigeLeStock(): void
    {
        $inventaire = $this->ouvrir();
        // 97,5 kg comptés pour 100 théoriques : 2,5 kg manquants.
        $this->compter($inventaire, ['Farine' => 97500]);

        $this->service()->valider($inventaire, $this->gerant, 'Comptage mensuel');

        $this->em->clear();
        $farine = $this->em->getRepository(MatierePremiere::class)->findOneBy(['nom' => 'Farine']);
        $this->assertSame(97500, $farine->getStockActuel());

        $mouvements = $this->mouvementsInventaire();
        $this->assertCount(1, $mouvements, 'Un mouvement, et un seul : la ligne en écart.');
        $this->assertSame(-2500, $mouvements[0]->getQuantite(), 'La quantité est signée.');
        $this->assertSame('inventaire', $mouvements[0]->getSourceType());
    }

    /**
     * Le cœur du module : corriger un stock **et** laisser une trace. C'est ce
     * que la modification directe de `stockActuel` ne faisait pas.
     */
    public function testChaqueEcartEstTraceAuJournalDAudit(): void
    {
        $inventaire = $this->ouvrir();
        $this->compter($inventaire, ['Farine' => 97500, 'Coca' => 26000]);

        $this->service()->valider($inventaire, $this->gerant, 'Comptage mensuel');

        $entrees = $this->em->getRepository(\App\Entity\JournalAudit::class)
            ->findBy(['action' => ActionAudit::INVENTAIRE_VALIDE]);

        $this->assertCount(2, $entrees, 'Une entrée par ligne corrigée.');

        $libelles = array_map(static fn ($e): string => $e->getApres()['libelle'], $entrees);
        $this->assertContains('Farine', $libelles);
        $this->assertContains('Coca', $libelles);
        $this->assertSame('Comptage mensuel', $entrees[0]->getApres()['commentaire']);
    }

    public function testUneLigneSansEcartNeProduitAucunMouvement(): void
    {
        $inventaire = $this->ouvrir();
        $this->compter($inventaire, ['Farine' => 100000]); // exactement le théorique

        $this->service()->valider($inventaire, $this->gerant);

        $this->assertSame([], $this->mouvementsInventaire());
    }

    /**
     * Le piège le plus dangereux du module : une feuille rendue à moitié remplie
     * ne doit pas mettre à zéro ce qui n'a pas été compté.
     */
    public function testUneLigneNonCompteeNeTouchePasAuStock(): void
    {
        $inventaire = $this->ouvrir();
        $this->compter($inventaire, ['Farine' => 97500]); // le sucre n'est pas compté

        $this->service()->valider($inventaire, $this->gerant, 'Comptage partiel');

        $this->em->clear();
        $sucre = $this->em->getRepository(MatierePremiere::class)->findOneBy(['nom' => 'Sucre']);
        $this->assertSame(50000, $sucre->getStockActuel(), 'Le sucre non compté ne bouge pas.');
    }

    /**
     * L'écart est appliqué **en delta**, jamais en écrasant le stock avec la
     * quantité comptée : entre le comptage et la validation, des ventes ont pu
     * déstocker, et les effacer serait pire que l'écart d'origine.
     */
    public function testLesVentesEntreLeComptageEtLaValidationNeSontPasEffacees(): void
    {
        $inventaire = $this->ouvrir();
        $this->compter($inventaire, ['Farine' => 97500]); // −2,5 kg constatés

        // 10 kg partent en production entre-temps.
        $this->farine->setStockActuel($this->farine->getStockActuel() - 10000);
        $this->em->flush();

        $this->service()->valider($inventaire, $this->gerant, 'Comptage mensuel');

        $this->em->clear();
        $farine = $this->em->getRepository(MatierePremiere::class)->findOneBy(['nom' => 'Farine']);

        // 90 (stock du moment) − 2,5 (écart constaté) = 87,5 — et non 97,5,
        // qui aurait rendu les 10 kg consommés.
        $this->assertSame(87500, $farine->getStockActuel());
    }

    public function testUnEcartSansCommentaireEstRefuse(): void
    {
        $inventaire = $this->ouvrir();
        $this->compter($inventaire, ['Farine' => 97500]);

        $this->expectException(\DomainException::class);
        $this->service()->valider($inventaire, $this->gerant);
    }

    public function testUneFeuilleValideeNeSeModifiePlus(): void
    {
        $inventaire = $this->ouvrir();
        $this->compter($inventaire, ['Farine' => 100000]);
        $this->service()->valider($inventaire, $this->gerant);

        $this->expectException(\DomainException::class);
        $this->compter($inventaire, ['Farine' => 50000]);
    }

    public function testUneFeuilleValideeNeSeRevalidePas(): void
    {
        $inventaire = $this->ouvrir();
        $this->compter($inventaire, ['Farine' => 100000]);
        $this->service()->valider($inventaire, $this->gerant);

        $this->expectException(\DomainException::class);
        $this->service()->valider($inventaire, $this->gerant);
    }

    public function testLEcartEstValoriseAuCoutFigeALOuverture(): void
    {
        $inventaire = $this->ouvrir();
        $this->compter($inventaire, ['Farine' => 97500]); // −2,5 kg à 450 FCFA

        // Le coût change après l'ouverture : la valorisation ne doit pas suivre.
        $this->farine->setCoutMoyenPondere(90000);
        $this->em->flush();

        // −2 500 millièmes × 45 000 centimes / 1000 = −112 500 centimes = −1 125 FCFA
        $this->assertSame(-112500, $this->ligne($inventaire, 'Farine')->ecartValorise());
    }

    public function testAbandonnerNeToucheNiLeStockNiLHistorique(): void
    {
        $inventaire = $this->ouvrir();
        $this->compter($inventaire, ['Farine' => 10]);

        $this->service()->abandonner($inventaire);

        $this->em->clear();
        $this->assertSame(100000, $this->em->getRepository(MatierePremiere::class)->findOneBy(['nom' => 'Farine'])->getStockActuel());
        $this->assertSame([], $this->mouvementsInventaire());
        $this->assertNull(static::getContainer()->get(InventaireRepository::class)->enCours());
    }

    // ------------------------------------------------------------------ Écrans

    public function testLesEcransDInventaireRepondent(): void
    {
        $inventaire = $this->ouvrir();

        foreach (['/admin/inventaires', '/admin/inventaires/'.$inventaire->getId(), '/admin/inventaires/'.$inventaire->getId().'/feuille'] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful('Échec du rendu de '.$url);
        }
    }

    /**
     * La feuille imprimée ne montre pas le théorique : lire « 42 » avant de
     * compter suffit à en trouver 42.
     */
    public function testLaFeuilleImprimeeNeRevelePasLeTheorique(): void
    {
        $inventaire = $this->ouvrir();

        $crawler = $this->client->request('GET', '/admin/inventaires/'.$inventaire->getId().'/feuille');
        $texte = $crawler->filter('table')->text();

        $this->assertStringContainsString('Farine', $texte);
        $this->assertStringNotContainsString('100', $texte, 'Le stock théorique ne doit pas figurer sur la feuille à remplir.');
    }

    /**
     * Turbo ne remplace pas la page sur un 200 : un refus doit répondre 422,
     * sinon le gérant reste devant un écran figé sans message.
     */
    public function testUnRefusDeValidationRepond422(): void
    {
        $inventaire = $this->ouvrir();
        $ligne = $this->ligne($inventaire, 'Farine');

        $this->client->request('POST', '/admin/inventaires/'.$inventaire->getId().'/valider', [
            '_token' => $this->jeton($inventaire),
            'comptee' => [$ligne->getId() => '97,5'],
            'commentaire' => '', // écart sans explication
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'commentaire est obligatoire');
    }

    public function testLaSaisieParLEcranConvertitLesUnitesEnMilliemes(): void
    {
        $inventaire = $this->ouvrir();
        $ligne = $this->ligne($inventaire, 'Farine');

        $this->client->request('POST', '/admin/inventaires/'.$inventaire->getId().'/saisir', [
            '_token' => $this->jeton($inventaire),
            'comptee' => [$ligne->getId() => '97,5'],
        ]);

        $this->assertResponseRedirects();
        $this->em->clear();

        $recharge = static::getContainer()->get(InventaireRepository::class)->avecLignes($inventaire->getId());
        $this->assertSame(97500, $this->ligne($recharge, 'Farine')->getQuantiteComptee());
    }

    /**
     * Une case vidée doit vraiment se vider : sinon corriger une faute de frappe
     * obligerait à écrire un zéro, qui viderait le stock à la validation.
     */
    public function testUneCaseVideeRepasseALigneNonComptee(): void
    {
        $inventaire = $this->ouvrir();
        $this->compter($inventaire, ['Farine' => 97500]);
        $ligne = $this->ligne($inventaire, 'Farine');

        $this->client->request('POST', '/admin/inventaires/'.$inventaire->getId().'/saisir', [
            '_token' => $this->jeton($inventaire),
            'comptee' => [$ligne->getId() => ''],
        ]);

        $this->em->clear();
        $recharge = static::getContainer()->get(InventaireRepository::class)->avecLignes($inventaire->getId());
        $this->assertFalse($this->ligne($recharge, 'Farine')->estComptee());
    }

    /**
     * Le stock ne se corrige plus à la main : le champ est **absent** du
     * formulaire de modification, donc non soumettable même en forgeant la requête.
     */
    public function testLeStockNestPlusModifiableDepuisLaFicheMatiere(): void
    {
        $crawler = $this->client->request('GET', '/admin/stock/'.$this->farine->getId().'/modifier');

        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('#matiere_premiere_stockActuel'));
        // Le seuil d'alerte, lui, reste un réglage et non un constat.
        $this->assertCount(1, $crawler->filter('#matiere_premiere_stockMini'));
    }

    public function testLeStockDeDepartResteSaisissableALaCreation(): void
    {
        $crawler = $this->client->request('GET', '/admin/stock/nouvelle');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('#matiere_premiere_stockActuel'));
    }

    // ------------------------------------------------------------- Utilitaires

    private function ligne(Inventaire $inventaire, string $libelle): \App\Entity\LigneInventaire
    {
        foreach ($inventaire->getLignes() as $ligne) {
            if ($libelle === $ligne->getLibelle()) {
                return $ligne;
            }
        }

        $this->fail('Ligne « '.$libelle.' » absente de la feuille.');
    }

    private function jeton(Inventaire $inventaire): string
    {
        $crawler = $this->client->request('GET', '/admin/inventaires/'.$inventaire->getId());

        return $crawler->filter('input[name="_token"]')->attr('value');
    }
}
