<?php

namespace App\Repository;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;

/**
 * Une page de résultats et de quoi naviguer autour.
 *
 * Objet unique pour tout le projet : avant lui, `VenteRepository` et
 * `JournalAuditRepository` renvoyaient chacun un tableau associatif de forme
 * voisine mais pas identique, et deux gabarits recopiaient le même contrôle de
 * navigation. Ajouter une neuvième liste paginée aurait fait une neuvième copie.
 *
 * @template T
 */
final readonly class Pagination
{
    /**
     * Taille par défaut d'une page de back-office. Assez pour balayer une liste
     * sans faire défiler indéfiniment, assez peu pour que la requête reste
     * instantanée sur le poste de la boulangerie.
     */
    public const PAR_DEFAUT = 25;

    /** Nombre total de pages, jamais inférieur à 1 (une liste vide a une page). */
    public int $pages;

    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $parPage = self::PAR_DEFAUT,
    ) {
        $this->pages = max(1, (int) ceil($total / max(1, $parPage)));
    }

    /**
     * Pagine une requête.
     *
     * `$fetchJoinCollection` doit passer à **true** dès que la requête joint une
     * collection (`->join('v.lignes', …)`) : sans lui, Doctrine compterait les
     * lignes du produit cartésien et non les entités, et la dernière page serait
     * fausse. À laisser à false quand seules des relations ToOne sont jointes —
     * c'est nettement plus rapide.
     *
     * @return self<mixed>
     */
    public static function depuis(
        QueryBuilder $qb,
        int $page,
        int $parPage = self::PAR_DEFAUT,
        bool $fetchJoinCollection = false,
    ): self {
        $page = max(1, $page);

        $qb->setFirstResult(($page - 1) * $parPage)->setMaxResults($parPage);

        $paginateur = new Paginator($qb->getQuery(), $fetchJoinCollection);
        $total = \count($paginateur);

        // Une page demandée au-delà de la fin (lien périmé, suppression entre
        // deux clics) rend une liste vide plutôt qu'une erreur : on n'immobilise
        // pas un écran de gestion pour un numéro de page.
        //
        // `array_values` garantit une liste à index continus : selon le mode de
        // comptage, le paginateur peut rendre des clés non séquentielles, ce qui
        // ferait mentir le type `list<T>` annoncé.
        return new self(array_values(iterator_to_array($paginateur)), $total, $page, $parPage);
    }

    /**
     * Pagine une liste déjà chargée en mémoire.
     *
     * Réservé aux ensembles **petits et déjà bornés** — une synthèse mensuelle,
     * une agrégation calculée en PHP. Pour une table, c'est la base qui doit
     * découper : charger 10 000 lignes pour n'en afficher 25 est un contresens.
     *
     * @param list<mixed> $items
     *
     * @return self<mixed>
     */
    public static function surTableau(array $items, int $page, int $parPage = self::PAR_DEFAUT): self
    {
        $page = max(1, $page);

        return new self(
            array_values(\array_slice($items, ($page - 1) * $parPage, $parPage)),
            \count($items),
            $page,
            $parPage,
        );
    }

    public function estVide(): bool
    {
        return [] === $this->items;
    }

    /** Y a-t-il de quoi afficher un contrôle de navigation ? */
    public function estPaginee(): bool
    {
        return $this->pages > 1;
    }

    public function aPrecedente(): bool
    {
        return $this->page > 1;
    }

    public function aSuivante(): bool
    {
        return $this->page < $this->pages;
    }

    /** Rang du premier élément affiché, à partir de 1 (0 si la page est vide). */
    public function debut(): int
    {
        return $this->estVide() ? 0 : ($this->page - 1) * $this->parPage + 1;
    }

    /** Rang du dernier élément affiché. */
    public function fin(): int
    {
        return $this->estVide() ? 0 : $this->debut() + \count($this->items) - 1;
    }

    /**
     * Numéros de page à proposer autour de la page courante.
     *
     * Une année d'audit fait des centaines de pages : les lister toutes créerait
     * une barre plus longue que le tableau. On garde une fenêtre glissante ; les
     * extrémités restent joignables par « Première » et « Dernière ».
     *
     * @return list<int>
     */
    public function fenetre(int $rayon = 2): array
    {
        $debut = max(1, $this->page - $rayon);
        $fin = min($this->pages, $this->page + $rayon);

        // Fenêtre de largeur constante, même en début et en fin de liste : la
        // barre ne change pas de taille quand on navigue.
        $largeur = 2 * $rayon + 1;
        if ($fin - $debut + 1 < $largeur) {
            if (1 === $debut) {
                $fin = min($this->pages, $debut + $largeur - 1);
            } else {
                $debut = max(1, $fin - $largeur + 1);
            }
        }

        return range($debut, $fin);
    }
}
