<?php

namespace App\Tests\Unit;

use App\Enum\GranulariteRapport;
use App\Service\Rapport\FiltreRapport;
use PHPUnit\Framework\TestCase;

/** Filtre commun des rapports des stands. « Aujourd'hui » : vendredi 11/09/2026. */
class FiltreRapportTest extends TestCase
{
    private function aujourdhui(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-11');
    }

    /** @param array<string, string> $parametres */
    private function plage(array $parametres): array
    {
        $filtre = FiltreRapport::depuis($parametres, $this->aujourdhui());

        return [$filtre->du->format('Y-m-d'), $filtre->au->format('Y-m-d')];
    }

    public function testLesQuatreRaccourcis(): void
    {
        $this->assertSame(['2026-09-11', '2026-09-11'], $this->plage(['periode' => FiltreRapport::AUJOURDHUI]));
        $this->assertSame(['2026-09-05', '2026-09-11'], $this->plage(['periode' => FiltreRapport::SEPT_JOURS]));
        $this->assertSame(['2026-09-01', '2026-09-11'], $this->plage(['periode' => FiltreRapport::MOIS_EN_COURS]));
        $this->assertSame(['2026-08-01', '2026-08-31'], $this->plage(['periode' => FiltreRapport::MOIS_PRECEDENT]));
        $this->assertSame(['2026-09-01', '2026-09-11'], $this->plage([]), 'Sans filtre : le mois en cours.');

        // Mois précédent d'un 31 mars : février entier, pas un « 31 février ».
        [$du, $au] = FiltreRapport::raccourcis(new \DateTimeImmutable('2027-03-31'))[FiltreRapport::MOIS_PRECEDENT];
        $this->assertSame(['2027-02-01', '2027-02-28'], [$du->format('Y-m-d'), $au->format('Y-m-d')]);

        $filtre = FiltreRapport::depuis(['du' => '2026-09-05', 'au' => '2026-09-11'], $this->aujourdhui());
        $this->assertSame(FiltreRapport::SEPT_JOURS, $filtre->raccourci($this->aujourdhui()), 'Une plage saisie à la main allume son raccourci.');
    }

    public function testLesTranchesSuiventLaGranularite(): void
    {
        $filtre = FiltreRapport::creer(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-15'), GranulariteRapport::SEMAINE);

        // Le 1er septembre est un mardi : la première semaine commence le lundi 31 août.
        $this->assertSame(['2026-08-31', '2026-09-07', '2026-09-14'], array_keys($filtre->tranches()));
        $this->assertSame('sem. du 31/08', $filtre->tranches()['2026-08-31']);
        $this->assertSame('2026-09-07', $filtre->cle('2026-09-13'));

        $mois = FiltreRapport::creer(new \DateTimeImmutable('2026-08-20'), new \DateTimeImmutable('2026-10-02'), GranulariteRapport::MOIS);
        $this->assertSame(['2026-08-01' => 'août 2026', '2026-09-01' => 'sept. 2026', '2026-10-01' => 'oct. 2026'], $mois->tranches());

        $jours = FiltreRapport::creer(new \DateTimeImmutable('2026-09-10'), new \DateTimeImmutable('2026-09-11'), GranulariteRapport::JOUR);
        $this->assertSame(['2026-09-10' => '10/09', '2026-09-11' => '11/09'], $jours->tranches());
    }

    public function testUneSaisieInvalideRetombeSurLeMoisEnCoursEnLeDisant(): void
    {
        foreach ([
            ['du' => '2026-09-11', 'au' => '2026-09-01'],
            ['du' => '2025-01-01', 'au' => '2026-09-11'],
            ['du' => 'hier', 'au' => '2026-09-11'],
        ] as $parametres) {
            $filtre = FiltreRapport::depuis($parametres, $this->aujourdhui());
            $this->assertSame('2026-09-01', $filtre->du->format('Y-m-d'));
            $this->assertNotNull($filtre->erreur, json_encode($parametres));
        }

        $this->assertSame(GranulariteRapport::JOUR, FiltreRapport::depuis(['granularite' => 'annee'], $this->aujourdhui())->granularite);
    }

    public function testLeNomDuFichierPorteLesFiltres(): void
    {
        $filtre = FiltreRapport::depuis(['du' => '2026-09-01', 'au' => '2026-09-30', 'granularite' => 'semaine'], $this->aujourdhui());

        $this->assertSame('rapport-stands_2026-09-01_2026-09-30_semaine.csv', $filtre->nomFichier('stands'));
    }
}
