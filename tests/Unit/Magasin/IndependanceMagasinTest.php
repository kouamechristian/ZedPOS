<?php

namespace App\Tests\Unit\Magasin;

use PHPUnit\Framework\TestCase;

/**
 * Le module Magasin est **indépendant** du stock de la boutique, des fiches
 * techniques, des ventes, de la caisse, des stands et de la production.
 *
 * Ce test relit chaque fichier PHP du module (tout chemin contenant un dossier
 * `Magasin`) et échoue s'il importe — ou cite par son nom complet — une classe de
 * ces domaines. Le défaut qu'il prévient est muet : un `use` glissé « pour
 * réutiliser un calcul » recouplerait les deux stocks, et une vente finirait par
 * faire bouger le magasin sans que personne l'ait décidé.
 */
class IndependanceMagasinTest extends TestCase
{
    /** Classes interdites dans le module, par nom complet. */
    private const INTERDITES = [
        'App\\Entity\\MatierePremiere',
        'App\\Entity\\FicheTechnique',
        'App\\Entity\\LigneFicheTechnique',
        'App\\Service\\CalculateurCoutMatiere',
        'App\\Service\\StockManager',
        'App\\Service\\DemandeMouvementStock',
        'App\\Service\\StockInsuffisantException',
        'App\\Entity\\MouvementStock',
        'App\\Entity\\StockCourant',
        'App\\Entity\\Emplacement',
        'App\\Enum\\TypeEmplacement',
        'App\\Enum\\TypeMouvementStock',
        'App\\EventListener\\DestockageVenteListener',
        'App\\Entity\\Inventaire',
        'App\\Entity\\LigneInventaire',
        'App\\Service\\InventaireService',
        // Ventes et caisse.
        'App\\Entity\\Vente',
        'App\\Entity\\LigneVente',
        'App\\Entity\\Reglement',
        'App\\Entity\\SessionCaisse',
        'App\\Service\\EncaissementService',
        'App\\Service\\SessionCaisseService',
        // Stands.
        'App\\Entity\\BonDotation',
        'App\\Entity\\LigneDotation',
        'App\\Entity\\Arrete',
        'App\\Service\\BonDotationService',
        'App\\Service\\ArreteService',
        // Production et vitrine.
        'App\\Entity\\SaisieProduction',
        'App\\Entity\\FicheProduction',
        'App\\Service\\ProductionService',
    ];

    /**
     * Références interdites trouvées dans un source PHP : les `use` et les noms
     * complets cités dans le code (`\App\Entity\Vente::class`).
     *
     * @return list<string>
     */
    public static function referencesInterdites(string $source): array
    {
        $trouvees = [];
        foreach (self::INTERDITES as $classe) {
            $motif = '/(?:^\s*use\s+|\\\\)'.preg_quote($classe, '/').'(?![A-Za-z0-9_\\\\])/m';
            if (preg_match($motif, $source)) {
                $trouvees[] = $classe;
            }
        }

        return $trouvees;
    }

    /** @return list<string> les fichiers PHP du module */
    private static function fichiersDuModule(): array
    {
        $racine = \dirname(__DIR__, 3).'/src';
        $fichiers = [];
        $iterateur = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterateur as $fichier) {
            $chemin = str_replace('\\', '/', $fichier->getPathname());
            if (str_ends_with($chemin, '.php') && str_contains($chemin, '/Magasin/')) {
                $fichiers[] = $chemin;
            }
        }
        sort($fichiers);

        return $fichiers;
    }

    public function testLeModuleNImporteAucuneClasseInterdite(): void
    {
        $fichiers = self::fichiersDuModule();
        $this->assertNotEmpty($fichiers, 'Aucun fichier du module trouvé : le test ne vérifierait rien.');

        $fautes = [];
        foreach ($fichiers as $fichier) {
            foreach (self::referencesInterdites((string) file_get_contents($fichier)) as $classe) {
                $fautes[] = basename(\dirname($fichier)).'/'.basename($fichier).' → '.$classe;
            }
        }

        $this->assertSame([], $fautes, "Le module Magasin doit rester indépendant :\n".implode("\n", $fautes));
    }

    /** Le détecteur lui-même : sans lui, un test vert ne prouverait rien. */
    public function testLeDetecteurAttrapeUnImportEtUnNomComplet(): void
    {
        $source = "<?php\nnamespace App\\Service\\Magasin;\n\nuse App\\Service\\StockManager;\nuse App\\Entity\\Utilisateur;\n\$x = \\App\\Entity\\Vente::class;\n";

        $this->assertSame(['App\\Service\\StockManager', 'App\\Entity\\Vente'], self::referencesInterdites($source));
        $this->assertSame([], self::referencesInterdites("<?php\nuse App\\Entity\\Magasin\\MagasinEmplacement;\nuse App\\Entity\\VenteX;\n"), 'Un nom voisin n\'est pas une faute.');
    }
}
