<?php

namespace App\Tests\Unit;

use App\Entity\Article;
use App\Entity\FicheTechnique;
use App\Entity\LigneFicheTechnique;
use App\Entity\MatierePremiere;
use App\Service\CalculateurCoutMatiere;
use PHPUnit\Framework\TestCase;

class CalculateurCoutMatiereTest extends TestCase
{
    private CalculateurCoutMatiere $calculateur;

    protected function setUp(): void
    {
        $this->calculateur = new CalculateurCoutMatiere(6000); // seuil 60 %
    }

    private function article(int $prixCentimes): Article
    {
        return new Article('Article test', $prixCentimes, 'pièce');
    }

    private function matiere(int $coutCentimesParUnite): MatierePremiere
    {
        return (new MatierePremiere('Matière', 'kg'))->setCoutMoyenPondere($coutCentimesParUnite);
    }

    public function testCoutAjusteDeLaPerteDeTransformation(): void
    {
        // 1 kg à 400 FCFA/kg (40 000 centimes), avec 50 % de perte de transformation.
        // Coût brut = 40 000 ; ajusté = 40 000 / (1 − 0,5) = 80 000 centimes (800 FCFA).
        $article = $this->article(100000);
        $fiche = new FicheTechnique($article);
        new LigneFicheTechnique($fiche, $this->matiere(40000), 1000, 5000);

        $resultat = $this->calculateur->calculer($article);

        $this->assertTrue($resultat->calculable);
        $this->assertSame(80000, $resultat->coutRevient, 'La perte de 50 % doit doubler le coût de revient.');
        $this->assertSame(20000, $resultat->margeBrute);
        $this->assertSame(2000, $resultat->tauxMargeBp); // 20 %
        $this->assertTrue($resultat->sousSeuil, 'Une marge de 20 % est sous le seuil de 60 %.');
    }

    public function testCoutRevientMultiLigneAvecPertes(): void
    {
        // Baguette : farine 0,25 kg à 450 FCFA/kg (perte 3 %) + levure 0,005 kg à 3000 FCFA/kg (perte 2 %).
        $article = $this->article(15000); // 150 FCFA
        $fiche = new FicheTechnique($article);
        new LigneFicheTechnique($fiche, $this->matiere(45000), 250, 300);
        new LigneFicheTechnique($fiche, $this->matiere(300000), 5, 200);

        $resultat = $this->calculateur->calculer($article);

        // 11 598 (farine) + 1 531 (levure) = 13 129 centimes.
        $this->assertSame(13129, $resultat->coutRevient);
        $this->assertSame(1871, $resultat->margeBrute);
        $this->assertSame(1247, $resultat->tauxMargeBp); // 12,47 %
        $this->assertTrue($resultat->sousSeuil);
    }

    public function testMargeAuDessusDuSeuilNeDeclenchePasDAlerte(): void
    {
        // 1 kg à 200 FCFA/kg, sans perte, vendu 1000 FCFA → marge 80 %.
        $article = $this->article(100000);
        $fiche = new FicheTechnique($article);
        new LigneFicheTechnique($fiche, $this->matiere(20000), 1000, 0);

        $resultat = $this->calculateur->calculer($article);

        $this->assertSame(20000, $resultat->coutRevient);
        $this->assertSame(8000, $resultat->tauxMargeBp); // 80 %
        $this->assertFalse($resultat->sousSeuil);
    }

    public function testSeuilParametrablePeutDeclencherLAlerte(): void
    {
        $article = $this->article(100000);
        $fiche = new FicheTechnique($article);
        new LigneFicheTechnique($fiche, $this->matiere(20000), 1000, 0); // marge 80 %

        // Avec un seuil relevé à 90 %, une marge de 80 % passe en alerte.
        $resultat = $this->calculateur->calculer($article, 9000);

        $this->assertSame(9000, $resultat->seuilBp);
        $this->assertTrue($resultat->sousSeuil);
    }

    public function testArticleSansFicheTechniqueNonCalculable(): void
    {
        $resultat = $this->calculateur->calculer($this->article(30000));

        $this->assertFalse($resultat->calculable);
        $this->assertSame(0, $resultat->coutRevient);
        $this->assertFalse($resultat->sousSeuil);
    }
}
