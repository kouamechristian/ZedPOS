<?php

namespace App\Tests\Functional;

use App\Entity\Arrete;
use App\Entity\Article;
use App\Entity\BonDotation;
use App\Entity\Emplacement;
use App\Entity\LigneDotation;
use App\Entity\LigneVente;
use App\Entity\Reglement;
use App\Entity\Utilisateur;
use App\Entity\Vendeur;
use App\Entity\Vente;
use App\Enum\GranulariteRapport;
use App\Enum\ModeReglement;
use App\Enum\ModeVente;
use App\Enum\MotifRetour;
use App\Enum\TypeEmplacement;
use App\Service\ArreteService;
use App\Service\BonDotationService;
use App\Service\Rapport\FiltreRapport;
use App\Service\Rapport\RapportStands;
use App\Service\SessionCaisseService;
use App\Service\SyntheseJourneeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Rapports des stands : justes à la journée sous un point mensuel, sans double
 * comptage avec la caisse, suggestion de dotation, écrans et CSV.
 *
 * Dates fixées en août 2026, « aujourd'hui » des services au 11/09/2026.
 */
class RapportStandsTest extends WebTestCase
{
    private const AUJOURDHUI = '2026-09-11';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Utilisateur $gerante;
    private Vendeur $awa;
    private Emplacement $stand;
    private Article $baguette;
    private Article $croissant;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $connexion = $this->em->getConnection();
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['remboursement_dette', 'dette_vendeur', 'ligne_retour', 'bon_retour', 'ligne_dotation', 'bon_dotation', 'arrete', 'vendeur', 'ligne_fiche_technique', 'fiche_technique', 'ligne_vente', 'reglement', 'vente', 'mouvement_caisse', 'session_caisse', 'mouvement_stock', 'stock_courant', 'perte', 'article', 'journal_audit', 'notification', 'utilisateur'] as $table) {
            $connexion->executeStatement('DELETE FROM '.$table);
        }
        $connexion->executeStatement("DELETE FROM emplacement WHERE code <> 'DEPOT'");
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $this->gerante = (new Utilisateur('mariam@test.ci', 'Mariam'))->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        $this->awa = new Vendeur('Awa');
        $this->stand = (new Emplacement('STAND-GARE', 'Stand de la gare', TypeEmplacement::STAND))
            ->setVendeurHabituel($this->awa)
            ->setTauxCommission(1000);   // 10 %
        $this->baguette = (new Article('Baguette', 15000, 'pièce'))->setTauxTva(0);
        $this->croissant = (new Article('Croissant', 25000, 'pièce'))->setTauxTva(0);

        foreach ([$this->gerante, $this->awa, $this->stand, $this->baguette, $this->croissant] as $entite) {
            $this->em->persist($entite);
        }
        $this->em->flush();
    }

    // ------------------------------------------------------------- Outils

    private function aujourdhui(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::AUJOURDHUI);
    }

    /** @param array<int, int> $unites unités par article */
    private function doter(string $date, array $unites): BonDotation
    {
        $service = static::getContainer()->get(BonDotationService::class);
        $bon = $service->creer($this->stand, $this->awa, new \DateTimeImmutable($date), $unites, $this->gerante);
        $service->valider($bon, $this->gerante);

        return $bon;
    }

    /** @param list<array{0: Article, 1: int, 2: MotifRetour}> $retours unités */
    private function arreter(string $fin, array $retours = []): Arrete
    {
        $points = static::getContainer()->get(ArreteService::class);
        $saisie = array_map(static fn (array $r): array => [$r[0], $r[1] * 1000, $r[2]], $retours);
        $arrete = $points->enregistrer($this->stand, new \DateTimeImmutable($fin), $this->awa, $saisie, null, null, null, $this->gerante, $this->aujourdhui());
        $arrete = $points->enregistrer($this->stand, new \DateTimeImmutable($fin), $this->awa, $saisie, $arrete->getNetARemettre(), null, null, $this->gerante, $this->aujourdhui());
        $points->valider($arrete, $this->gerante, $this->aujourdhui());

        return $arrete;
    }

    private function filtre(string $du, string $au, GranulariteRapport $granularite = GranulariteRapport::JOUR): FiltreRapport
    {
        return FiltreRapport::creer(new \DateTimeImmutable($du), new \DateTimeImmutable($au), $granularite);
    }

    private function rapports(): RapportStands
    {
        return static::getContainer()->get(RapportStands::class);
    }

    // ------------------------------------------------ Justes à la journée

    /**
     * Un point mensuel ne fausse pas le rapport journalier : chaque ligne a reçu sa
     * part du vendu à la validation, lue à la date de sa dotation — et la somme
     * retombe sur l'arrêté.
     */
    public function testUnPointMensuelDonneDesChiffresJustesAuJour(): void
    {
        $this->doter('2026-08-03', [$this->baguette->getId() => 40]);
        $this->doter('2026-08-05', [$this->baguette->getId() => 30, $this->croissant->getId() => 12]);
        $arrete = $this->arreter('2026-08-31', [
            [$this->baguette, 5, MotifRetour::INVENDU],
            [$this->baguette, 2, MotifRetour::CASSE],
            [$this->croissant, 2, MotifRetour::INVENDU],
        ]);

        $rapport = $this->rapports()->parStand($this->filtre('2026-08-01', '2026-08-31'));

        // Baguettes : 63 vendues, les 40 du lundi d'abord. Croissants : 10, à 250 F.
        [$lundi, $mercredi] = $rapport['detail'];
        $this->assertSame(['03/08', 40000, 0, 0, 600000], [$lundi['tranche'], $lundi['vendue'], $lundi['retournee'], $lundi['perdue'], $lundi['ca']]);
        $this->assertSame(['05/08', 33000, 7000, 2000, 595000], [$mercredi['tranche'], $mercredi['vendue'], $mercredi['retournee'], $mercredi['perdue'], $mercredi['ca']]);
        $this->assertSame(30000, $mercredi['valeurPertes'], '2 baguettes cassées à 150 F.');

        // La somme retombe au centime sur l'arrêté.
        $total = $rapport['total'];
        $this->assertSame([$arrete->getMontantAttendu(), $arrete->getRemunerationVendeur(), $arrete->getNetARemettre()], [$total['ca'], $total['remuneration'], $total['net']]);
        $this->assertSame([60000, 59500], [$lundi['remuneration'], $mercredi['remuneration']]);
        $this->assertSame(0, $total['enAttente']);
        $this->assertSame(1, $total['points']);
        // Taux d'invendus : 7 retournés sur 82 arrêtés.
        $this->assertSame(intdiv(7000 * 10000, 82000), $total['tauxInvendus']);

        // Le même mois en une tranche donne les mêmes totaux.
        $mois = $this->rapports()->parStand($this->filtre('2026-08-01', '2026-08-31', GranulariteRapport::MOIS));
        $this->assertCount(1, $mois['detail']);
        $this->assertSame($total['ca'], $mois['detail'][0]['ca']);

        // Un jour seul ne voit que sa part.
        $jour = $this->rapports()->parStand($this->filtre('2026-08-05', '2026-08-05'));
        $this->assertSame(595000, $jour['total']['ca']);
    }

    public function testAvantLePointLeConfieEstEnAttenteEtLAnnulationLyRemet(): void
    {
        $this->doter('2026-08-03', [$this->baguette->getId() => 40]);

        $avant = $this->rapports()->parStand($this->filtre('2026-08-01', '2026-08-31'))['total'];
        $this->assertSame([40000, 40000, 0, 0], [$avant['confiee'], $avant['enAttente'], $avant['vendue'], $avant['ca']]);

        $arrete = $this->arreter('2026-08-31');
        $this->assertSame(0, $this->rapports()->parStand($this->filtre('2026-08-01', '2026-08-31'))['total']['enAttente']);

        static::getContainer()->get(ArreteService::class)->annuler($arrete, 'Erreur de période', $this->gerante);

        $apres = $this->rapports()->parStand($this->filtre('2026-08-01', '2026-08-31'))['total'];
        $this->assertSame([40000, 0, 0, 0], [$apres['enAttente'], $apres['vendue'], $apres['ca'], $apres['points']]);
        $this->em->clear();
        $this->assertNull($this->em->getRepository(LigneDotation::class)->findOneBy([])->getQteVendue());
    }

    // ------------------------------------------------ Pas de double comptage

    /**
     * Une vente de stand ne crée aucune `Vente` : la caisse lit `vente`, les stands
     * leurs lignes de dotation, le CA global est la somme des deux — et le pilotage,
     * qui ne lit que la caisse, ne bouge pas quand un point est validé.
     */
    public function testLeCaGlobalNeCompteRienDeuxFois(): void
    {
        $caissier = (new Utilisateur('fatou@test.ci', 'Fatou'))->setRoles(['ROLE_CAISSIER'])->setCodePin('x');
        $this->em->persist($caissier);
        $this->em->flush();
        $session = static::getContainer()->get(SessionCaisseService::class)->ouvrir($caissier, 0);

        $vente = new Vente($session, ModeVente::BOULANGERIE, 'VT-00001', 100000, 0, 100000);
        (new \ReflectionProperty($vente, 'createdAt'))->setValue($vente, new \DateTimeImmutable('2026-08-05 09:00'));
        new LigneVente($vente, $this->baguette, 1000, 100000);
        new Reglement($vente, ModeReglement::ESPECES, 100000);
        $this->em->persist($vente);
        $this->em->flush();

        $syntheses = static::getContainer()->get(SyntheseJourneeService::class);
        $caPilotageAvant = $syntheses->construire(new \DateTimeImmutable('2026-08-05'))->caJour;

        $this->doter('2026-08-05', [$this->baguette->getId() => 30]);
        $this->arreter('2026-08-31', [[$this->baguette, 10, MotifRetour::INVENDU]]);   // 20 vendues : 3 000 F

        $this->assertSame(1, $this->em->getRepository(Vente::class)->count([]), 'Le point n\'a créé aucune vente.');
        $this->assertSame($caPilotageAvant, $syntheses->construire(new \DateTimeImmutable('2026-08-05'))->caJour, 'Le CA du pilotage reste celui de la caisse.');

        $comparatif = $this->rapports()->comparatif($this->filtre('2026-08-01', '2026-08-31', GranulariteRapport::MOIS));
        $total = $comparatif['total'];
        $this->assertSame([100000, 1, 300000, 270000, 400000], [$total['caCaisse'], $total['tickets'], $total['caStands'], $total['netStands'], $total['caGlobal']]);
        $this->assertSame(7500, $total['partStands']);
        $this->assertSame($total['caGlobal'], array_sum(array_column($comparatif['lignes'], 'caGlobal')));
    }

    // ---------------------------------------------------------- Suggestion

    /** Moyenne du vendu des quatre derniers mêmes jours de semaine, arrondie à l'unité. */
    public function testLaSuggestionMoyenneLesQuatreDerniersMemesJours(): void
    {
        foreach (['2026-08-03' => 10, '2026-08-10' => 20, '2026-08-17' => 30, '2026-08-24' => 40, '2026-08-31' => 50] as $lundi => $baguettes) {
            $unites = [$this->baguette->getId() => $baguettes];
            if ($lundi >= '2026-08-24') {
                $unites[$this->croissant->getId()] = '2026-08-24' === $lundi ? 3 : 4;
            }
            $this->doter($lundi, $unites);
        }
        $this->doter('2026-08-04', [$this->baguette->getId() => 100]);   // un mardi : ignoré
        $this->doter('2026-09-07', [$this->baguette->getId() => 999]);   // pas encore arrêté : ignoré

        $this->assertSame([], $this->rapports()->suggestion((int) $this->stand->getId(), new \DateTimeImmutable('2026-09-14'))['quantites'], 'Rien d\'arrêté : pas de suggestion.');

        $this->arreter('2026-08-31');   // tout vendu

        $suggestion = $this->rapports()->suggestion((int) $this->stand->getId(), new \DateTimeImmutable('2026-09-07'));

        $this->assertSame(['2026-08-31', '2026-08-24', '2026-08-17', '2026-08-10'], $suggestion['jours']);
        // (50 + 40 + 30 + 20) / 4 = 35 ; croissants (4 + 3 + 0 + 0) / 4 = 1,75 → 2.
        $this->assertEquals([(string) $this->baguette->getId() => 35, (string) $this->croissant->getId() => 2], $suggestion['quantites']);
    }

    // ------------------------------------------------------------- Écrans

    public function testLesRapportsSontReservesALaGestionDesStands(): void
    {
        $caissier = (new Utilisateur('fatou@test.ci', 'Fatou'))->setRoles(['ROLE_CAISSIER'])->setCodePin('x');
        $comptable = (new Utilisateur('cabinet@test.ci', 'Cabinet'))->setRoles(['ROLE_COMPTABLE'])->setMotDePasse('x');
        $this->em->persist($caissier);
        $this->em->persist($comptable);
        $this->em->flush();

        foreach (['/admin/rapports-stands', '/admin/rapports-stands/vendeurs.csv'] as $url) {
            foreach ([$caissier, $comptable] as $compte) {
                $this->client->loginUser($compte);
                $this->client->request('GET', $url);
                $this->assertResponseStatusCodeSame(403, $compte->getEmail().' sur '.$url);
            }
        }
    }

    public function testLesQuatreRapportsEtLeursExports(): void
    {
        $this->doter('2026-08-03', [$this->baguette->getId() => 40]);
        $this->arreter('2026-08-31', [[$this->baguette, 4, MotifRetour::INVENDU]]);
        $this->client->loginUser($this->gerante);
        $filtres = 'du=2026-08-01&au=2026-08-31&granularite=semaine';

        foreach (['stands' => 'Stand de la gare', 'vendeurs' => 'Awa', 'comparatif' => 'CA global', 'produits' => 'Baguette'] as $rapport => $attendu) {
            $crawler = $this->client->request('GET', '/admin/rapports-stands/'.$rapport.'?'.$filtres);
            $this->assertResponseIsSuccessful($rapport);
            $this->assertSelectorTextContains('main', $attendu);
            $this->assertSame('2026-08-01', $crawler->filter('input[name="du"]')->attr('value'));
            $this->assertCount(1, $crawler->filter('a[data-turbo="false"][href*="/admin/rapports-stands/'.$rapport.'.csv"][href*="granularite=semaine"]'), 'Le CSV reprend les filtres.');

            $this->client->request('GET', '/admin/rapports-stands/'.$rapport.'.csv?'.$filtres);
            $this->assertResponseIsSuccessful();
            $reponse = $this->client->getResponse();
            $this->assertStringStartsWith('text/csv', (string) $reponse->headers->get('Content-Type'));
            $this->assertStringContainsString('filename=rapport-'.$rapport.'_2026-08-01_2026-08-31_semaine.csv', (string) $reponse->headers->get('Content-Disposition'));
            $this->assertStringStartsWith("\u{FEFF}", (string) $reponse->getContent());
            $this->assertStringContainsString($attendu, (string) $reponse->getContent());
        }

        // 36 baguettes vendues à 150 F : 5 400 F ; en CSV, montants à deux décimales.
        $this->client->request('GET', '/admin/rapports-stands/stands.csv?'.$filtres);
        $this->assertStringContainsString(';5400,00;', (string) $this->client->getResponse()->getContent());

        // Raccourcis présents, période invalide signalée sans casser l'écran.
        $crawler = $this->client->request('GET', '/admin/rapports-stands?du=2026-08-31&au=2026-08-01');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('main', 'Mois en cours affiché');
        foreach (['Aujourd\'hui', '7 derniers jours', 'Mois en cours', 'Mois précédent'] as $raccourci) {
            $this->assertCount(1, $crawler->filter('a.onglet:contains("'.$raccourci.'")'));
        }
    }

    /** Le bouton de suggestion de l'écran de dotation porte les quantités calculées. */
    public function testLaDotationProposeLaSuggestion(): void
    {
        $this->client->loginUser($this->gerante);
        $crawler = $this->client->request('GET', '/admin/dotations/nouvelle?stand='.$this->stand->getId());

        $this->assertResponseIsSuccessful();
        $bouton = $crawler->filter('button[data-action="dotation#suggerer"]');
        $this->assertCount(1, $bouton);
        $this->assertNotNull($bouton->attr('disabled'), 'Aucun historique : bouton inerte.');
        $this->assertSame('{}', $crawler->filter('form[data-controller="dotation"]')->attr('data-dotation-suggestion-value'));
    }
}
