<?php

namespace App\Tests\Unit;

use App\Service\ImpressionService;
use App\Service\TicketData;
use PHPUnit\Framework\TestCase;

class ImpressionServiceTest extends TestCase
{
    private function ticket(): TicketData
    {
        return new TicketData(
            raisonSociale: 'Boulangerie Aké',
            adresse: 'Abengourou',
            ncc: 'CI-123456',
            telephone: '0700000000',
            pied: 'Merci à bientôt',
            numero: 'V260725-00001',
            dateHeure: new \DateTimeImmutable('2026-07-25 08:30'),
            caissier: 'Fatou Traoré',
            lignes: [[
                'nom' => 'Crème brûlée',
                'quantiteMillimes' => 2000,
                'prixUnitaire' => 50000,
                'montant' => 100000,
                'commentaire' => 'Sans sucre',
            ]],
            ventilationTva: [['tauxBp' => 1800, 'base' => 84746, 'montant' => 15254]],
            reglements: [['libelle' => 'Espèces', 'montant' => 150000]],
            totalHt: 84746,
            totalTva: 15254,
            totalTtc: 100000,
            remise: 0,
            rendu: 50000,
        );
    }

    public function testCommandeEscPosContientLesCommandesMaterielles(): void
    {
        $sortie = (new ImpressionService())->commandeEscPos($this->ticket());

        $this->assertStringStartsWith("\x1B@", $sortie, 'La commande doit débuter par l\'initialisation ESC @.');
        $this->assertStringContainsString("\x1DV\x00", $sortie, 'La coupe papier (GS V 0) doit être présente.');
        $this->assertStringContainsString("\x1Bp\x00", $sortie, "L'ouverture du tiroir (ESC p) doit être présente.");
    }

    public function testTexteTranslittereEnAscii(): void
    {
        $sortie = (new ImpressionService())->commandeEscPos($this->ticket());

        $this->assertStringContainsString('Creme brulee', $sortie);
        $this->assertStringContainsString('Fatou Traore', $sortie);
        $this->assertStringContainsString('Merci a bientot', $sortie);
        $this->assertStringContainsString('TOTAL', $sortie);
        $this->assertStringContainsString('Rendu', $sortie);
        // Aucun caractère accentué ne doit subsister (impression thermique ASCII).
        $this->assertDoesNotMatchRegularExpression('/[éèêàâçùûôî]/u', $sortie);
    }
}
