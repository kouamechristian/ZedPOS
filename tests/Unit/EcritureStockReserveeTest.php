<?php

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Le stock ne s'écrit que par `StockManager`.
 *
 * Aucune règle PHP n'empêche d'appeler un setter public ou d'instancier une
 * entité : ce test relit les sources. Il existe parce que le défaut qu'il garde
 * est muet — un `setStockActuel()` ajouté dans un contrôleur fait diverger le stock
 * de ses mouvements, tout s'affiche normalement, et l'écart ne se découvre qu'à
 * l'inventaire suivant.
 */
class EcritureStockReserveeTest extends TestCase
{
    private const AUTORISE = 'src/Service/StockManager.php';

    /** Motif interdit => ce qu'il écrit. */
    private const ECRITURES = [
        '/->setStockActuel\(/' => 'le champ historique stockActuel',
        '/new\s+MouvementStock\(/' => 'un mouvement de stock',
        '/(INSERT\s+INTO|UPDATE)\s+stock_courant\b/i' => 'le stock courant',
        '/stock_actuel\s*=/i' => 'la colonne stock_actuel',
    ];

    public function testSeulStockManagerEcritLeStock(): void
    {
        $racine = \dirname(__DIR__, 2);
        $fautes = [];

        $fichiers = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine.'/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($fichiers as $fichier) {
            if ('php' !== $fichier->getExtension()) {
                continue;
            }

            $relatif = str_replace('\\', '/', substr($fichier->getPathname(), \strlen($racine) + 1));
            if (self::AUTORISE === $relatif) {
                continue;
            }

            $source = (string) file_get_contents($fichier->getPathname());
            foreach (self::ECRITURES as $motif => $cible) {
                if (1 === preg_match($motif, $source)) {
                    $fautes[] = \sprintf('%s écrit %s hors de StockManager.', $relatif, $cible);
                }
            }
        }

        $this->assertSame([], $fautes);
    }
}
