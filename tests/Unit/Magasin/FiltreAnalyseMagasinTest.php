<?php

namespace App\Tests\Unit\Magasin;

use App\Entity\Magasin\MagasinEmplacement;
use App\Enum\Magasin\CategorieProduitMagasin;
use App\Service\Magasin\FiltreAnalyseMagasin;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Lecture de la période de l'analyse : raccourcis, dates libres, et repli sur le
 * mois en cours — annoncé — quand l'adresse ne veut rien dire.
 */
class FiltreAnalyseMagasinTest extends TestCase
{
    private const AUJOURDHUI = '2026-10-03'; // un samedi

    private function filtre(array $query, ?MagasinEmplacement $emplacement = null): FiltreAnalyseMagasin
    {
        return FiltreAnalyseMagasin::depuis(new Request($query), static fn (int $id): ?MagasinEmplacement => $emplacement, new \DateTimeImmutable(self::AUJOURDHUI));
    }

    private function periode(FiltreAnalyseMagasin $f): string
    {
        return $f->du->format('Y-m-d').' → '.$f->au->format('Y-m-d');
    }

    public function testSansParametreCEstLeMoisEnCours(): void
    {
        $f = $this->filtre([]);
        $this->assertSame('2026-10-01 → 2026-10-03', $this->periode($f));
        $this->assertSame('mois', $f->periode);
        $this->assertNull($f->avertissement);
    }

    public function testLesRaccourcis(): void
    {
        $this->assertSame('2026-10-03 → 2026-10-03', $this->periode($this->filtre(['periode' => 'jour'])));
        $this->assertSame('2026-09-28 → 2026-10-03', $this->periode($this->filtre(['periode' => 'semaine'])), 'La semaine part du lundi.');
    }

    public function testLesDatesLibres(): void
    {
        $f = $this->filtre(['periode' => 'libre', 'du' => '2026-09-01', 'au' => '2026-09-30']);
        $this->assertSame('2026-09-01 → 2026-09-30', $this->periode($f));
        $this->assertSame('2026-10-01', $f->fin()->format('Y-m-d'), 'La fin est exclusive : le lendemain à minuit.');
        $this->assertSame('du 01/09/2026 au 30/09/2026', $f->libelle());
        $this->assertSame('le 03/10/2026', $this->filtre(['periode' => 'jour'])->libelle());
    }

    public static function periodesInvalides(): iterable
    {
        yield 'illisible' => [['du' => 'hier', 'au' => '2026-10-01']];
        yield 'inversée' => [['du' => '2026-10-02', 'au' => '2026-10-01']];
        yield 'future' => [['du' => '2026-10-01', 'au' => '2026-10-10']];
        yield 'plus d\'un an' => [['du' => '2025-01-01', 'au' => '2026-10-01']];
        yield 'date impossible' => [['du' => '2026-02-30', 'au' => '2026-03-01']];
    }

    /** @dataProvider periodesInvalides */
    #[\PHPUnit\Framework\Attributes\DataProvider('periodesInvalides')]
    public function testUnePeriodeInvalideRetombeSurLeMoisEnLeDisant(array $dates): void
    {
        $f = $this->filtre(['periode' => 'libre'] + $dates);
        $this->assertSame('2026-10-01 → 2026-10-03', $this->periode($f));
        $this->assertNotNull($f->avertissement);
    }

    public function testCategorieEtEmplacementSontLus(): void
    {
        $froid = new MagasinEmplacement('FROID', 'Chambre froide');
        $f = $this->filtre(['categorie' => 'EMBALLAGE', 'emplacement' => '7'], $froid);

        $this->assertSame(CategorieProduitMagasin::EMBALLAGE, $f->categorie);
        $this->assertSame($froid, $f->emplacement);
        $this->assertNull($this->filtre(['categorie' => 'INCONNUE', 'emplacement' => 'x'], $froid)->categorie);
        $this->assertNull($this->filtre(['emplacement' => 'x'], $froid)->emplacement, 'Un identifiant illisible est ignoré.');
    }
}
