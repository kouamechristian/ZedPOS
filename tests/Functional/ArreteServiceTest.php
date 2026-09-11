<?php

namespace App\Tests\Functional;

use App\Entity\Arrete;
use App\Entity\Article;
use App\Entity\BonDotation;
use App\Entity\BonRetour;
use App\Entity\Emplacement;
use App\Entity\JournalAudit;
use App\Entity\MouvementStock;
use App\Entity\Utilisateur;
use App\Entity\Vendeur;
use App\Enum\ActionAudit;
use App\Enum\GranulariteArrete;
use App\Enum\MotifRetour;
use App\Enum\StatutArrete;
use App\Enum\StatutBonRetour;
use App\Enum\TraitementEcart;
use App\Enum\TypeEmplacement;
use App\Enum\TypeMouvementStock;
use App\Repository\ArreteRepository;
use App\Service\ArreteService;
use App\Service\BonDotationService;
use App\Service\DotationsEnBrouillonException;
use App\Service\StockManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le point d'un stand : règle de la période, embarquement des dotations et des
 * retours, écritures de stock, écart, immuabilité, annulation.
 *
 * Toutes les dates sont fixées, « aujourd'hui » compris (vendredi 11/09/2026) : le
 * résultat ne dépend pas du jour où la suite tourne.
 */
class ArreteServiceTest extends KernelTestCase
{
    private const AUJOURDHUI = '2026-09-11';

    private EntityManagerInterface $em;
    private ArreteService $points;
    private BonDotationService $dotations;
    private StockManager $stock;
    private Utilisateur $gerante;
    private Vendeur $awa;
    private Emplacement $depot;
    private Emplacement $stand;
    /** Suivi en stock : les invendus reviennent vraiment au dépôt. */
    private Article $coca;
    /** Fabriqué : le dépôt n'en tient pas de stock. */
    private Article $baguette;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->points = static::getContainer()->get(ArreteService::class);
        $this->dotations = static::getContainer()->get(BonDotationService::class);
        $this->stock = static::getContainer()->get(StockManager::class);

        $connexion = $this->em->getConnection();
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['remboursement_dette', 'dette_vendeur', 'ligne_retour', 'bon_retour', 'ligne_dotation', 'bon_dotation', 'arrete', 'vendeur', 'ligne_fiche_technique', 'fiche_technique', 'ligne_vente', 'reglement', 'vente', 'mouvement_caisse', 'session_caisse', 'mouvement_stock', 'stock_courant', 'perte', 'article', 'matiere_premiere', 'famille_produit', 'journal_audit', 'notification', 'utilisateur'] as $table) {
            $connexion->executeStatement('DELETE FROM '.$table);
        }
        $connexion->executeStatement("DELETE FROM emplacement WHERE code <> 'DEPOT'");
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $this->gerante = (new Utilisateur('mariam@test.ci', 'Mariam'))->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        $this->awa = new Vendeur('Awa');
        $this->stand = (new Emplacement('STAND-GARE', 'Stand de la gare', TypeEmplacement::STAND))
            ->setVendeurHabituel($this->awa)
            ->setTauxCommission(1000)       // 10 %
            ->setSeuilEcartAlerte(50000);   // 500 F
        $this->coca = (new Article('Coca', 50000, 'bouteille'))->setSuiviStock(true)->setPrixCession(42000);
        $this->baguette = (new Article('Baguette', 15000, 'pièce'))->setPrixCession(12500);

        foreach ([$this->gerante, $this->awa, $this->stand, $this->coca, $this->baguette] as $entite) {
            $this->em->persist($entite);
        }
        $this->em->flush();

        $this->depot = $this->stock->depotPrincipal();
        $this->stock->enregistrerMouvement($this->coca, $this->depot, 48000, TypeMouvementStock::AJUSTEMENT);
    }

    // ------------------------------------------------------------- Outils

    /** @param array<int, int> $unites unités par article */
    private function doter(string $date, array $unites, bool $valider = true): BonDotation
    {
        $bon = $this->dotations->creer($this->stand, $this->awa, new \DateTimeImmutable($date), $unites, $this->gerante);
        if ($valider) {
            $this->dotations->valider($bon, $this->gerante);
        }

        return $bon;
    }

    /** @param list<array{0: Article, 1: int, 2: MotifRetour}> $saisie quantités en unités */
    private function enregistrer(string $fin, array $saisie = [], ?int $remisFcfa = null, ?string $commentaire = null, ?TraitementEcart $traitement = null): Arrete
    {
        return $this->points->enregistrer(
            $this->stand,
            new \DateTimeImmutable($fin),
            $this->awa,
            array_map(static fn (array $l): array => [$l[0], $l[1] * 1000, $l[2]], $saisie),
            null === $remisFcfa ? null : $remisFcfa * 100,
            $commentaire,
            $traitement,
            $this->gerante,
            $this->aujourdhui(),
        );
    }

    /** Enregistre la saisie avec des espèces égales au net, puis valide. */
    private function arreterJuste(string $fin, array $saisie = []): Arrete
    {
        $arrete = $this->enregistrer($fin, $saisie);
        $arrete = $this->enregistrer($fin, $saisie, intdiv($arrete->getNetARemettre(), 100));
        $this->points->valider($arrete, $this->gerante, $this->aujourdhui());

        return $arrete;
    }

    private function aujourdhui(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::AUJOURDHUI);
    }

    private function mouvements(string $documentType, ?int $documentId, TypeMouvementStock $type): array
    {
        return $this->em->getRepository(MouvementStock::class)->findBy(['documentType' => $documentType, 'documentId' => $documentId, 'type' => $type], ['id' => 'ASC']);
    }

    private function audit(ActionAudit $action): array
    {
        return $this->em->getRepository(JournalAudit::class)->findBy(['action' => $action->value], ['id' => 'ASC']);
    }

    // ------------------------------------------------------------ Période

    /**
     * RÈGLE CRITIQUE : le début n'est jamais saisi. Première dotation du stand, puis
     * lendemain du dernier arrêté validé.
     */
    public function testLeDebutSuitLaPremiereDotationPuisLeDernierArreteValide(): void
    {
        $this->assertNull($this->points->debutPeriode($this->stand), 'Aucune dotation : rien à arrêter.');

        $this->doter('2026-09-07', [$this->baguette->getId() => 40]);
        $this->doter('2026-09-09', [$this->baguette->getId() => 30]);
        $this->assertSame('2026-09-07', $this->points->debutPeriode($this->stand)->format('Y-m-d'));

        $arrete = $this->arreterJuste('2026-09-08');
        $this->assertSame('2026-09-07', $arrete->getDateDebut()->format('Y-m-d'));
        $this->assertSame(GranulariteArrete::LIBRE, $arrete->getGranularite());

        $this->assertSame('2026-09-09', $this->points->debutPeriode($this->stand)->format('Y-m-d'), 'Lendemain du dernier arrêté.');
        $suivant = $this->enregistrer('2026-09-09');
        $this->assertSame('2026-09-09', $suivant->getDateDebut()->format('Y-m-d'));
        $this->assertSame(GranulariteArrete::JOUR, $suivant->getGranularite());
    }

    /** Un brouillon de dotation plus ancien que la première validée ouvre la période : il ne doit pas y échapper. */
    public function testUnBrouillonDeDotationAncienOuvreLaPeriode(): void
    {
        $this->doter('2026-09-05', [$this->baguette->getId() => 10], valider: false);
        $this->doter('2026-09-07', [$this->baguette->getId() => 40]);

        $this->assertSame('2026-09-05', $this->points->debutPeriode($this->stand)->format('Y-m-d'));
    }

    public function testUneFinFutureOuAvantLeDebutEstRefusee(): void
    {
        $this->doter('2026-09-07', [$this->baguette->getId() => 40]);

        foreach (['2026-09-12', '2026-09-06'] as $fin) {
            try {
                $this->enregistrer($fin);
                $this->fail('Fin acceptée : '.$fin);
            } catch (\DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertNull(static::getContainer()->get(ArreteRepository::class)->brouillonDe($this->stand));
    }

    /** L'autre moitié de la règle : une journée arrêtée n'accepte plus de dotation. */
    public function testUneDotationNeSeDatePlusDansUnePeriodeArretee(): void
    {
        $this->doter('2026-09-07', [$this->baguette->getId() => 40]);
        $this->arreterJuste('2026-09-08');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('arrêté jusqu\'au 08/09/2026');
        $this->doter('2026-09-08', [$this->baguette->getId() => 5]);
    }

    // --------------------------------------------------------- Validation

    public function testValiderEmbarqueRetourneVendEtRattache(): void
    {
        $lundi = $this->doter('2026-09-07', [$this->baguette->getId() => 40, $this->coca->getId() => 12]);
        $mercredi = $this->doter('2026-09-09', [$this->baguette->getId() => 30]);
        $horsPeriode = $this->doter('2026-09-11', [$this->baguette->getId() => 25]);

        $arrete = $this->arreterJuste('2026-09-10', [
            [$this->baguette, 5, MotifRetour::INVENDU],
            [$this->baguette, 2, MotifRetour::CASSE],
            [$this->coca, 2, MotifRetour::INVENDU],
        ]);

        $this->assertSame(StatutArrete::VALIDE, $arrete->getStatut());
        $this->assertNotNull($arrete->getDateValidation());

        // Vendu : baguettes 70 − 5 − 2 = 63 ; cocas 12 − 2 = 10.
        // Attendu 63 × 150 + 10 × 500 = 14 450 F ; commission 10 % = 1 445 F ; net 13 005 F.
        $this->assertSame([1445000, 144500, 1300500, 1300500, 0], [
            $arrete->getMontantAttendu(), $arrete->getRemunerationVendeur(), $arrete->getNetARemettre(), $arrete->getMontantRemis(), $arrete->getEcart(),
        ]);

        // Rattachements : les deux dotations de la période, pas celle du 11.
        $this->assertSame($arrete, $lundi->getArrete());
        $this->assertSame($arrete, $mercredi->getArrete());
        $this->assertNull($horsPeriode->getArrete());
        $retour = $this->em->getRepository(BonRetour::class)->findOneBy(['arrete' => $arrete]);
        $this->assertSame(StatutBonRetour::VALIDE, $retour->getStatut());
        $this->assertSame('2026-09-10', $retour->getDateRetour()->format('Y-m-d'));

        // Stock : invendus au dépôt (coca seul, la baguette n'y est pas suivie), casse
        // en perte, vendu sorti du stand — il ne reste au stand que la dotation du 11.
        $this->assertSame(48000 - 12000 + 2000, $this->stock->getStock($this->depot, $this->coca));
        $this->assertSame(0, $this->stock->getStock($this->stand, $this->coca));
        $this->assertSame(25000, $this->stock->getStock($this->stand, $this->baguette));
        $this->assertCount(3, $this->mouvements(ArreteService::DOCUMENT_RETOUR, $retour->getId(), TypeMouvementStock::RETOUR), 'Baguette : stand ; coca : stand et dépôt.');
        $this->assertCount(1, $this->mouvements(ArreteService::DOCUMENT_RETOUR, $retour->getId(), TypeMouvementStock::PERTE));
        $this->assertCount(2, $this->mouvements(ArreteService::DOCUMENT_ARRETE, $arrete->getId(), TypeMouvementStock::VENTE_STAND));

        // Trace, sans entrée d'écart puisqu'il n'y en a pas.
        [$trace] = $this->audit(ActionAudit::ARRETE_VALIDE);
        $this->assertSame('BROUILLON', $trace->getAvant()['statut']);
        $this->assertSame('VALIDE', $trace->getApres()['statut']);
        $this->assertSame(1300500, $trace->getApres()['netARemettre']);
        $this->assertEqualsCanonicalizing([$lundi->getNumero(), $mercredi->getNumero()], $trace->getApres()['bonsDotation']);
        $this->assertSame([], $this->audit(ActionAudit::ECART_POINT));

        // Le recalcul depuis les seuls documents rattachés retombe sur les montants figés.
        $resultat = $this->points->resultat($arrete);
        $this->assertSame($arrete->getNetARemettre(), $resultat->netARemettre);
    }

    /** Prix changé en cours de période : chaque dotation garde le sien, jamais relu sur le produit. */
    public function testUnChangementDePrixEnCoursDePeriodeGardeLesPrixFiges(): void
    {
        $this->doter('2026-09-07', [$this->baguette->getId() => 20]);                 // 150 F
        $this->baguette->setPrixVenteTtc(20000);
        $this->em->flush();
        $this->doter('2026-09-09', [$this->baguette->getId() => 20]);                 // 200 F
        $this->baguette->setPrixVenteTtc(99900);                                      // relu nulle part
        $this->em->flush();

        $arrete = $this->arreterJuste('2026-09-10', [[$this->baguette, 10, MotifRetour::INVENDU]]);

        // Plus anciens vendus d'abord : 20 × 150 + 10 × 200 = 5 000 F.
        $this->assertSame(500000, $arrete->getMontantAttendu());
        $this->assertSame(450000, $arrete->getNetARemettre());
    }

    /** Tant qu'une dotation de la période est en brouillon, le point ne se valide pas — et rien n'est écrit. */
    public function testUneDotationEnBrouillonBloqueLaValidation(): void
    {
        $this->doter('2026-09-07', [$this->baguette->getId() => 40]);
        $brouillon = $this->doter('2026-09-08', [$this->baguette->getId() => 10], valider: false);
        // 35 vendues × 150 F = 5 250 F, moins 10 % : 4 725 F remis, écart nul.
        $arrete = $this->enregistrer('2026-09-10', [[$this->baguette, 5, MotifRetour::INVENDU]], 4725);

        try {
            $this->points->valider($arrete, $this->gerante, $this->aujourdhui());
            $this->fail('Point validé malgré une dotation en brouillon.');
        } catch (DotationsEnBrouillonException $e) {
            $this->assertSame([$brouillon->getNumero()], array_map(static fn ($b) => $b->getNumero(), $e->bons));
        }

        $this->assertTrue($arrete->estBrouillon());
        $this->assertSame([], $this->mouvements(ArreteService::DOCUMENT_ARRETE, $arrete->getId(), TypeMouvementStock::VENTE_STAND));
        $this->assertSame(40000, $this->stock->getStock($this->stand, $this->baguette));
        $this->assertSame([], $this->audit(ActionAudit::ARRETE_VALIDE));

        // Le brouillon annulé, la validation passe.
        $this->dotations->annuler($brouillon, null, $this->gerante);
        $this->points->valider($arrete, $this->gerante, $this->aujourdhui());
        $this->assertTrue($arrete->estValide());
    }

    public function testUnRetourAuDelaDuConfieEstRefuseSansRienEnregistrer(): void
    {
        $this->doter('2026-09-07', [$this->baguette->getId() => 40]);

        try {
            $this->enregistrer('2026-09-07', [[$this->baguette, 38, MotifRetour::INVENDU], [$this->baguette, 3, MotifRetour::PERIME]]);
            $this->fail('Retour accepté au-delà du confié.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Baguette', $e->getMessage());
        }

        $this->assertNull(static::getContainer()->get(ArreteRepository::class)->brouillonDe($this->stand));
    }

    public function testLesEspecesRemisesSontObligatoires(): void
    {
        $this->doter('2026-09-07', [$this->baguette->getId() => 40]);
        $arrete = $this->enregistrer('2026-09-07');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('espèces remises');
        $this->points->valider($arrete, $this->gerante, $this->aujourdhui());
    }

    /** Au-delà du seuil du stand (500 F), l'écart se justifie ; en deçà, non. */
    public function testUnEcartAuDelaDuSeuilExigeUneJustification(): void
    {
        $this->doter('2026-09-07', [$this->baguette->getId() => 40]);   // net 5 400 F

        $arrete = $this->enregistrer('2026-09-07', [], 4700);           // manque 700 F
        $this->assertSame(-70000, $arrete->getEcart());
        try {
            $this->points->valider($arrete, $this->gerante, $this->aujourdhui());
            $this->fail('Écart de 700 F accepté sans justification.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('justification', $e->getMessage());
        }

        $this->enregistrer('2026-09-07', [], 4700, 'Billet de 1 000 F refusé, rendu en partie', TraitementEcart::PERTE);
        $this->points->valider($arrete, $this->gerante, $this->aujourdhui());

        $this->assertSame(-70000, $arrete->getEcart());
        [$ecart] = $this->audit(ActionAudit::ECART_POINT);
        $this->assertSame(-70000, $ecart->getApres()['ecart']);
        $this->assertTrue(ActionAudit::ECART_POINT->estSensible());
    }

    public function testUnEcartDansLeSeuilSePasseDeJustification(): void
    {
        $this->doter('2026-09-07', [$this->baguette->getId() => 40]);
        $arrete = $this->enregistrer('2026-09-07', [], 5100, null, TraitementEcart::DETTE);   // manque 300 F

        $this->points->valider($arrete, $this->gerante, $this->aujourdhui());

        $this->assertTrue($arrete->estValide());
        $this->assertCount(1, $this->audit(ActionAudit::ECART_POINT), 'Tracé quand même : un écart reste un écart.');
    }

    public function testUnArreteValideEstImmuable(): void
    {
        $this->doter('2026-09-07', [$this->baguette->getId() => 40]);
        $arrete = $this->arreterJuste('2026-09-07');

        foreach ([
            fn () => $arrete->definirPeriode(new \DateTimeImmutable('2026-09-07'), new \DateTimeImmutable('2026-09-08')),
            fn () => $arrete->enregistrerSaisie(0, null),
            fn () => $arrete->changerVendeur($this->awa),
            fn () => $this->points->valider($arrete, $this->gerante, $this->aujourdhui()),
        ] as $geste) {
            try {
                $geste();
                $this->fail('Un arrêté validé a été modifié.');
            } catch (\DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        // Enregistrer à nouveau ouvre un autre point, sur la période suivante.
        $this->doter('2026-09-08', [$this->baguette->getId() => 10]);
        $suivant = $this->enregistrer('2026-09-08');
        $this->assertNotSame($arrete, $suivant);
    }

    // --------------------------------------------------------- Annulation

    public function testSeulLeDernierArreteSAnnuleEtLAnnulationDefaitTout(): void
    {
        $lundi = $this->doter('2026-09-07', [$this->baguette->getId() => 40, $this->coca->getId() => 12]);
        $premier = $this->arreterJuste('2026-09-07', [[$this->coca, 2, MotifRetour::INVENDU]]);
        $mardi = $this->doter('2026-09-08', [$this->baguette->getId() => 30]);
        $second = $this->arreterJuste('2026-09-08', [[$this->baguette, 4, MotifRetour::PERIME]]);

        try {
            $this->points->annuler($premier, 'Erreur', $this->gerante);
            $this->fail('Arrêté ancien annulé.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('dernier arrêté', $e->getMessage());
        }
        $this->assertTrue($premier->estValide());

        $avantAnnulation = $this->stock->getStock($this->stand, $this->baguette);
        $this->points->annuler($second, 'Périmés mal comptés', $this->gerante);

        $this->assertSame(StatutArrete::ANNULE, $second->getStatut());
        $this->assertSame('Périmés mal comptés', $second->getMotifAnnulation());
        $this->assertNull($mardi->getArrete(), 'La dotation redevient à arrêter.');
        $this->assertSame($premier, $lundi->getArrete(), 'Le point précédent n\'est pas touché.');
        $this->assertSame($avantAnnulation + 30000, $this->stock->getStock($this->stand, $this->baguette), 'Vendu et périmés reviennent au stand.');
        $this->assertSame(StatutBonRetour::ANNULE, $this->em->getRepository(BonRetour::class)->findOneBy(['arrete' => $second])->getStatut());
        $this->assertSame('2026-09-08', $this->points->debutPeriode($this->stand)->format('Y-m-d'), 'La période du point annulé est de nouveau à arrêter.');
        $this->assertCount(1, $this->audit(ActionAudit::ARRETE_ANNULE));

        // Motif obligatoire, et plus rien à annuler ensuite.
        foreach ([fn () => $this->points->annuler($premier, '  ', $this->gerante), fn () => $this->points->annuler($second, 'Encore', $this->gerante)] as $geste) {
            try {
                $geste();
                $this->fail('Annulation acceptée.');
            } catch (\DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        // La période se refait.
        $refait = $this->arreterJuste('2026-09-08');
        $this->assertSame($refait, $mardi->getArrete());
    }
}
