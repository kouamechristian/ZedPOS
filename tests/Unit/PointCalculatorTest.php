<?php

namespace App\Tests\Unit;

use App\Enum\ModeRemuneration;
use App\Enum\MotifRetour;
use App\Service\Point\DotationPoint;
use App\Service\Point\PointCalculator;
use App\Service\Point\RetourPoint;
use PHPUnit\Framework\TestCase;

/**
 * Calcul du point d'un stand. Pur : ni base, ni conteneur.
 *
 * Montants en centimes (15000 = 150 F), quantités en millièmes (40000 = 40).
 */
class PointCalculatorTest extends TestCase
{
    private const BAGUETTE = 1;
    private const CROISSANT = 2;
    private const COCA = 3;

    private PointCalculator $calcul;

    protected function setUp(): void
    {
        $this->calcul = new PointCalculator();
    }

    private function dotation(int $produit, string $date, int $unites, int $prixVenteF, int $prixCessionF, int $ordre = 1): DotationPoint
    {
        $noms = [self::BAGUETTE => 'Baguette', self::CROISSANT => 'Croissant', self::COCA => 'Coca'];

        return new DotationPoint($produit, $noms[$produit], 'pièce', new \DateTimeImmutable($date), $ordre, $unites * 1000, $prixVenteF * 100, $prixCessionF * 100);
    }

    private function retour(int $produit, int $unites, MotifRetour $motif = MotifRetour::INVENDU): RetourPoint
    {
        return new RetourPoint($produit, $unites * 1000, $motif);
    }

    // ------------------------------------------------- Les trois cas demandés

    /** Un jour, une dotation, quelques invendus et une casse. */
    public function testPeriodeDUnJour(): void
    {
        $point = $this->calcul->calculer(
            [$this->dotation(self::BAGUETTE, '2026-09-11', 40, 150, 125)],
            [$this->retour(self::BAGUETTE, 6), $this->retour(self::BAGUETTE, 2, MotifRetour::CASSE)],
            ModeRemuneration::COMMISSION,
            1000, // 10 %
            285000,
        );

        $ligne = $point->ligne(self::BAGUETTE);
        $this->assertSame([40000, 6000, 2000, 32000], [$ligne->qteConfiee, $ligne->qteRetournee, $ligne->qtePerdue, $ligne->qteVendue]);
        $this->assertSame(480000, $point->montantAttendu, '32 × 150 F = 4 800 F');
        $this->assertSame(48000, $point->remuneration, '10 % = 480 F');
        $this->assertSame(432000, $point->netARemettre, '4 320 F à remettre');
        $this->assertSame(285000 - 432000, $point->ecart, 'Remis 2 850 F : il manque 1 470 F.');
    }

    /**
     * Le prix de la baguette passe de 150 à 200 F en cours de semaine. Chaque ligne
     * garde son prix figé ; les invendus sont imputés à la dotation la plus récente.
     */
    public function testPeriodeACheValSurUnChangementDePrix(): void
    {
        $point = $this->calcul->calculer(
            [
                $this->dotation(self::BAGUETTE, '2026-09-10', 20, 200, 160),  // jeudi, nouveau prix
                $this->dotation(self::BAGUETTE, '2026-09-07', 20, 150, 125),  // lundi, ancien prix
            ],
            [$this->retour(self::BAGUETTE, 10)],
            ModeRemuneration::MARGE,
            0,
        );

        $ligne = $point->ligne(self::BAGUETTE);
        $this->assertSame(30000, $ligne->qteVendue);
        $this->assertNull($ligne->prixUnique(), 'Deux prix sur la période.');

        [$lundi, $jeudi] = $ligne->tranches;
        $this->assertSame(['2026-09-07', 20000, 15000], [$lundi->date->format('Y-m-d'), $lundi->quantiteVendue, $lundi->prixVente]);
        $this->assertSame(['2026-09-10', 10000, 20000], [$jeudi->date->format('Y-m-d'), $jeudi->quantiteVendue, $jeudi->prixVente]);

        // 20 × 150 + 10 × 200 = 5 000 F — et non 30 × 200 relu sur le produit.
        $this->assertSame(500000, $point->montantAttendu);
        // Marge : 20 × (150 − 125) + 10 × (200 − 160) = 500 + 400 = 900 F.
        $this->assertSame(90000, $point->remuneration);
        $this->assertSame(410000, $point->netARemettre);
        $this->assertNull($point->ecart, 'Rien remis encore : pas d\'écart.');
    }

    /** Une semaine, plusieurs dotations et plusieurs retours, trois produits. */
    public function testPeriodeAvecPlusieursDotationsEtPlusieursRetours(): void
    {
        $point = $this->calcul->calculer(
            [
                $this->dotation(self::BAGUETTE, '2026-09-07', 40, 150, 125, 1),
                $this->dotation(self::CROISSANT, '2026-09-07', 12, 300, 250, 1),
                $this->dotation(self::BAGUETTE, '2026-09-07', 10, 150, 125, 2),  // réappro le même jour
                $this->dotation(self::BAGUETTE, '2026-09-09', 40, 150, 125, 1),
                $this->dotation(self::COCA, '2026-09-09', 24, 500, 420, 1),
            ],
            [
                $this->retour(self::BAGUETTE, 5),
                $this->retour(self::BAGUETTE, 7),
                $this->retour(self::BAGUETTE, 3, MotifRetour::PERIME),
                $this->retour(self::CROISSANT, 1, MotifRetour::CASSE),
                $this->retour(self::CROISSANT, 1, MotifRetour::AUTRE),
                $this->retour(self::COCA, 4),
                $this->retour(self::COCA, 0),  // case laissée à zéro : ignorée
            ],
            ModeRemuneration::COMMISSION,
            750, // 7,5 %
            3000000,
        );

        $baguette = $point->ligne(self::BAGUETTE);
        $this->assertSame([90000, 12000, 3000, 75000], [$baguette->qteConfiee, $baguette->qteRetournee, $baguette->qtePerdue, $baguette->qteVendue]);
        $this->assertCount(3, $baguette->tranches);

        $croissant = $point->ligne(self::CROISSANT);
        $this->assertSame([12000, 0, 2000, 10000], [$croissant->qteConfiee, $croissant->qteRetournee, $croissant->qtePerdue, $croissant->qteVendue]);

        $coca = $point->ligne(self::COCA);
        $this->assertSame(20000, $coca->qteVendue);

        // 75 × 150 + 10 × 300 + 20 × 500 = 11 250 + 3 000 + 10 000 = 24 250 F.
        $this->assertSame(2425000, $point->montantAttendu);
        // Confié 90 + 12 + 24 ; retourné 12 + 4 ; perdu 3 + 2 ; vendu 126 − 21.
        $this->assertSame([126000, 16000, 5000, 105000], [$point->qteConfiee, $point->qteRetournee, $point->qtePerdue, $point->qteVendue]);
        // 7,5 % de 24 250 F = 1 818,75 F → 1 819 F.
        $this->assertSame(181900, $point->remuneration);
        $this->assertSame(2243100, $point->netARemettre);
        $this->assertSame(3000000 - 2243100, $point->ecart, 'Trop-perçu : écart positif.');

        $this->assertSame(['Baguette', 'Coca', 'Croissant'], array_map(static fn ($l) => $l->produit, $point->lignes));
    }

    // ------------------------------------------------------------- Règles

    public function testUnRetourAuDelaDuConfieEstRefuse(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('« Baguette »');

        $this->calcul->calculer(
            [$this->dotation(self::BAGUETTE, '2026-09-11', 10, 150, 125)],
            [$this->retour(self::BAGUETTE, 8), $this->retour(self::BAGUETTE, 3, MotifRetour::CASSE)],
            ModeRemuneration::COMMISSION,
            1000,
        );
    }

    public function testUnRetourDUnProduitNonConfieEstRefuse(): void
    {
        $this->expectException(\DomainException::class);

        $this->calcul->calculer([$this->dotation(self::BAGUETTE, '2026-09-11', 10, 150, 125)], [$this->retour(self::COCA, 1)], ModeRemuneration::COMMISSION, 0);
    }

    public function testToutRetourneLaisseUnPointANul(): void
    {
        $point = $this->calcul->calculer(
            [$this->dotation(self::BAGUETTE, '2026-09-11', 10, 150, 125)],
            [$this->retour(self::BAGUETTE, 10)],
            ModeRemuneration::COMMISSION,
            1000,
            0,
        );

        $this->assertSame([0, 0, 0, 0], [$point->montantAttendu, $point->remuneration, $point->netARemettre, $point->ecart]);
    }

    public function testLaCommissionSArrondiAuFrancLePlusProche(): void
    {
        $this->assertSame(9300, PointCalculator::commission(123400, 750), '7,5 % de 1 234 F = 92,55 F → 93 F');
        $this->assertSame(9200, PointCalculator::commission(122000, 750), '91,50 F → 92 F (demi-franc vers le haut)');
        $this->assertSame(9100, PointCalculator::commission(121900, 750), '91,425 F → 91 F');
        $this->assertSame(0, PointCalculator::commission(0, 750));
    }

    public function testUnTauxHorsBornesEstRefuse(): void
    {
        $this->expectException(\DomainException::class);
        $this->calcul->calculer([], [], ModeRemuneration::COMMISSION, 10001);
    }

    public function testSansDotationLePointEstVide(): void
    {
        $point = $this->calcul->calculer([], [], ModeRemuneration::MARGE, 0, 5000);

        $this->assertSame([], $point->lignes);
        $this->assertSame(5000, $point->ecart, 'Remettre de l\'argent sans rien avoir confié est un trop-perçu.');
    }
}
