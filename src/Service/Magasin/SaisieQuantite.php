<?php

namespace App\Service\Magasin;

/**
 * Lecture d'une quantité tapée à l'écran (« 18 », « 2,5 », « 1 000 ») en
 * millièmes, **sans flottant** : partie entière et décimales sont recomposées en
 * entier. Trois décimales au plus. Partagée par les écrans de saisie du module.
 */
final class SaisieQuantite
{
    /** Au-delà, c'est une touche restée enfoncée, pas une livraison. */
    private const UNITES_MAX = 999999;

    /**
     * @return ?int millièmes ; nul pour une case vide
     *
     * @throws \DomainException saisie illisible
     */
    public static function millimes(mixed $saisie, string $contexte): ?int
    {
        $texte = str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim(\is_scalar($saisie) ? (string) $saisie : ''));
        if ('' === $texte) {
            return null;
        }
        if (!preg_match('/^(\d+)(?:[.,](\d{1,3}))?$/', $texte, $m) || (int) $m[1] > self::UNITES_MAX) {
            throw new \DomainException(\sprintf('%s : quantité illisible « %s » (chiffres seulement, 2,5 pour deux et demi).', $contexte, $texte));
        }

        return (int) $m[1] * 1000 + (int) str_pad($m[2] ?? '', 3, '0');
    }

    /**
     * Prix tapé en FCFA entiers (« 25 000 ») → centimes ; case vide : nul.
     *
     * @throws \DomainException saisie illisible
     */
    public static function prix(mixed $saisie, string $contexte): ?int
    {
        $texte = str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim(\is_scalar($saisie) ? (string) $saisie : ''));
        if ('' === $texte) {
            return null;
        }
        if (!ctype_digit($texte) || \strlen($texte) > 9) {
            throw new \DomainException(\sprintf('%s : prix illisible « %s » (FCFA entiers).', $contexte, $texte));
        }

        return (int) $texte * 100;
    }

    /** Millièmes → « 18 », « 2,5 » : la valeur d'un champ réaffiché. */
    public static function texte(?int $millimes): string
    {
        if (null === $millimes) {
            return '';
        }
        $decimales = rtrim(\sprintf('%03d', abs($millimes) % 1000), '0');

        return ($millimes < 0 ? '-' : '').intdiv(abs($millimes), 1000).('' !== $decimales ? ','.$decimales : '');
    }
}
