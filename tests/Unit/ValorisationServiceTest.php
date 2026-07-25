<?php

namespace App\Tests\Unit;

use App\Entity\Article;
use App\Entity\FicheTechnique;
use App\Entity\LigneFicheTechnique;
use App\Entity\MatierePremiere;
use App\Service\CalculateurCoutMatiere;
use App\Service\ValorisationService;
use PHPUnit\Framework\TestCase;

class ValorisationServiceTest extends TestCase
{
    private ValorisationService $service;

    protected function setUp(): void
    {
        $this->service = new ValorisationService(new CalculateurCoutMatiere(6000));
    }

    public function testValorisationMatiereAuCoutMoyenPondere(): void
    {
        $farine = (new MatierePremiere('Farine', 'kg'))->setCoutMoyenPondere(45000); // 450 FCFA/kg

        // 2 kg perdus → 2 × 450 = 900 FCFA (90 000 centimes).
        $this->assertSame(90000, $this->service->valoriser($farine, null, 2000));
        $this->assertSame(45000, $this->service->coutUnitaire($farine, null));
    }

    public function testValorisationArticleAuCoutDeRevient(): void
    {
        $farine = (new MatierePremiere('Farine', 'kg'))->setCoutMoyenPondere(45000);
        $pain = new Article('Baguette', 15000, 'pièce');
        $fiche = new FicheTechnique($pain);
        new LigneFicheTechnique($fiche, $farine, 250, 0); // 0,25 kg → coût de revient 112,50 FCFA

        // 3 baguettes perdues → 3 × 11 250 = 33 750 centimes.
        $this->assertSame(11250, $this->service->coutUnitaire(null, $pain));
        $this->assertSame(33750, $this->service->valoriser(null, $pain, 3000));
    }

    public function testArticleSansFicheValoriseAZero(): void
    {
        $eau = new Article('Eau', 30000, 'pièce');

        $this->assertSame(0, $this->service->valoriser(null, $eau, 5000));
    }
}
