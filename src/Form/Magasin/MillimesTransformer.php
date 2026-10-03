<?php

namespace App\Form\Magasin;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * Quantité saisie en unités (« 50 », « 2,5 », « 1 000 ») ↔ millièmes d'unité,
 * **sans flottant** : la saisie est découpée en partie entière et décimales, et
 * recomposée en entier. Trois décimales au plus ; une case vide reste vide.
 *
 * @implements DataTransformerInterface<int, string>
 */
final class MillimesTransformer implements DataTransformerInterface
{
    public function transform(mixed $value): string
    {
        if (null === $value) {
            return '';
        }

        $millimes = (int) $value;
        $absolu = abs($millimes);
        $decimales = rtrim(\sprintf('%03d', $absolu % 1000), '0');

        return ($millimes < 0 ? '-' : '').intdiv($absolu, 1000).('' !== $decimales ? ','.$decimales : '');
    }

    public function reverseTransform(mixed $value): ?int
    {
        $texte = str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim((string) $value));
        if ('' === $texte) {
            return null;
        }

        if (!preg_match('/^(\d{1,9})(?:[.,](\d{1,3}))?$/', $texte, $m)) {
            $erreur = new TransformationFailedException(\sprintf('Quantité illisible : « %s ».', $texte));
            $erreur->setInvalidMessage('Quantité illisible : chiffres seulement, trois décimales au plus (2,5).');

            throw $erreur;
        }

        return (int) $m[1] * 1000 + (int) str_pad($m[2] ?? '', 3, '0');
    }
}
