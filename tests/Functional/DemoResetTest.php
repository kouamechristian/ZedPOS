<?php

namespace App\Tests\Functional;

use App\Enum\StatutSessionCaisse;
use App\Enum\StatutVente;
use App\Repository\NotificationRepository;
use App\Repository\SessionCaisseRepository;
use App\Repository\UtilisateurRepository;
use App\Service\NotificateurDirigeante;
use App\Service\SyntheseJourneeService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:demo:reset` est ce que le client verra en premier : ce test garantit que
 * l'état de démonstration reste conforme à ce que DEMO.md promet.
 *
 * La commande recharge 30 jours d'historique : elle n'est exécutée **qu'une fois
 * pour toute la classe**, les tests se contentant ensuite de lire l'état obtenu.
 */
class DemoResetTest extends KernelTestCase
{
    private const ECART_ATTENDU = -250000; // −2 500 FCFA

    /** L'état de démonstration a-t-il déjà été construit pour cette classe ? */
    private static bool $etatPrepare = false;

    private EntityManagerInterface $em;
    private Connection $connexion;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->connexion = $this->em->getConnection();

        if (!self::$etatPrepare) {
            $this->executerReset('yes')->assertCommandIsSuccessful();
            self::$etatPrepare = true;
        }

        $this->em->clear();
    }

    public static function tearDownAfterClass(): void
    {
        self::$etatPrepare = false;
    }

    private function executerReset(string $reponse): CommandTester
    {
        $tester = new CommandTester(
            (new Application(static::$kernel))->find('app:demo:reset'),
        );
        $tester->setInputs([$reponse]);
        $tester->execute([]);

        return $tester;
    }

    public function testLesQuatreComptesSontPresents(): void
    {
        $utilisateurs = static::getContainer()->get(UtilisateurRepository::class);

        foreach ([
            'aya.kone@zedpos.ci',
            'koffi.nguessan@zedpos.ci',
            'fatou.traore@zedpos.ci',
            'yao.kouassi@zedpos.ci',
        ] as $email) {
            $this->assertNotNull($utilisateurs->findOneBy(['email' => $email]), $email.' doit exister.');
        }
    }

    public function testTrenteJoursDHistorique(): void
    {
        $jours = (int) $this->connexion->fetchOne(
            'SELECT COUNT(DISTINCT DATE(created_at)) FROM vente',
        );

        $this->assertGreaterThanOrEqual(30, $jours, 'Au moins 30 journées de ventes.');
        $this->assertGreaterThan(1000, (int) $this->connexion->fetchOne('SELECT COUNT(*) FROM vente'));
    }

    public function testJourneeEnCoursAvecUneCaisseOuverteEtDesVentesPassees(): void
    {
        $sessions = static::getContainer()->get(SessionCaisseRepository::class);
        $utilisateurs = static::getContainer()->get(UtilisateurRepository::class);

        $fatou = $utilisateurs->findOneBy(['email' => 'fatou.traore@zedpos.ci']);
        $session = $sessions->ouvertePour($fatou);

        $this->assertNotNull($session, 'La caisse de Fatou reste ouverte : c\'est la journée en cours.');
        $this->assertSame(StatutSessionCaisse::OUVERTE, $session->getStatut());
        $this->assertGreaterThan(0, $session->getFondCaisse(), 'Le fond de caisse est saisi.');

        $ventes = (int) $this->connexion->fetchOne(
            'SELECT COUNT(*) FROM vente WHERE session_caisse_id = ?',
            [$session->getId()],
        );
        $this->assertGreaterThan(0, $ventes, 'Des ventes de la matinée sont déjà passées.');
    }

    public function testStockPlausibleSansAucuneRupture(): void
    {
        $ruptures = (int) $this->connexion->fetchOne(
            'SELECT COUNT(*) FROM matiere_premiere WHERE stock_actuel < stock_mini',
        );
        $negatifs = (int) $this->connexion->fetchOne(
            'SELECT COUNT(*) FROM matiere_premiere WHERE stock_actuel < 0',
        );

        $this->assertSame(0, $negatifs, 'Aucun stock négatif après réapprovisionnement.');
        $this->assertSame(0, $ruptures, 'Aucune rupture : les seules anomalies doivent être celles injectées.');
    }

    // ------------------------------------------------------------- Anomalie 1

    public function testAnomalieUnTicketAnnuleApresEncaissement(): void
    {
        $annulations = $this->connexion->fetchAllAssociative(
            "SELECT numero, total_ttc, motif_annulation FROM vente
             WHERE DATE(created_at) = CURDATE() AND statut = ?",
            [StatutVente::ANNULEE->value],
        );

        $this->assertCount(1, $annulations, 'Exactement une annulation le jour de la démonstration.');
        $this->assertNotEmpty($annulations[0]['motif_annulation'], 'Le motif est conservé.');
        $this->assertGreaterThan(0, (int) $annulations[0]['total_ttc']);
    }

    public function testLAnnulationNotifieLaDirigeante(): void
    {
        $notifications = static::getContainer()->get(NotificationRepository::class)
            ->nonLuesPour('ROLE_DIRIGEANTE');

        $this->assertCount(1, $notifications, 'Une alerte non lue attend la dirigeante.');
        $this->assertSame(NotificateurDirigeante::TYPE_VENTE_ANNULEE, $notifications[0]->getType());
        $this->assertStringContainsString('annulée', $notifications[0]->getTitre());
    }

    public function testLAnnulationEstTraceeAuJournalDAudit(): void
    {
        $traces = (int) $this->connexion->fetchOne(
            "SELECT COUNT(*) FROM journal_audit WHERE action = 'VENTE_ANNULEE'",
        );

        $this->assertSame(1, $traces, 'L\'annulation passe par le vrai chemin métier, donc elle est tracée.');
    }

    // ------------------------------------------------------------- Anomalie 2

    public function testAnomalieDeuxEcartDeCaisseDeMoins2500(): void
    {
        $sessions = $this->connexion->fetchAllAssociative(
            "SELECT s.ecart, s.commentaire_cloture, u.nom
             FROM session_caisse s JOIN utilisateur u ON u.id = s.utilisateur_id
             WHERE s.statut = 'CLOTUREE' AND DATE(s.cloture_at) = CURDATE()",
        );

        $this->assertCount(1, $sessions, 'Une seule caisse clôturée aujourd\'hui.');
        $this->assertSame(self::ECART_ATTENDU, (int) $sessions[0]['ecart'], 'Écart de −2 500 FCFA exactement.');
        $this->assertNotEmpty($sessions[0]['commentaire_cloture'], 'Un écart impose un commentaire.');
        $this->assertSame('Yao Kouassi', $sessions[0]['nom']);
    }

    // -------------------------------------------- Visibilité pour la dirigeante

    public function testLesDeuxAnomaliesRemontentAuTableauDeBord(): void
    {
        $synthese = static::getContainer()->get(SyntheseJourneeService::class)->construire();

        $this->assertTrue($synthese->aDesPointsDeVigilance());
        $this->assertSame(1, $synthese->annulationsNombre);
        $this->assertSame(self::ECART_ATTENDU, $synthese->ecartCaisse);

        // …et rien d'autre ne vient brouiller la démonstration.
        $this->assertSame([], $synthese->rupturesStock, 'Aucune rupture parasite.');
        $this->assertSame(0, $synthese->pertesMontant, 'Aucune perte parasite.');

        $this->assertGreaterThan(0, $synthese->caJour, 'Le CA du jour est renseigné.');
        $this->assertGreaterThan(0, $synthese->nombreTickets);
        $this->assertNotEmpty($synthese->topProduits);
        $this->assertCount(30, $synthese->serie30Jours, 'La courbe a bien 30 points.');
    }

    public function testLeRapportQuotidienEnumereLesDeuxAnomalies(): void
    {
        $tester = new CommandTester(
            (new Application(static::$kernel))->find('app:rapport-quotidien'),
        );
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $sortie = $tester->getDisplay();
        $this->assertStringContainsString('1 annulation', $sortie);
        $this->assertStringContainsString('Écart de caisse : -2 500 FCFA', $sortie);
    }

    public function testLaCommandeRefuseDeSExecuterSansConfirmation(): void
    {
        $ventesAvant = (int) $this->connexion->fetchOne('SELECT COUNT(*) FROM vente');

        $tester = $this->executerReset('no');

        $this->assertStringContainsString('rien n\'a été touché', $tester->getDisplay());
        $this->assertSame(
            $ventesAvant,
            (int) $this->connexion->fetchOne('SELECT COUNT(*) FROM vente'),
            'Un refus ne doit rien effacer.',
        );
    }
}
