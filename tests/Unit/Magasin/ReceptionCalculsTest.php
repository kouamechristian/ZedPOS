<?php

namespace App\Tests\Unit\Magasin;

use App\Service\Magasin\ReceptionMagasinService;
use App\Service\Magasin\SaisieQuantite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Calculs purs de la réception : lecture des quantités sans flottant, coût moyen
 * pondéré en entiers.
 */
class ReceptionCalculsTest extends TestCase
{
    public static function saisies(): iterable
    {
        yield 'entier' => ['20', 20000];
        yield 'virgule' => ['2,5', 2500];
        yield 'point' => ['2.25', 2250];
        yield 'trois décimales' => ['0,125', 125];
        yield 'milliers espacés' => ['1 000', 1000000];
        yield 'zéro' => ['0', 0];
        yield 'vide' => ['', null];
        yield 'espaces seuls' => ['  ', null];
    }

    #[DataProvider('saisies')]
    public function testLaSaisieSeLitEnMillimes(string $saisie, ?int $attendu): void
    {
        $this->assertSame($attendu, SaisieQuantite::millimes($saisie, 'Farine'));
    }

    public static function saisiesIllisibles(): iterable
    {
        yield 'lettres' => ['vingt'];
        yield 'quatre décimales' => ['1,2345'];
        yield 'négatif' => ['-3'];
        yield 'deux virgules' => ['1,2,3'];
    }

    #[DataProvider('saisiesIllisibles')]
    public function testUneSaisieIllisibleEstRefusee(string $saisie): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Farine : quantité illisible');
        SaisieQuantite::millimes($saisie, 'Farine');
    }

    public function testLePrixSeLitEnFcfaEntiers(): void
    {
        $this->assertSame(2500000, SaisieQuantite::prix('25 000', 'Farine'));
        $this->assertNull(SaisieQuantite::prix('', 'Farine'));

        $this->expectException(\DomainException::class);
        SaisieQuantite::prix('25000,50', 'Farine');
    }

    public function testLeTexteReaffichable(): void
    {
        $this->assertSame('20', SaisieQuantite::texte(20000));
        $this->assertSame('2,5', SaisieQuantite::texte(2500));
        $this->assertSame('0,125', SaisieQuantite::texte(125));
        $this->assertSame('', SaisieQuantite::texte(null));
    }

    /** Premier achat : le CMP est le prix ramené à l'unité de stock. 25 000 F le sac de 50 kg → 500 F/kg. */
    public function testLePremierAchatFixeLeCoutMoyen(): void
    {
        // 20 sacs = 1 000 kg = 1 000 000 millièmes ; valeur 20 × 25 000 F = 50 000 000 centimes.
        $this->assertSame(50000, ReceptionMagasinService::cmpApresEntree(0, 0, 1000000, 50000000));
    }

    public function testLeCoutMoyenSePondere(): void
    {
        // 1 000 kg à 500 F, puis 500 kg à 560 F → (500 000 + 280 000) / 1 500 = 520 F.
        $this->assertSame(52000, ReceptionMagasinService::cmpApresEntree(1000000, 50000, 500000, 28000000));
    }

    public function testLeCoutMoyenSArrondiAuCentime(): void
    {
        // 1 kg à 1 centime + 2 kg pour 4 centimes : 5 / 3 = 1,67 centime → 2.
        $this->assertSame(2, ReceptionMagasinService::cmpApresEntree(1000, 1, 2000, 4));
    }

    public function testUnStockNegatifRepartDuPrix(): void
    {
        $this->assertSame(50000, ReceptionMagasinService::cmpApresEntree(-10000, 99999, 1000000, 50000000));
    }

    public function testLAnnulationRetireLaLivraisonDuCoutMoyen(): void
    {
        $apres = ReceptionMagasinService::cmpApresEntree(1000000, 50000, 500000, 28000000);
        $this->assertSame(50000, ReceptionMagasinService::cmpApresRetrait(1500000, $apres, 500000, 28000000));
    }

    public function testLAnnulationQuiVideLeStockGardeLeCoutMoyen(): void
    {
        $this->assertSame(50000, ReceptionMagasinService::cmpApresRetrait(1000000, 50000, 1000000, 50000000));
    }
}
