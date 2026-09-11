<?php

namespace App\Tests\Unit;

use App\Enum\GranulariteArrete;
use App\Service\Point\PeriodeArrete;
use PHPUnit\Framework\TestCase;

/**
 * Dates d'un arrêté : raccourcis de fin, garde-fous, granularité déduite.
 * Le 11/09/2026 est un vendredi.
 */
class PeriodeArreteTest extends TestCase
{
    private function jour(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date);
    }

    public function testFinDeSemaineEtFinDeMois(): void
    {
        $this->assertSame('2026-09-13', PeriodeArrete::finDeSemaine($this->jour('2026-09-11 15:30'))->format('Y-m-d'));
        $this->assertSame('2026-09-13', PeriodeArrete::finDeSemaine($this->jour('2026-09-13'))->format('Y-m-d'), 'Un dimanche est sa propre fin de semaine.');
        $this->assertSame('2026-09-13', PeriodeArrete::finDeSemaine($this->jour('2026-09-07'))->format('Y-m-d'), 'Semaine du lundi au dimanche.');
        $this->assertSame('2026-09-30', PeriodeArrete::finDeMois($this->jour('2026-09-11'))->format('Y-m-d'));
        $this->assertSame('2026-02-28', PeriodeArrete::finDeMois($this->jour('2026-02-03'))->format('Y-m-d'));
    }

    /** Un raccourci qui tomberait dans le futur est grisé : on n'arrête pas demain. */
    public function testLesRaccourcisNeProposentNiLeFuturNiAvantLeDebut(): void
    {
        $raccourcis = PeriodeArrete::raccourcis($this->jour('2026-09-01'), $this->jour('2026-09-11'));

        $this->assertSame('2026-09-11', $raccourcis['aujourdhui']->format('Y-m-d'));
        $this->assertSame('2026-09-06', $raccourcis['semaine']->format('Y-m-d'), 'Fin de la semaine du début : le point suivant reprend le lundi.');
        $this->assertNull($raccourcis['mois'], 'Le 30 septembre n\'est pas encore arrivé.');

        $mercredi = PeriodeArrete::raccourcis($this->jour('2026-09-09'), $this->jour('2026-09-09'));
        $this->assertSame('2026-09-09', $mercredi['aujourdhui']->format('Y-m-d'));
        $this->assertNull($mercredi['semaine']);
    }

    public function testLaFinNePrecedePasLeDebutEtNeDepassePasAujourdhui(): void
    {
        PeriodeArrete::verifierFin($this->jour('2026-09-01'), $this->jour('2026-09-01'), $this->jour('2026-09-11'));
        $this->addToAssertionCount(1);

        foreach ([['2026-08-31', 'finir avant'], ['2026-09-12', 'pas encore eu lieu']] as [$fin, $message]) {
            try {
                PeriodeArrete::verifierFin($this->jour('2026-09-01'), $this->jour($fin), $this->jour('2026-09-11'));
                $this->fail('Fin acceptée : '.$fin);
            } catch (\DomainException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testLaGranulariteSeDeduitDesDates(): void
    {
        $this->assertSame(GranulariteArrete::JOUR, PeriodeArrete::granularite($this->jour('2026-09-11'), $this->jour('2026-09-11')));
        $this->assertSame(GranulariteArrete::SEMAINE, PeriodeArrete::granularite($this->jour('2026-09-07'), $this->jour('2026-09-13')));
        $this->assertSame(GranulariteArrete::SEMAINE, PeriodeArrete::granularite($this->jour('2026-09-10'), $this->jour('2026-09-13')), 'Fin de la semaine du début, même partielle.');
        $this->assertSame(GranulariteArrete::MOIS, PeriodeArrete::granularite($this->jour('2026-09-01'), $this->jour('2026-09-30')));
        $this->assertSame(GranulariteArrete::LIBRE, PeriodeArrete::granularite($this->jour('2026-09-01'), $this->jour('2026-09-11')));
    }
}
