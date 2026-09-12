<?php

namespace App\Service\Rapport;

/**
 * Un article dans le rapport de journée : ce qui en a été vendu, et pour combien.
 *
 * Immuable, sans dépendance : le rapport se calcule une fois, le gabarit HTML et
 * le PDF le relisent tel quel. C'est ce qui garantit que l'écran et le fichier
 * téléchargé ne peuvent pas afficher deux chiffres différents.
 */
final readonly class ArticleVendu
{
    /**
     * @param string $nom           libellé de l'article
     * @param int    $quantite      quantité vendue, en millièmes d'unité
     * @param ?int   $prixUnitaire  prix unitaire TTC en centimes, ou **null** si
     *                              l'article a été vendu à plusieurs prix dans la
     *                              journée (prix modifié en cours de journée) —
     *                              afficher l'un des deux serait un mensonge
     * @param int    $montant       montant TTC vendu, en centimes, remises de
     *                              ligne déduites
     */
    public function __construct(
        public string $nom,
        public int $quantite,
        public ?int $prixUnitaire,
        public int $montant,
    ) {
    }
}
