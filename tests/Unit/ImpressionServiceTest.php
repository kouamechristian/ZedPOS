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

    /**
     * Le code-barres est confié au firmware : on lui envoie la chaîne, pas une
     * image. Une trame calculée ici serait rééchantillonnée par la tête
     * d'impression et des barres d'un point finiraient par se confondre.
     */
    public function testLeNumeroEstImprimeEnCodeBarresNatif(): void
    {
        $sortie = (new ImpressionService())->commandeEscPos($this->ticket());

        $donnees = '{BV260725-00001';

        // GS k 73 n <données> : Code 128, longueur préfixée, jeu B sélectionné
        // dans les données elles-mêmes par « {B ».
        $this->assertStringContainsString(
            "\x1Dk\x49".\chr(\strlen($donnees)).$donnees,
            $sortie,
            'Le numéro doit partir en Code 128 jeu B.',
        );

        $this->assertStringContainsString("\x1Dh\x3C", $sortie, 'La hauteur du code (GS h) doit être fixée.');
        $this->assertStringContainsString("\x1Dw\x02", $sortie, 'La largeur de module (GS w) doit être fixée.');
        $this->assertStringContainsString("\x1DH\x02", $sortie, 'Le numéro doit être imprimé en clair sous le code.');

        // L'emplacement réservé au QR a disparu avec le code-barres qui le remplace.
        $this->assertStringNotContainsString('QR', $sortie);
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
