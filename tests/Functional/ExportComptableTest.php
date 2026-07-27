<?php

namespace App\Tests\Functional;

use App\Comptabilite\FormatExport;
use App\Comptabilite\JeuEcritures;
use App\Comptabilite\JournalComptable;
use App\Comptabilite\PlanComptable;
use App\Entity\Article;
use App\Entity\FamilleProduit;
use App\Entity\FicheTechnique;
use App\Entity\LigneVente;
use App\Entity\MatierePremiere;
use App\Entity\Reglement;
use App\Entity\SessionCaisse;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Enum\CategorieDepense;
use App\Enum\ModeReglement;
use App\Enum\ModeVente;
use App\Enum\MotifPerte;
use App\Enum\TypeMouvementCaisse;
use App\Service\Comptabilite\ExportComptable;
use App\Service\Comptabilite\GenerateurEcrituresSyscohada;
use App\Service\PerteService;
use App\Service\SessionCaisseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Exports comptables SYSCOHADA : écritures produites, contrôles, formats de
 * fichier, écran comptable et habilitations.
 *
 * L'invariant que ces tests protègent avant tout est **l'équilibre** : un
 * fichier dont le débit ne rejoint pas le crédit est refusé par le logiciel du
 * cabinet, et l'erreur ne se voit qu'à la réception.
 */
class ExportComptableTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Utilisateur $comptable;
    private Utilisateur $dirigeante;
    private Utilisateur $gerant;
    private Utilisateur $caissier;

    /** Baguette : 150 FCFA TTC, TVA 0, fabriquée sur place (fiche technique). */
    private Article $baguette;

    /** Coca : 1 000 FCFA TTC, TVA 18 %, revendu en l'état. */
    private Article $coca;

    private MatierePremiere $farine;
    private int $sequence = 0;

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

        $this->comptable = new Utilisateur('comptable@test.ci', 'Adjoua');
        $this->comptable->setRoles(['ROLE_COMPTABLE'])->setMotDePasse('x');
        $this->em->persist($this->comptable);

        $this->dirigeante = new Utilisateur('aya@test.ci', 'Aya Koné');
        $this->dirigeante->setRoles(['ROLE_DIRIGEANTE'])->setMotDePasse('x');
        $this->em->persist($this->dirigeante);

        $this->gerant = new Utilisateur('koffi@test.ci', 'Koffi');
        $this->gerant->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        $this->em->persist($this->gerant);

        $this->caissier = new Utilisateur('fatou@test.ci', 'Fatou Traoré');
        $this->caissier->setRoles(['ROLE_CAISSIER'])->setCodePin('x');
        $this->em->persist($this->caissier);

        $pains = new FamilleProduit('Pains');
        $this->em->persist($pains);
        $boissons = new FamilleProduit('Boissons');
        $this->em->persist($boissons);

        $this->baguette = new Article('Baguette', 15000, 'pièce');
        $this->baguette->setFamilleProduit($pains)->setTauxTva(0);
        $this->em->persist($this->baguette);
        // La fiche technique fait de la baguette un produit fabriqué sur place.
        $this->em->persist(new FicheTechnique($this->baguette));

        $this->coca = new Article('Coca 33cl', 100000, 'pièce');
        $this->coca->setFamilleProduit($boissons)->setTauxTva(1800);
        $this->em->persist($this->coca);

        $this->farine = new MatierePremiere('Farine', 'kg');
        $this->farine->setStockActuel(100000)->setCoutMoyenPondere(50000);
        $this->em->persist($this->farine);

        $this->em->flush();
    }

    // ------------------------------------------------------------- Utilitaires

    private function generateur(): GenerateurEcrituresSyscohada
    {
        return static::getContainer()->get(GenerateurEcrituresSyscohada::class);
    }

    private function jeuDuJour(): JeuEcritures
    {
        $jour = new \DateTimeImmutable('today');

        return $this->generateur()->construire($jour, $jour);
    }

    private function ouvrirCaisse(): SessionCaisse
    {
        return static::getContainer()->get(SessionCaisseService::class)->ouvrir($this->caissier, 3000000);
    }

    /**
     * Vente construite directement, sans passer par l'API : ces tests portent sur
     * la traduction comptable, pas sur l'encaissement — déjà couvert ailleurs.
     *
     * @param list<array{0: Article, 1: int}>       $lignes       [article, quantité]
     * @param list<array{0: ModeReglement, 1: int}> $reglements
     */
    private function vendre(
        SessionCaisse $session,
        array $lignes,
        array $reglements = [],
        int $remise = 0,
    ): Vente {
        $brutTtc = 0;
        $brutTva = 0;
        foreach ($lignes as [$article, $quantite]) {
            $montantTtc = $quantite * $article->getPrixVenteTtc();
            $brutTtc += $montantTtc;
            $brutTva += $montantTtc - intdiv($montantTtc * 10000, 10000 + $article->getTauxTva());
        }

        // Mêmes formules que EncaissementService : la remise se ventile entre HT
        // et TVA au prorata, et le net en découle.
        $remiseTva = $brutTtc > 0 ? intdiv($remise * $brutTva, $brutTtc) : 0;
        $netTtc = $brutTtc - $remise;
        $netTva = $brutTva - $remiseTva;

        $vente = new Vente(
            $session,
            ModeVente::BOULANGERIE,
            \sprintf('VTEST-%05d', ++$this->sequence),
            $netTtc - $netTva,
            $netTva,
            $netTtc,
        );

        foreach ($lignes as [$article, $quantite]) {
            new LigneVente($vente, $article, $quantite * 1000, $article->getPrixVenteTtc());
        }

        $reglements = [] !== $reglements ? $reglements : [[ModeReglement::ESPECES, $netTtc]];
        $encaisse = 0;
        foreach ($reglements as [$mode, $montant]) {
            new Reglement($vente, $mode, $montant);
            $encaisse += $montant;
        }

        $vente->enregistrerRemiseEtRendu($remise, $remise > 0 ? 'Test' : null, $encaisse - $netTtc);

        $this->em->persist($vente);
        $this->em->flush();

        return $vente;
    }

    /** @return list<array{compte: string, debit: int, credit: int}> */
    private function lignes(JeuEcritures $jeu, ?JournalComptable $journal = null): array
    {
        $lignes = [];
        foreach ($jeu->ecritures as $ecriture) {
            if (null !== $journal && $ecriture->journal !== $journal) {
                continue;
            }
            foreach ($ecriture->lignes as $ligne) {
                $lignes[] = ['compte' => $ligne->compte, 'debit' => $ligne->debit, 'credit' => $ligne->credit];
            }
        }

        return $lignes;
    }

    private function cumul(JeuEcritures $jeu, PlanComptable $compte, string $sens): int
    {
        $total = 0;
        foreach ($this->lignes($jeu) as $ligne) {
            if ($ligne['compte'] === $compte->value) {
                $total += $ligne[$sens];
            }
        }

        return $total;
    }

    // ---------------------------------------------------------- Journal ventes

    public function testUneJourneeDeVentesProduitUneEcritureParRapportZ(): void
    {
        $session = $this->ouvrirCaisse();
        $this->vendre($session, [[$this->baguette, 2]]);
        $this->vendre($session, [[$this->coca, 1]]);

        $jeu = $this->jeuDuJour();

        $ventes = array_filter(
            $jeu->ecritures,
            static fn ($e): bool => JournalComptable::VENTES === $e->journal,
        );

        $this->assertCount(1, $ventes, 'Les tickets sont centralisés, pas repris un par un.');
        $this->assertSame('Z'.$session->getId(), reset($ventes)->piece);
        $this->assertStringContainsString('Fatou Traoré', reset($ventes)->libelle);
        $this->assertStringContainsString('2 tickets', reset($ventes)->libelle);
    }

    public function testLeChiffreDAffairesEstVentileEntreProduitsFinisEtMarchandises(): void
    {
        $session = $this->ouvrirCaisse();
        $this->vendre($session, [[$this->baguette, 2]]);  // 300 FCFA, TVA 0, fiche technique
        $this->vendre($session, [[$this->coca, 1]]);      // 1 000 FCFA TTC, TVA 18 %

        $jeu = $this->jeuDuJour();

        // Baguette : pas de TVA, le HT est le TTC.
        $this->assertSame(30000, $this->cumul($jeu, PlanComptable::VENTES_PRODUITS_FINIS, 'credit'));
        // Coca : 100 000 centimes TTC → 84 745 HT, 15 255 de TVA.
        $this->assertSame(84745, $this->cumul($jeu, PlanComptable::VENTES_MARCHANDISES, 'credit'));
        $this->assertSame(15255, $this->cumul($jeu, PlanComptable::TVA_FACTUREE, 'credit'));
    }

    public function testLeCompteDeVenteDeLaFamillePrimeSurLaNatureDeLArticle(): void
    {
        $this->baguette->getFamilleProduit()->setCompteVente(PlanComptable::VENTES_SERVICES->value);
        $this->em->flush();

        $session = $this->ouvrirCaisse();
        $this->vendre($session, [[$this->baguette, 2]]);

        $jeu = $this->jeuDuJour();

        $this->assertSame(30000, $this->cumul($jeu, PlanComptable::VENTES_SERVICES, 'credit'));
        $this->assertSame(0, $this->cumul($jeu, PlanComptable::VENTES_PRODUITS_FINIS, 'credit'));
    }

    public function testChaqueModeDeReglementDebiteSonCompteDeTresorerie(): void
    {
        $session = $this->ouvrirCaisse();
        $this->vendre($session, [[$this->coca, 1]], [[ModeReglement::WAVE, 60000], [ModeReglement::ESPECES, 40000]]);
        $this->vendre($session, [[$this->baguette, 1]], [[ModeReglement::ORANGE_MONEY, 15000]]);

        $jeu = $this->jeuDuJour();

        $this->assertSame(60000, $this->cumul($jeu, PlanComptable::MONNAIE_ELECTRONIQUE_WAVE, 'debit'));
        $this->assertSame(15000, $this->cumul($jeu, PlanComptable::MONNAIE_ELECTRONIQUE_ORANGE, 'debit'));
        $this->assertSame(40000, $this->cumul($jeu, PlanComptable::CAISSE, 'debit'));
    }

    public function testLeRenduDeMonnaieEstDeduitDesEspeces(): void
    {
        $session = $this->ouvrirCaisse();
        // 300 FCFA de baguettes réglés avec un billet de 500 : 200 FCFA rendus.
        $this->vendre($session, [[$this->baguette, 2]], [[ModeReglement::ESPECES, 50000]]);

        $jeu = $this->jeuDuJour();

        $this->assertSame(30000, $this->cumul($jeu, PlanComptable::CAISSE, 'debit'), 'Le tiroir ne gagne que le net.');
        $this->assertTrue($jeu->estEquilibre());
    }

    public function testUneRemiseEstPorteeEnRabaisAccordeEtNonEnCharge(): void
    {
        $session = $this->ouvrirCaisse();
        // 1 000 FCFA de baguettes (TVA 0), 100 FCFA de remise.
        $this->vendre($session, [[$this->baguette, 10]], [], remise: 10000);

        $jeu = $this->jeuDuJour();

        $this->assertSame(10000, $this->cumul($jeu, PlanComptable::RRR_ACCORDES, 'debit'));
        $this->assertSame(150000, $this->cumul($jeu, PlanComptable::VENTES_PRODUITS_FINIS, 'credit'),
            'Le produit est crédité brut, la remise venant en diminution au débit.');
        $this->assertSame(140000, $this->cumul($jeu, PlanComptable::CAISSE, 'debit'));
        $this->assertTrue($jeu->estEquilibre());
    }

    public function testLesVentesAnnuleesSontExclues(): void
    {
        $session = $this->ouvrirCaisse();
        $this->vendre($session, [[$this->baguette, 2]]);
        $annulee = $this->vendre($session, [[$this->baguette, 4]]);

        $annulee->annuler('Erreur de saisie');
        $this->em->flush();

        $jeu = $this->jeuDuJour();

        $this->assertSame(30000, $this->cumul($jeu, PlanComptable::VENTES_PRODUITS_FINIS, 'credit'));
    }

    // ---------------------------------------------------------- Journal caisse

    public function testUneDepenseDeCaisseDebiteSonCompteDeCharge(): void
    {
        $session = $this->ouvrirCaisse();
        $sessions = static::getContainer()->get(SessionCaisseService::class);
        $sessions->enregistrerMouvement($session, $this->caissier, TypeMouvementCaisse::DEPENSE, 500000, CategorieDepense::TRANSPORT, 'Livraison');

        $jeu = $this->jeuDuJour();

        $this->assertSame(500000, $this->cumul($jeu, PlanComptable::TRANSPORTS, 'debit'));
        $this->assertSame(500000, $this->cumul($jeu, PlanComptable::CAISSE, 'credit'));
    }

    public function testUneAvanceAuPersonnelEstUneCreanceNonUneCharge(): void
    {
        $session = $this->ouvrirCaisse();
        static::getContainer()->get(SessionCaisseService::class)->enregistrerMouvement(
            $session, $this->caissier, TypeMouvementCaisse::DEPENSE, 1000000, CategorieDepense::AVANCE_PERSONNEL, 'Avance Yao',
        );

        $jeu = $this->jeuDuJour();

        $this->assertSame(1000000, $this->cumul($jeu, PlanComptable::AVANCES_PERSONNEL, 'debit'));
        $this->assertSame(0, $this->cumul($jeu, PlanComptable::CHARGES_DIVERSES, 'debit'));
    }

    public function testUneSortieDeCaisseVaEnVirementDeFonds(): void
    {
        $session = $this->ouvrirCaisse();
        static::getContainer()->get(SessionCaisseService::class)->enregistrerMouvement(
            $session, $this->caissier, TypeMouvementCaisse::SORTIE, 2000000, null, 'Remise au coffre',
        );

        $jeu = $this->jeuDuJour();

        $this->assertSame(2000000, $this->cumul($jeu, PlanComptable::VIREMENTS_INTERNES, 'debit'));
        $this->assertSame(2000000, $this->cumul($jeu, PlanComptable::CAISSE, 'credit'));
    }

    public function testUnManquantDeCaisseEstUneCharge(): void
    {
        $session = $this->ouvrirCaisse();
        $this->vendre($session, [[$this->baguette, 2]]);

        // Théorique = 30 000 (fond) + 300 de ventes ; on compte 250 de moins.
        $sessions = static::getContainer()->get(SessionCaisseService::class);
        $sessions->cloturer($session, 3005000, 'Manquant constaté');

        $jeu = $this->jeuDuJour();

        $this->assertSame(25000, $this->cumul($jeu, PlanComptable::CHARGES_DIVERSES, 'debit'));
        $this->assertTrue($jeu->estEquilibre());
    }

    public function testUnExcedentDeCaisseEstUnProduit(): void
    {
        $session = $this->ouvrirCaisse();
        $this->vendre($session, [[$this->baguette, 2]]);

        static::getContainer()->get(SessionCaisseService::class)->cloturer($session, 3035000, 'Excédent constaté');

        $jeu = $this->jeuDuJour();

        $this->assertSame(5000, $this->cumul($jeu, PlanComptable::PRODUITS_DIVERS, 'credit'));
        $this->assertTrue($jeu->estEquilibre());
    }

    // ------------------------------------------------- Journal des OD (pertes)

    public function testUnePerteDeMatiereSortDuStockParSonCompteDeVariation(): void
    {
        static::getContainer()->get(PerteService::class)
            ->enregistrer(MotifPerte::PERIME, $this->farine, null, 2000, 'Sac éventré');

        $jeu = $this->jeuDuJour();

        // 2 kg au coût moyen de 500 FCFA le kg = 1 000 FCFA.
        $this->assertSame(100000, $this->cumul($jeu, PlanComptable::VARIATION_STOCKS_MATIERES, 'debit'));
        $this->assertSame(100000, $this->cumul($jeu, PlanComptable::STOCK_MATIERES, 'credit'));
    }

    // --------------------------------------------------------------- Contrôles

    public function testLesEcrituresSontToujoursEquilibrees(): void
    {
        $session = $this->ouvrirCaisse();
        $this->vendre($session, [[$this->baguette, 3], [$this->coca, 2]], [[ModeReglement::MTN_MOMO, 215000], [ModeReglement::ESPECES, 50000]], remise: 10000);
        $this->vendre($session, [[$this->coca, 7]]);

        static::getContainer()->get(SessionCaisseService::class)->enregistrerMouvement(
            $session, $this->caissier, TypeMouvementCaisse::DEPENSE, 300000, CategorieDepense::DIVERS, 'Divers',
        );

        $jeu = $this->jeuDuJour();

        $this->assertTrue($jeu->estEquilibre(), 'Débit et crédit doivent se rejoindre au centime.');
        $this->assertSame($jeu->totalDebit(), $jeu->totalCredit());

        foreach ($jeu->ecritures as $ecriture) {
            $debit = array_sum(array_column($ecriture->lignes, 'debit'));
            $credit = array_sum(array_column($ecriture->lignes, 'credit'));
            $this->assertSame($debit, $credit, 'Écriture déséquilibrée : '.$ecriture->piece);
        }
    }

    public function testLesControlesRapprochentLesEcrituresDesChiffresDeLApplication(): void
    {
        $session = $this->ouvrirCaisse();
        $this->vendre($session, [[$this->coca, 3]], [[ModeReglement::WAVE, 200000], [ModeReglement::ESPECES, 150000]]);
        $this->vendre($session, [[$this->baguette, 5]], [[ModeReglement::ESPECES, 100000]]);

        static::getContainer()->get(SessionCaisseService::class)->enregistrerMouvement(
            $session, $this->caissier, TypeMouvementCaisse::DEPENSE, 250000, CategorieDepense::ENTRETIEN, 'Nettoyage',
        );

        $jeu = $this->jeuDuJour();

        $this->assertNotEmpty($jeu->controles);
        foreach ($jeu->controles as $controle) {
            $this->assertTrue(
                $controle->estBon(),
                \sprintf('Contrôle « %s » : attendu %d, obtenu %d.', $controle->libelle, $controle->attendu, $controle->obtenu),
            );
        }
        $this->assertTrue($jeu->controlesSontBons());
    }

    public function testLaBalanceCumuleLesComptesEtSeSoldeAZero(): void
    {
        $session = $this->ouvrirCaisse();
        $this->vendre($session, [[$this->coca, 4]]);

        $balance = $this->jeuDuJour()->balance();

        $this->assertNotEmpty($balance);
        $this->assertSame(0, array_sum(array_column($balance, 'solde')), 'Une balance se solde à zéro.');

        // Ordre comptable : 4431 avant 5711, et 605 avant 6056 (voir JeuEcritures).
        $comptes = array_column($balance, 'compte');
        $this->assertSame($comptes, $this->trierCommeUneBalance($comptes));
    }

    /**
     * @param list<string> $comptes
     *
     * @return list<string>
     */
    private function trierCommeUneBalance(array $comptes): array
    {
        usort($comptes, static fn (string $a, string $b): int => str_pad($a, 8, '0') <=> str_pad($b, 8, '0'));

        return $comptes;
    }

    // ----------------------------------------------------------------- Formats

    public function testLeCsvDesEcrituresEstLisibleParUnTableur(): void
    {
        $session = $this->ouvrirCaisse();
        $this->vendre($session, [[$this->coca, 1]]);

        $contenu = static::getContainer()->get(ExportComptable::class)
            ->rendre($this->jeuDuJour(), FormatExport::ECRITURES_CSV);

        $this->assertStringStartsWith("\u{FEFF}", $contenu, 'Sans marque d\'ordre, Excel lirait les accents en ANSI.');
        $this->assertStringContainsString('Journal;Libellé journal;Date;Pièce;Compte', $contenu);
        $this->assertStringContainsString("\r\n", $contenu);
        // 1 000 FCFA TTC → 847,45 HT : virgule décimale, jamais de point.
        $this->assertStringContainsString('847,45', $contenu);
        $this->assertStringNotContainsString('847.45', $contenu);
    }

    public function testLeFecPorteSesDixHuitColonnesSurChaqueLigne(): void
    {
        $session = $this->ouvrirCaisse();
        $this->vendre($session, [[$this->coca, 1]]);

        $jeu = $this->jeuDuJour();
        $contenu = static::getContainer()->get(ExportComptable::class)->rendre($jeu, FormatExport::FEC);

        $lignes = array_filter(explode("\r\n", $contenu));
        $this->assertGreaterThan(1, \count($lignes));

        foreach ($lignes as $ligne) {
            $this->assertCount(18, explode("\t", $ligne), 'Le FEC compte 18 colonnes, même vides.');
        }

        $entete = explode("\t", (string) reset($lignes));
        $this->assertSame('JournalCode', $entete[0]);
        $this->assertSame('Idevise', $entete[17]);

        // Toutes les lignes d'une écriture partagent le même numéro : c'est ce
        // qui les relie entre elles à l'import.
        $premiere = explode("\t", (string) next($lignes));
        $this->assertSame('VE00001', $premiere[2]);
        $this->assertMatchesRegularExpression('/^\d{8}$/', $premiere[3]);
    }

    public function testLaBalanceRappelleLesControlesEnPied(): void
    {
        $session = $this->ouvrirCaisse();
        $this->vendre($session, [[$this->baguette, 2]]);

        $contenu = static::getContainer()->get(ExportComptable::class)
            ->rendre($this->jeuDuJour(), FormatExport::BALANCE_CSV);

        $this->assertStringContainsString('Compte;Libellé;Débit;Crédit', $contenu);
        $this->assertStringContainsString('Contrôles;Attendu;Écritures', $contenu);
        $this->assertStringContainsString('Équilibre débit / crédit', $contenu);
        $this->assertStringNotContainsString('ANOMALIE', $contenu);
    }

    public function testLeNomDuFichierFecSuitLaConventionDuFormat(): void
    {
        $jeu = $this->jeuDuJour();
        $nom = static::getContainer()->get(ExportComptable::class)->nomFichier($jeu, FormatExport::FEC);

        $this->assertMatchesRegularExpression('/^[A-Z0-9]+FEC\d{8}\.txt$/', $nom);
        $this->assertStringEndsWith($jeu->au->format('Ymd').'.txt', $nom);
    }

    // ------------------------------------------------------------------- Écran

    public function testLeComptableAccedeALEcranDExport(): void
    {
        $session = $this->ouvrirCaisse();
        $this->vendre($session, [[$this->coca, 2]]);

        $this->client->loginUser($this->comptable);
        $crawler = $this->client->request('GET', '/comptabilite');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('SYSCOHADA', $crawler->filter('title')->text());
        $this->assertStringContainsString('Contrôles conformes', $crawler->text());
        $this->assertStringContainsString('Balance générale', $crawler->text());
    }

    public function testLaDirigeanteAccedeAussiALEspaceComptable(): void
    {
        $this->client->loginUser($this->dirigeante);
        $this->client->request('GET', '/comptabilite');

        $this->assertResponseIsSuccessful();
    }

    /**
     * Le gérant accède à l'espace comptable, écran **et** fichiers. La restriction
     * qui le lui interdisait a été levée : il n'est plus le seul encadrant à devoir
     * demander les écritures à quelqu'un d'autre.
     */
    public function testLeGerantAccedeALEspaceComptableEtAuxExports(): void
    {
        $session = $this->ouvrirCaisse();
        $this->vendre($session, [[$this->coca, 2]]);

        $this->client->loginUser($this->gerant);

        $this->client->request('GET', '/comptabilite');
        $this->assertResponseIsSuccessful();

        $jour = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $this->client->request('GET', '/comptabilite/telecharger/fec?du='.$jour.'&au='.$jour);
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('JournalCode', (string) $this->client->getResponse()->getContent());
    }

    /**
     * Le caissier, lui, reste dehors : encaisser ne donne aucun droit sur les
     * comptes de l'établissement.
     */
    public function testLeCaissierNAccedePasAuxExports(): void
    {
        $this->client->loginUser($this->caissier);

        $this->client->request('GET', '/comptabilite');
        $this->assertResponseStatusCodeSame(403);

        // La porte de derrière est fermée elle aussi : un lien de téléchargement
        // recopié ne doit pas sortir les écritures.
        $this->client->request('GET', '/comptabilite/telecharger/fec');
        $this->assertResponseStatusCodeSame(403);
    }

    /**
     * Le gérant trouve l'espace comptable depuis sa navigation : un droit sans
     * chemin pour l'atteindre n'existe pas pour l'utilisateur.
     */
    public function testLeBackOfficeMeneALEspaceComptable(): void
    {
        $this->client->loginUser($this->gerant);
        $crawler = $this->client->request('GET', '/admin');

        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(
            0,
            $crawler->filter('nav a[href="/comptabilite"]')->count(),
            'La barre latérale du back-office doit mener à la comptabilité.',
        );
    }

    public function testLeTelechargementRenvoieUnFichierAttache(): void
    {
        $session = $this->ouvrirCaisse();
        $this->vendre($session, [[$this->baguette, 2]]);

        $this->client->loginUser($this->comptable);
        $jour = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $this->client->request('GET', '/comptabilite/telecharger/fec?du='.$jour.'&au='.$jour);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertStringContainsString('attachment;', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $this->assertStringContainsString('JournalCode', (string) $this->client->getResponse()->getContent());
    }

    public function testUnFormatInconnuEstRefuse(): void
    {
        $this->client->loginUser($this->comptable);
        $this->client->request('GET', '/comptabilite/telecharger/pdf');

        $this->assertResponseStatusCodeSame(404);
    }

    // ---------------------------------------------------------------- Commande

    public function testLaCommandeProduitLeMemeFichierQueLEcran(): void
    {
        $session = $this->ouvrirCaisse();
        $this->vendre($session, [[$this->coca, 3]]);

        $jour = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $fichier = sys_get_temp_dir().'/zedpos-export-'.uniqid().'.csv';

        $commande = (new Application(static::$kernel))->find('app:export-comptable');
        $tester = new CommandTester($commande);
        $tester->execute(['--du' => $jour, '--au' => $jour, '--format' => 'ecritures', '--sortie' => $fichier]);

        $tester->assertCommandIsSuccessful();
        $this->assertFileExists($fichier);

        $attendu = static::getContainer()->get(ExportComptable::class)
            ->rendre($this->jeuDuJour(), FormatExport::ECRITURES_CSV);
        $this->assertSame($attendu, file_get_contents($fichier));

        unlink($fichier);
    }

    public function testLaCommandeRefuseUnFormatInconnu(): void
    {
        $commande = (new Application(static::$kernel))->find('app:export-comptable');
        $tester = new CommandTester($commande);

        $this->assertSame(2, $tester->execute(['--format' => 'pdf']));
        $this->assertStringContainsString('Format inconnu', $tester->getDisplay());
    }
}
