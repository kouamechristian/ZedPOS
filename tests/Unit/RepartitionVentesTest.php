<?php

namespace App\Tests\Unit;

use App\Enum\ModeRemuneration;
use App\Enum\MotifRetour;
use App\Service\Point\DotationPoint;
use App\Service\Point\PointCalculator;
use App\Service\Point\RepartitionVentes;
use App\Service\Point\RetourPoint;
use PHPUnit\Framework\TestCase;

/**
 * Le point ramené à chaque ligne de dotation : c'est ce que lisent les rapports,
 * il faut que les lignes retombent exactement sur l'arrêté.
 */
class RepartitionVentesTest extends TestCase
{
    private const BAGUETTE = 1;
    private const COCA = 2;

    /** @param int $bon id du bon, départage aussi l'ordre */
    private function dotation(int $produit, string $date, int $bon, int $unites, int $prixVenteF, int $prixCessionF = 0): DotationPoint
    {
        return new DotationPoint($produit, 1 === $produit ? 'Baguette' : 'Coca', 'pièce', new \DateTimeImmutable($date), $bon, $unites * 1000, $prixVenteF * 100, $prixCessionF * 100);
    }

    private function retour(int $produit, int $unites, MotifRetour $motif): RetourPoint
    {
        return new RetourPoint($produit, $unites * 1000, $motif);
    }

    public function testLeVenduVaAuxPlusAnciennesEtLeNonVenduAuxPlusRecentes(): void
    {
        $resultat = (new PointCalculator())->calculer(
            [$this->dotation(self::BAGUETTE, '2026-09-07', 10, 20, 150), $this->dotation(self::BAGUETTE, '2026-09-09', 11, 20, 200)],
            [$this->retour(self::BAGUETTE, 10, MotifRetour::INVENDU), $this->retour(self::BAGUETTE, 2, MotifRetour::CASSE)],
            ModeRemuneration::COMMISSION,
            1000,
        );

        $parts = RepartitionVentes::repartir($resultat, ModeRemuneration::COMMISSION);

        // Vendu 28 : 20 du lundi, 8 du mercredi. Les 12 restants du mercredi : 10 invendus, 2 cassés.
        $this->assertSame(['vendue' => 20000, 'retournee' => 0, 'perdue' => 0, 'montant' => 300000], array_diff_key($parts['10-1'], ['remuneration' => 0]));
        $this->assertSame(['vendue' => 8000, 'retournee' => 10000, 'perdue' => 2000, 'montant' => 160000], array_diff_key($parts['11-1'], ['remuneration' => 0]));

        // Commission totale 460 F, répartie au prorata : 300 et 160.
        $this->assertSame(46000, $resultat->remuneration);
        $this->assertSame([30000, 16000], [$parts['10-1']['remuneration'], $parts['11-1']['remuneration']]);
    }

    /** Invendus plus nombreux que le reste de la dernière dotation : ils remontent sur la précédente. */
    public function testLesRetoursDebordentSurLaDotationPrecedente(): void
    {
        $resultat = (new PointCalculator())->calculer(
            [$this->dotation(self::BAGUETTE, '2026-09-07', 10, 20, 150), $this->dotation(self::BAGUETTE, '2026-09-08', 11, 5, 150)],
            [$this->retour(self::BAGUETTE, 8, MotifRetour::INVENDU), $this->retour(self::BAGUETTE, 1, MotifRetour::PERIME)],
            ModeRemuneration::COMMISSION,
            0,
        );

        $parts = RepartitionVentes::repartir($resultat, ModeRemuneration::COMMISSION);

        // Vendu 16 : tout sur le lundi (20). Mardi : 5 invendus. Lundi : 3 invendus et 1 périmé.
        $this->assertSame([16000, 3000, 1000], [$parts['10-1']['vendue'], $parts['10-1']['retournee'], $parts['10-1']['perdue']]);
        $this->assertSame([0, 5000, 0], [$parts['11-1']['vendue'], $parts['11-1']['retournee'], $parts['11-1']['perdue']]);
    }

    /** La somme des lignes retombe au centime sur l'arrêté, même quand la commission ne se divise pas. */
    public function testLaCommissionRepartieRetombeExactementSurLeTotal(): void
    {
        $resultat = (new PointCalculator())->calculer(
            [
                $this->dotation(self::BAGUETTE, '2026-09-07', 10, 7, 150),
                $this->dotation(self::BAGUETTE, '2026-09-08', 11, 11, 150),
                $this->dotation(self::COCA, '2026-09-08', 11, 3, 500),
            ],
            [],
            ModeRemuneration::COMMISSION,
            750,   // 7,5 %
        );

        $parts = RepartitionVentes::repartir($resultat, ModeRemuneration::COMMISSION);

        $this->assertSame($resultat->remuneration, array_sum(array_column($parts, 'remuneration')));
        $this->assertSame($resultat->montantAttendu, array_sum(array_column($parts, 'montant')));
        $this->assertSame($resultat->qteVendue, array_sum(array_column($parts, 'vendue')));
    }

    public function testALaMargeChaqueLigneGardeSaMarge(): void
    {
        $resultat = (new PointCalculator())->calculer(
            [$this->dotation(self::COCA, '2026-09-07', 10, 12, 500, 420), $this->dotation(self::COCA, '2026-09-08', 11, 12, 600, 480)],
            [$this->retour(self::COCA, 4, MotifRetour::INVENDU)],
            ModeRemuneration::MARGE,
            0,
        );

        $parts = RepartitionVentes::repartir($resultat, ModeRemuneration::MARGE);

        // 20 vendues : 12 à (500 − 420), 8 à (600 − 480).
        $this->assertSame([96000, 96000], [$parts['10-2']['remuneration'], $parts['11-2']['remuneration']]);
        $this->assertSame($resultat->remuneration, $parts['10-2']['remuneration'] + $parts['11-2']['remuneration']);
    }

    public function testLeProrataAuPlusFortReste(): void
    {
        $this->assertSame(['a' => 4, 'b' => 3, 'c' => 3], RepartitionVentes::auProrata(10, ['a' => 1, 'b' => 1, 'c' => 1]));
        $this->assertSame(['a' => 1, 'b' => 2], RepartitionVentes::auProrata(3, ['a' => 1, 'b' => 2]));
        $this->assertSame(['a' => 0, 'b' => 0], RepartitionVentes::auProrata(500, ['a' => 0, 'b' => 0]));
        $this->assertSame(['x' => 34, 'y' => 33, 'z' => 33], RepartitionVentes::auProrata(100, ['x' => 1, 'y' => 1, 'z' => 1]), 'À reste égal, la première clé.');
    }
}
