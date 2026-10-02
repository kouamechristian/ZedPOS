<?php

namespace App\Enum;

/**
 * Atelier de fabrication d'un article, porté par sa famille.
 *
 * Seuls les articles d'une famille rattachée à un atelier se déclarent en
 * production et en vitrine : une boisson revendue telle quelle n'a pas de fournée.
 * `AUTRE` accueille ce qui se prépare sur place sans relever des trois premiers
 * (jus pressés, salades…).
 */
enum Atelier: string
{
    case BOULANGERIE = 'BOULANGERIE';
    case PATISSERIE = 'PATISSERIE';
    case FAST_FOOD = 'FAST_FOOD';
    case AUTRE = 'AUTRE';

    public function libelle(): string
    {
        return match ($this) {
            self::BOULANGERIE => 'Boulangerie',
            self::PATISSERIE => 'Pâtisserie',
            self::FAST_FOOD => 'Fast-food',
            self::AUTRE => 'Autres',
        };
    }

    /** Titre de l'écran de déclaration : on enfourne le pain, on prépare le reste. */
    public function titreDeclaration(): string
    {
        return match ($this) {
            self::BOULANGERIE, self::PATISSERIE => 'Fournée',
            self::FAST_FOOD, self::AUTRE => 'Production',
        };
    }

    public function aideDeclaration(): string
    {
        return match ($this) {
            self::BOULANGERIE, self::PATISSERIE => 'Ce qui vient de sortir du four',
            self::FAST_FOOD, self::AUTRE => 'Ce qui vient d\'être préparé',
        };
    }

    /** Segment d'URL de l'écran de production (`/atelier/production/fast-food`). */
    public function slug(): string
    {
        return str_replace('_', '-', strtolower($this->value));
    }

    public static function depuisSlug(string $slug): ?self
    {
        foreach (self::cases() as $atelier) {
            if ($atelier->slug() === $slug) {
                return $atelier;
            }
        }

        return null;
    }
}
