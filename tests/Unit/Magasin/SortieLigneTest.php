<?php

namespace App\Tests\Unit\Magasin;

use App\Entity\Magasin\MagasinEmplacement;
use App\Entity\Magasin\MagasinLigneSortie;
use App\Entity\Magasin\MagasinProduit;
use App\Entity\Magasin\MagasinSortie;
use App\Entity\Utilisateur;
use App\Enum\Magasin\CategorieProduitMagasin;
use App\Enum\Magasin\DestinationSortieMagasin;
use App\Enum\Magasin\MotifSortieMagasin;
use App\Service\Magasin\QuantiteMagasin;
use PHPUnit\Framework\TestCase;

/**
 * Une ligne de sortie, sans base : conversion de la saisie, contenance figée,
 * valeur au coût figé.
 */
class SortieLigneTest extends TestCase
{
    private function ligne(MagasinProduit $produit, int $saisie, bool $enAchat): MagasinLigneSortie
    {
        $sortie = new MagasinSortie('SOR-2026-0001', new \DateTimeImmutable('2026-10-02'), DestinationSortieMagasin::ATELIER, MotifSortieMagasin::PRODUCTION, new Utilisateur('a@test.ci', 'A'));

        return new MagasinLigneSortie($sortie, $produit, new MagasinEmplacement('RESERVE', 'Réserve principale'), $saisie, $enAchat);
    }

    private function farine(): MagasinProduit
    {
        return (new MagasinProduit('Farine', CategorieProduitMagasin::MATIERE, 'kg'))->setUniteAchat('sac')->setContenanceAchat(50000);
    }

    public function testTroisSacsFontCentCinquanteKilos(): void
    {
        $ligne = $this->ligne($this->farine(), 3000, true);

        $this->assertSame('sac', $ligne->getUniteSaisie());
        $this->assertSame(150000, $ligne->getQuantite());
    }

    public function testUnDemiSacFaitVingtCinqKilos(): void
    {
        $this->assertSame(25000, $this->ligne($this->farine(), 500, true)->getQuantite());
    }

    public function testLaContenanceEstFigee(): void
    {
        $farine = $this->farine();
        $ligne = $this->ligne($farine, 3000, true);
        $farine->setContenanceAchat(25000);

        $this->assertSame(150000, $ligne->getQuantite());
        $this->assertSame(50000, $ligne->getContenance());
    }

    public function testLaValeurAttendLaValidation(): void
    {
        $ligne = $this->ligne($this->farine(), 3000, true);
        $this->assertNull($ligne->getValeur());

        $ligne->figerCout(50000);
        $this->assertSame(7500000, $ligne->getValeur());
    }

    public function testUneQuantiteNulleEstRefusee(): void
    {
        $this->expectException(\DomainException::class);
        $this->ligne($this->farine(), 0, true);
    }

    public function testLUniteSAccorde(): void
    {
        $this->assertSame('3 sacs', QuantiteMagasin::avecUnite(3000, 'sac'));
        $this->assertSame('1 sac', QuantiteMagasin::avecUnite(1000, 'sac'));
        $this->assertSame('12,5 kg', QuantiteMagasin::avecUnite(12500, 'kg', false));
    }
}
