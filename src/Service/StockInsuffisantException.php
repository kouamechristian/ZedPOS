<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\Emplacement;
use App\Entity\MatierePremiere;

/**
 * Mouvement refusé : il aurait laissé un stock négatif.
 *
 * Hérite de `\DomainException` comme les autres refus métier du projet : les
 * contrôleurs qui les attrapent déjà (inventaire) affichent le message sans
 * rien de plus. Le message est destiné à l'utilisateur, les quantités restent
 * lisibles pour qui voudrait réagir autrement.
 */
final class StockInsuffisantException extends \DomainException
{
    /**
     * @param int $disponible stock avant le mouvement, en millièmes (peut être négatif)
     * @param int $demande    quantité sortante demandée, en millièmes (positive)
     */
    public function __construct(
        public readonly string $produit,
        public readonly string $emplacement,
        public readonly int $disponible,
        public readonly int $demande,
        public readonly string $unite,
    ) {
        parent::__construct(\sprintf(
            'Stock insuffisant pour « %s » (%s) : %s %s disponible, %s %s demandé.',
            $produit,
            $emplacement,
            self::quantite($disponible),
            $unite,
            self::quantite($demande),
            $unite,
        ));
    }

    public static function pour(Article|MatierePremiere $produit, Emplacement $emplacement, int $disponible, int $demande): self
    {
        return new self(
            $produit->getNom(),
            $emplacement->getLibelle(),
            $disponible,
            $demande,
            $produit instanceof Article ? $produit->getUnite() : $produit->getUniteStock(),
        );
    }

    /** Millièmes → « 2,5 », « 1 200 », « -3 » : arithmétique entière, jamais de flottant. */
    private static function quantite(int $millimes): string
    {
        $absolu = abs($millimes);
        $decimales = rtrim(\sprintf('%03d', $absolu % 1000), '0');

        return ($millimes < 0 ? '-' : '')
            .number_format(intdiv($absolu, 1000), 0, ',', ' ')
            .('' !== $decimales ? ','.$decimales : '');
    }
}
