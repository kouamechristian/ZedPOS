<?php

namespace App\Tests\Unit;

use App\Service\CodeBarres128;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Encodeur Code 128 jeu B.
 *
 * Un code-barres faux ne se voit pas : il s'imprime proprement et c'est le
 * lecteur, au comptoir, qui refuse. D'où un cas déroulé **à la main** depuis la
 * table normative, plutôt que des vérifications de forme seules.
 */
class CodeBarres128Test extends TestCase
{
    private CodeBarres128 $encodeur;

    protected function setUp(): void
    {
        $this->encodeur = new CodeBarres128();
    }

    /**
     * Encodage complet de « A », déroulé depuis la table :
     *
     *   START B (104) → 211214
     *   « A » (65−32 = 33) → 111323
     *   contrôle (104 + 1×33) mod 103 = 34 → 131123
     *   STOP (106) → 2331112
     *
     * Soit 46 modules de symbole, plus 10 de zone de silence de chaque côté.
     */
    public function testEncodageDeReferenceCaractereUnique(): void
    {
        $code = $this->encodeur->encoder('A');

        $this->assertSame('A', $code->valeur);
        $this->assertSame(66, $code->modules, '46 modules de symbole + 2 × 10 de silence.');

        // Trois barres par motif de six éléments, quatre pour le STOP qui en a sept.
        $this->assertCount(13, $code->barres);

        $this->assertSame(
            [
                ['x' => 10, 'largeur' => 2], ['x' => 13, 'largeur' => 1], ['x' => 16, 'largeur' => 1],
                ['x' => 21, 'largeur' => 1], ['x' => 23, 'largeur' => 1], ['x' => 27, 'largeur' => 2],
                ['x' => 32, 'largeur' => 1], ['x' => 36, 'largeur' => 1], ['x' => 38, 'largeur' => 2],
                ['x' => 43, 'largeur' => 2], ['x' => 48, 'largeur' => 3], ['x' => 52, 'largeur' => 1],
                ['x' => 54, 'largeur' => 2],
            ],
            $code->barres,
        );
    }

    /**
     * La somme de contrôle pondère chaque caractère par son rang : deux caractères
     * permutés donnent donc un symbole différent. C'est toute la protection du
     * format contre une lecture partielle.
     */
    public function testLaSommeDeControleDependDeLOrdre(): void
    {
        $ab = $this->encodeur->encoder('AB');
        $ba = $this->encodeur->encoder('BA');

        $this->assertSame($ab->modules, $ba->modules, 'Même longueur : seuls les motifs changent.');
        $this->assertNotSame($ab->barres, $ba->barres);
    }

    public function testUnNumeroDeTicketEstEncodable(): void
    {
        $this->assertTrue($this->encodeur->supporte('V260725-00001'));

        $code = $this->encodeur->encoder('V260725-00001');

        // 13 caractères : START + 13 données + contrôle = 15 motifs de 11 modules,
        // plus le STOP (13) et les deux zones de silence.
        $this->assertSame(15 * 11 + 13 + 20, $code->modules);
        $this->assertSame('V260725-00001', $code->valeur);
    }

    /**
     * Les barres se suivent sans jamais se chevaucher ni sortir du cadre — sans
     * quoi le SVG dessinerait un aplat noir au lieu d'un code.
     */
    public function testLesBarresNeSeChevauchentPas(): void
    {
        $code = $this->encodeur->encoder('V260725-00001');
        $precedente = 0;

        foreach ($code->barres as $barre) {
            $this->assertGreaterThan($precedente, $barre['x'], 'Deux barres collées formeraient un pâté.');
            $this->assertGreaterThan(0, $barre['largeur']);
            $precedente = $barre['x'] + $barre['largeur'];
        }

        $this->assertLessThanOrEqual($code->modules - 10, $precedente, 'La zone de silence de droite doit rester vierge.');
    }

    public function testZonesDeSilenceDeDixModules(): void
    {
        $code = $this->encodeur->encoder('V260725-00001');

        $this->assertSame(10, $code->barres[0]['x'], 'Sans zone de silence, un lecteur ne trouve pas le départ.');
    }

    #[DataProvider('chainesNonEncodables')]
    public function testLesChainesHorsJeuBSontRefusees(string $valeur): void
    {
        $this->assertFalse($this->encodeur->supporte($valeur));

        $this->expectException(\DomainException::class);
        $this->encodeur->encoder($valeur);
    }

    /** @return iterable<string, array{string}> */
    public static function chainesNonEncodables(): iterable
    {
        yield 'chaîne vide' => [''];
        yield 'accent' => ['CRÊPE'];
        yield 'retour à la ligne' => ["V260725\n00001"];
        yield 'caractère de contrôle' => ["V\x01"];
    }
}
