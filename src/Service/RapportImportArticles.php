<?php

namespace App\Service;

/**
 * Ce qu'un import d'articles a produit, ligne par ligne.
 *
 * Un import réussi n'est pas un import muet : sur soixante lignes tapées dans un
 * tableur, il y en a toujours une avec une faute de frappe, et un simple
 * « 59 articles créés » laisse chercher laquelle. Chaque ligne écartée sort donc
 * avec **son numéro, son contenu et la raison** — c'est ce qui permet de corriger
 * le fichier et de le repasser, plutôt que de comparer deux listes à la main.
 */
final readonly class RapportImportArticles
{
    /**
     * @param list<array{nom: string, prix: int}>                    $creees   prix en centimes
     * @param list<string>                                           $doublons noms déjà au catalogue
     * @param list<array{ligne: int, contenu: string, raison: string}> $rejets
     * @param bool                                                   $prixIgnores le fichier portait des
     *                                                                            prix, l'auteur n'avait
     *                                                                            pas le droit de les fixer
     */
    public function __construct(
        public array $creees = [],
        public array $doublons = [],
        public array $rejets = [],
        public bool $prixIgnores = false,
    ) {
    }

    public function nombreCreees(): int
    {
        return \count($this->creees);
    }

    /**
     * Articles créés **sans prix**, donc inactifs.
     *
     * @return list<string>
     */
    public function sansPrix(): array
    {
        return array_values(array_map(
            static fn (array $ligne): string => $ligne['nom'],
            array_filter($this->creees, static fn (array $ligne): bool => 0 === $ligne['prix']),
        ));
    }

    public function estVide(): bool
    {
        return [] === $this->creees && [] === $this->doublons && [] === $this->rejets;
    }

    /** Forme sérialisable, pour survivre au aller-retour par la session. */
    public function enTableau(): array
    {
        return [
            'creees' => $this->creees,
            'doublons' => $this->doublons,
            'rejets' => $this->rejets,
            'prixIgnores' => $this->prixIgnores,
        ];
    }

    public static function depuisTableau(array $donnees): self
    {
        return new self(
            creees: $donnees['creees'] ?? [],
            doublons: $donnees['doublons'] ?? [],
            rejets: $donnees['rejets'] ?? [],
            prixIgnores: (bool) ($donnees['prixIgnores'] ?? false),
        );
    }
}
