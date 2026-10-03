<?php

namespace App\Service\Magasin;

use App\Entity\Magasin\MagasinProduit;

/**
 * Mise en forme d'une quantité du magasin : « 20 sacs (1 000 kg) ».
 *
 * Présentation seulement : les quantités circulent partout en millièmes d'unité
 * de stock. Le magasin compte en sacs et l'atelier pèse en kilos ; afficher les
 * deux évite la division de tête devant la palette. Sans unité d'achat :
 * « 12,5 kg ».
 */
final class QuantiteMagasin
{
    /** @param int $millimesStock millièmes d'unité de stock */
    public static function formater(MagasinProduit $produit, int $millimesStock): string
    {
        $stock = self::nombre($millimesStock).' '.$produit->getUniteStock();
        $achat = $produit->versUniteAchat($millimesStock);
        if (null === $achat) {
            return $stock;
        }

        return self::nombre($achat).' '.self::accorder((string) $produit->getUniteAchat(), $achat).' ('.$stock.')';
    }

    /** Nombre en français sans décimale inutile : 1 000 ; 2,5 ; −3. Sans flottant. */
    public static function nombre(int $millimes): string
    {
        $absolu = abs($millimes);
        $decimales = rtrim(\sprintf('%03d', $absolu % 1000), '0');

        return ($millimes < 0 ? '−' : '')
            .number_format(intdiv($absolu, 1000), 0, ',', ' ')
            .('' !== $decimales ? ','.$decimales : '');
    }

    /**
     * Une quantité dans une unité donnée : « 3 sacs », « 1 sac », « 12,5 kg ».
     * Seule une unité d'achat s'accorde — un symbole (kg, L) ne prend pas de s.
     */
    public static function avecUnite(int $millimes, string $unite, bool $uniteAchat = true): string
    {
        return self::nombre($millimes).' '.($uniteAchat ? self::accorder($unite, $millimes) : $unite);
    }

    /** « 1 sac », « 20 sacs » ; un mot déjà terminé par s, x ou z ne bouge pas. */
    private static function accorder(string $unite, int $millimes): string
    {
        return abs($millimes) <= 1000 || preg_match('/[sxz]$/iu', $unite) ? $unite : $unite.'s';
    }
}
