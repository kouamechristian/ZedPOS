<?php

namespace App\Service;

/**
 * Un code-barres encodé, réduit à sa géométrie : une suite de barres noires
 * posées sur une grille de modules.
 *
 * Indépendant du support, comme {@see TicketData} : le gabarit HTML en tire un
 * SVG, et rien n'empêcherait un autre support de s'en servir. La commande
 * ESC/POS, elle, n'utilise pas cette géométrie — l'imprimante thermique dessine
 * le code elle-même à partir de la seule {@see self::$valeur}.
 */
final readonly class CodeBarres
{
    /**
     * @param string                             $valeur  chaîne encodée, imprimée en clair sous le code
     * @param list<array{x: int, largeur: int}>  $barres  barres noires, en modules
     * @param int                                $modules largeur totale, zones de silence comprises
     */
    public function __construct(
        public string $valeur,
        public array $barres,
        public int $modules,
    ) {
    }
}
