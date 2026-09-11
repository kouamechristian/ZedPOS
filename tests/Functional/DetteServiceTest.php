<?php

namespace App\Tests\Functional;

use App\Entity\Arrete;
use App\Entity\Article;
use App\Entity\DetteVendeur;
use App\Entity\Emplacement;
use App\Entity\JournalAudit;
use App\Entity\RemboursementDette;
use App\Entity\Utilisateur;
use App\Entity\Vendeur;
use App\Enum\ActionAudit;
use App\Enum\CleParametre;
use App\Enum\ModeReglement;
use App\Enum\StatutDette;
use App\Enum\TraitementEcart;
use App\Enum\TypeDette;
use App\Enum\TypeEmplacement;
use App\Repository\DetteVendeurRepository;
use App\Service\ArreteService;
use App\Service\BonDotationService;
use App\Service\DetteService;
use App\Service\ParametresBoutique;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Dettes des vendeurs : jamais de création silencieuse, remboursement des plus
 * anciennes d'abord, annulation avec le point qui les a ouvertes.
 *
 * Dates fixées, « aujourd'hui » compris (vendredi 11/09/2026).
 */
class DetteServiceTest extends KernelTestCase
{
    private const AUJOURDHUI = '2026-09-11';

    private EntityManagerInterface $em;
    private DetteService $dettes;
    private ArreteService $points;
    private DetteVendeurRepository $depot;
    private Utilisateur $gerante;
    private Vendeur $awa;
    private Vendeur $kone;
    private Emplacement $stand;
    private Article $baguette;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->dettes = static::getContainer()->get(DetteService::class);
        $this->points = static::getContainer()->get(ArreteService::class);
        $this->depot = static::getContainer()->get(DetteVendeurRepository::class);

        $connexion = $this->em->getConnection();
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['remboursement_dette', 'dette_vendeur', 'ligne_retour', 'bon_retour', 'ligne_dotation', 'bon_dotation', 'arrete', 'vendeur', 'mouvement_stock', 'stock_courant', 'article', 'journal_audit', 'parametre', 'utilisateur'] as $table) {
            $connexion->executeStatement('DELETE FROM '.$table);
        }
        $connexion->executeStatement("DELETE FROM emplacement WHERE code <> 'DEPOT'");
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $this->gerante = (new Utilisateur('mariam@test.ci', 'Mariam'))->setRoles(['ROLE_GERANT'])->setMotDePasse('x');
        $this->awa = new Vendeur('Awa');
        $this->kone = new Vendeur('Koné');
        $this->stand = (new Emplacement('STAND-GARE', 'Stand de la gare', TypeEmplacement::STAND))
            ->setVendeurHabituel($this->awa)
            ->setTauxCommission(1000)        // 10 %
            ->setSeuilEcartAlerte(50000);    // 500 F
        $this->baguette = (new Article('Baguette', 15000, 'pièce'))->setPrixCession(12500);

        foreach ([$this->gerante, $this->awa, $this->kone, $this->stand, $this->baguette] as $entite) {
            $this->em->persist($entite);
        }
        $this->em->flush();
    }

    // ------------------------------------------------------------- Outils

    private function aujourdhui(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::AUJOURDHUI);
    }

    /** 40 baguettes le $date : net à remettre 5 400 F. */
    private function doter(string $date): void
    {
        $service = static::getContainer()->get(BonDotationService::class);
        $bon = $service->creer($this->stand, $this->awa, new \DateTimeImmutable($date), [$this->baguette->getId() => 40], $this->gerante);
        $service->valider($bon, $this->gerante);
    }

    private function point(string $fin, int $remisFcfa, ?TraitementEcart $traitement, ?string $commentaire = null): Arrete
    {
        return $this->points->enregistrer($this->stand, new \DateTimeImmutable($fin), $this->awa, [], $remisFcfa * 100, $commentaire, $traitement, $this->gerante, $this->aujourdhui());
    }

    private function valider(Arrete $arrete): void
    {
        $this->points->valider($arrete, $this->gerante, $this->aujourdhui());
    }

    private function audit(ActionAudit $action): array
    {
        return $this->em->getRepository(JournalAudit::class)->findBy(['action' => $action->value], ['id' => 'ASC']);
    }

    private function avance(Vendeur $vendeur, int $fcfa, string $commentaire = 'Avance sur commission'): DetteVendeur
    {
        return $this->dettes->ouvrirManuelle($vendeur, TypeDette::AVANCE, $fcfa * 100, $commentaire, $this->gerante);
    }

    // ------------------------------------------------ Ouverture depuis un point

    public function testUnManquantImputeEnDetteOuvreLaDetteALaValidation(): void
    {
        $this->doter('2026-09-07');
        $arrete = $this->point('2026-09-07', 5000, TraitementEcart::DETTE);   // manque 400 F
        $this->assertSame([], $this->depot->findAll(), 'Rien tant que le point n\'est pas validé.');

        $this->valider($arrete);

        $dette = $this->depot->deArrete($arrete);
        $this->assertSame($this->awa, $dette->getVendeur());
        $this->assertSame([TypeDette::ECART_CAISSE, StatutDette::OUVERTE, 40000, 0, 40000], [
            $dette->getType(), $dette->getStatut(), $dette->getMontant(), $dette->getMontantRembourse(), $dette->reste(),
        ]);
        $this->assertStringContainsString($arrete->getNumero(), (string) $dette->getCommentaire());
        $this->assertSame(40000, $this->dettes->soldeDe($this->awa));

        [$trace] = $this->audit(ActionAudit::DETTE_CREEE);
        $this->assertNull($trace->getAvant());
        $this->assertSame(['ECART_CAISSE', 40000, $arrete->getNumero()], [$trace->getApres()['type'], $trace->getApres()['montant'], $trace->getApres()['arrete']]);
        [$ecart] = $this->audit(ActionAudit::ECART_POINT);
        $this->assertSame('DETTE', $ecart->getApres()['traitementEcart']);
    }

    /** Jamais de création silencieuse : sans choix, le point ne se valide pas et rien n'est écrit. */
    public function testUnManquantSansChoixBloqueLaValidation(): void
    {
        $this->doter('2026-09-07');
        $arrete = $this->point('2026-09-07', 5000, null);

        try {
            $this->valider($arrete);
            $this->fail('Manquant validé sans choix.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Il manque 400 FCFA', $e->getMessage());
            $this->assertStringContainsString('Awa', $e->getMessage());
        }

        $this->assertTrue($arrete->estBrouillon());
        $this->assertSame([], $this->depot->findAll());
        $this->assertSame([], $this->audit(ActionAudit::ARRETE_VALIDE));
    }

    /** Une perte se justifie toujours, même sous le seuil d'écart du stand ; elle n'ouvre aucune dette. */
    public function testUnManquantPasseEnPerteExigeUneJustification(): void
    {
        $this->doter('2026-09-07');
        $arrete = $this->point('2026-09-07', 5200, TraitementEcart::PERTE);   // manque 200 F, sous le seuil

        try {
            $this->valider($arrete);
            $this->fail('Perte validée sans justification.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('justification', $e->getMessage());
        }

        $arrete = $this->point('2026-09-07', 5200, TraitementEcart::PERTE, 'Billet déchiré refusé à la banque');
        $this->valider($arrete);

        $this->assertTrue($arrete->estValide());
        $this->assertSame(TraitementEcart::PERTE, $arrete->getTraitementEcart());
        $this->assertSame([], $this->depot->findAll());
    }

    /** Sans manquant, un choix resté d'une saisie précédente ne voyage pas. */
    public function testUnPointJusteOuEnTropPercuNOuvreRien(): void
    {
        $this->doter('2026-09-07');
        $arrete = $this->point('2026-09-07', 5600, TraitementEcart::DETTE);   // 200 F de trop

        $this->valider($arrete);

        $this->assertNull($arrete->getTraitementEcart());
        $this->assertSame([], $this->depot->findAll());
    }

    // ------------------------------------------------------ Dettes manuelles

    public function testUneAvanceSeSaisitAvecUnObjetEtUnManquantJamaisALaMain(): void
    {
        $dette = $this->avance($this->awa, 2000, '  Avance pour le transport  ');
        $this->assertSame(['Avance pour le transport', 200000, StatutDette::OUVERTE], [$dette->getCommentaire(), $dette->getMontant(), $dette->getStatut()]);
        $this->assertCount(1, $this->audit(ActionAudit::DETTE_CREEE));

        foreach ([
            fn () => $this->dettes->ouvrirManuelle($this->awa, TypeDette::AUTRE, 50000, '   ', $this->gerante),
            fn () => $this->dettes->ouvrirManuelle($this->awa, TypeDette::AVANCE, 0, 'Rien', $this->gerante),
            fn () => $this->dettes->ouvrirManuelle($this->awa, TypeDette::ECART_CAISSE, 50000, 'Manquant inventé', $this->gerante),
        ] as $geste) {
            try {
                $geste();
                $this->fail('Dette acceptée.');
            } catch (\DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertCount(1, $this->depot->findAll());
    }

    // ------------------------------------------------------- Remboursements

    /** Un versement rembourse les plus anciennes d'abord, réparti sur autant de dettes qu'il faut. */
    public function testUnVersementRembourseLesPlusAnciennesDAbord(): void
    {
        $ancienne = $this->avance($this->awa, 1000);
        $recente = $this->dettes->ouvrirManuelle($this->awa, TypeDette::AUTRE, 300000, 'Seau cassé', $this->gerante);
        $this->avance($this->kone, 5000);
        $this->assertSame(400000, $this->dettes->soldeDe($this->awa));

        $lignes = $this->dettes->rembourser($this->awa, 150000, ModeReglement::WAVE, new \DateTimeImmutable('2026-09-10'), $this->gerante, $this->aujourdhui());

        $this->assertCount(2, $lignes);
        $this->assertSame([StatutDette::SOLDEE, 100000, 0], [$ancienne->getStatut(), $ancienne->getMontantRembourse(), $ancienne->reste()]);
        $this->assertSame([StatutDette::PARTIELLE, 50000, 250000], [$recente->getStatut(), $recente->getMontantRembourse(), $recente->reste()]);
        $this->assertSame(250000, $this->dettes->soldeDe($this->awa));
        $this->assertSame(500000, $this->dettes->soldeDe($this->kone), 'Les dettes d\'un autre vendeur ne bougent pas.');

        $this->em->clear();
        $enBase = $this->em->getRepository(RemboursementDette::class)->findBy([], ['id' => 'ASC']);
        $this->assertSame([100000, 50000], array_map(static fn (RemboursementDette $r): int => $r->getMontant(), $enBase));
        $this->assertSame(ModeReglement::WAVE, $enBase[0]->getMoyen());
        $this->assertSame('2026-09-10', $enBase[1]->getDate()->format('Y-m-d'));

        [$trace] = $this->audit(ActionAudit::DETTE_REMBOURSEE);
        $this->assertSame(400000, $trace->getAvant()['solde']);
        $this->assertSame([250000, 150000, 'WAVE'], [$trace->getApres()['solde'], $trace->getApres()['montant'], $trace->getApres()['moyen']]);
        $this->assertSame(['SOLDEE', 'PARTIELLE'], array_column($trace->getApres()['repartition'], 'statut'));

        // Soldes de plusieurs vendeurs en une requête ; un vendeur soldé disparaît.
        $this->dettes->rembourser($this->em->find(Vendeur::class, $this->kone->getId()), 500000, ModeReglement::ESPECES, $this->aujourdhui(), $this->em->find(Utilisateur::class, $this->gerante->getId()), $this->aujourdhui());
        $this->assertSame([$this->awa->getId() => 250000], $this->depot->soldes());
    }

    public function testUnVersementAuDelaDuSoldeACreditOuFuturEstRefuseSansRienEcrire(): void
    {
        $dette = $this->avance($this->awa, 1000);

        foreach ([
            'ne doit que 1 000 FCFA' => fn () => $this->dettes->rembourser($this->awa, 100100, ModeReglement::ESPECES, $this->aujourdhui(), $this->gerante, $this->aujourdhui()),
            'crédit' => fn () => $this->dettes->rembourser($this->awa, 50000, ModeReglement::CREDIT, $this->aujourdhui(), $this->gerante, $this->aujourdhui()),
            'futur' => fn () => $this->dettes->rembourser($this->awa, 50000, ModeReglement::ESPECES, new \DateTimeImmutable('2026-09-12'), $this->gerante, $this->aujourdhui()),
            'strictement positif' => fn () => $this->dettes->rembourser($this->awa, 0, ModeReglement::ESPECES, $this->aujourdhui(), $this->gerante, $this->aujourdhui()),
            'ne doit rien' => fn () => $this->dettes->rembourser($this->kone, 100, ModeReglement::ESPECES, $this->aujourdhui(), $this->gerante, $this->aujourdhui()),
        ] as $attendu => $geste) {
            try {
                $geste();
                $this->fail('Versement accepté : '.$attendu);
            } catch (\DomainException $e) {
                $this->assertStringContainsString($attendu, $e->getMessage());
            }
        }

        $this->assertSame([0, StatutDette::OUVERTE], [$dette->getMontantRembourse(), $dette->getStatut()]);
        $this->assertSame([], $this->em->getRepository(RemboursementDette::class)->findAll());
        $this->assertSame([], $this->audit(ActionAudit::DETTE_REMBOURSEE));
    }

    // ------------------------------------------------ Annulation d'un point

    public function testAnnulerLePointAnnuleSaDetteTantQueRienNEstRembourse(): void
    {
        $this->doter('2026-09-07');
        $arrete = $this->point('2026-09-07', 5000, TraitementEcart::DETTE);
        $this->valider($arrete);
        $dette = $this->depot->deArrete($arrete);

        $this->points->annuler($arrete, 'Invendus mal comptés', $this->gerante);

        $this->assertSame(StatutDette::ANNULEE, $dette->getStatut());
        $this->assertNotNull($dette->getAnnuleAt());
        $this->assertSame(0, $this->dettes->soldeDe($this->awa));
        [$trace] = $this->audit(ActionAudit::DETTE_ANNULEE);
        $this->assertSame(['OUVERTE', 'ANNULEE'], [$trace->getAvant()['statut'], $trace->getApres()['statut']]);
        $this->assertTrue(ActionAudit::DETTE_ANNULEE->estSensible());

        // Le point se refait, et sa nouvelle dette remplace l'annulée.
        $refait = $this->point('2026-09-07', 5000, TraitementEcart::DETTE);
        $this->valider($refait);
        $this->assertSame(40000, $this->dettes->soldeDe($this->awa));
    }

    /** On ne défait pas un argent reçu : le point dont la dette a commencé d'être remboursée ne s'annule plus. */
    public function testUnPointDontLaDetteEstEntameeNeSAnnulePlus(): void
    {
        $this->doter('2026-09-07');
        $arrete = $this->point('2026-09-07', 5000, TraitementEcart::DETTE);
        $this->valider($arrete);
        $this->dettes->rembourser($this->awa, 10000, ModeReglement::ESPECES, $this->aujourdhui(), $this->gerante, $this->aujourdhui());

        try {
            $this->points->annuler($arrete, 'Erreur', $this->gerante);
            $this->fail('Point annulé malgré un remboursement.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('100 FCFA ont déjà été remboursés', $e->getMessage());
        }

        $this->assertTrue($arrete->estValide());
        $this->assertSame(StatutDette::PARTIELLE, $this->depot->deArrete($arrete)->getStatut());
    }

    // ----------------------------------------------------------- Paramètre

    public function testLeSeuilDAlerteSeLitEnCentimesEtRetombeSurLeDefaut(): void
    {
        $parametres = static::getContainer()->get(ParametresBoutique::class);
        $this->assertSame(500000, $parametres->seuilAlerteDette(), 'Défaut : 5 000 F.');

        $parametres->enregistrer([CleParametre::SEUIL_ALERTE_DETTE->value => '12000']);
        $this->assertSame(1200000, $parametres->seuilAlerteDette());

        $parametres->enregistrer([CleParametre::SEUIL_ALERTE_DETTE->value => 'beaucoup']);
        $this->assertSame(500000, $parametres->seuilAlerteDette());
    }
}
