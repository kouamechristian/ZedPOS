<?php

namespace App\Repository;

use Doctrine\ORM\QueryBuilder;

/**
 * Recherche texte des tableaux du back-office — un seul mécanisme pour tous.
 *
 * Pendant du {@see Pagination} : les listes se filtrent toutes de la même façon,
 * plutôt que chaque repository ne réécrive son `LIKE`. Neuf tableaux à équiper,
 * c'était neuf occasions d'oublier l'échappement ou les parenthèses.
 *
 * Le terme est cherché **en sous-chaîne** sur les champs donnés, reliés par `OR` :
 * la caissière ou le gérant tape trois lettres du nom, pas un préfixe exact.
 */
final class Recherche
{
    private function __construct()
    {
    }

    /**
     * Terme utilisable, ou `null` si la saisie est vide.
     *
     * Un champ laissé vide ou rempli d'espaces ne filtre rien — il ne doit pas
     * vider le tableau.
     */
    public static function normaliser(?string $terme): ?string
    {
        $terme = trim((string) $terme);

        return '' === $terme ? null : $terme;
    }

    /**
     * Restreint la requête aux lignes dont l'un des champs contient le terme.
     *
     * Sans terme, la requête ressort **inchangée** : l'appelant n'a pas de garde
     * à écrire de son côté.
     *
     * @param string ...$champs alias DQL complets (`u.nom`, `a.email`…)
     */
    public static function appliquer(QueryBuilder $qb, ?string $terme, string ...$champs): void
    {
        $terme = self::normaliser($terme);

        if (null === $terme || [] === $champs) {
            return;
        }

        // `%` et `_` sont des jokers en SQL : chercher « 50% » ou « x_y » sans les
        // échapper ramènerait des lignes sans rapport, voire la table entière. La
        // barre oblique est échappée la première, sinon elle doublerait celles
        // ajoutées juste après.
        $motif = '%'.addcslashes($terme, '\\%_').'%';

        // Les conditions sont regroupées entre parenthèses : sans elles, le `OR`
        // se lierait au reste de la requête et une recherche ferait ressortir des
        // lignes exclues par les autres filtres (mois, statut, période…).
        $conditions = array_map(static fn (string $champ): string => $champ.' LIKE :recherche', $champs);

        $qb->andWhere('('.implode(' OR ', $conditions).')')
            ->setParameter('recherche', $motif);
    }
}
