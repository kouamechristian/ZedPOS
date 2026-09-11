<?php

namespace App\Entity;

use App\Repository\StockCourantRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Quantité présente d'un produit (article **ou** matière) dans un emplacement.
 *
 * **Jamais écrit en dehors de `StockManager`**, qui l'écrit en SQL sous verrou
 * de ligne, dans la même transaction que le mouvement qui le justifie. D'où
 * l'entité en lecture seule, sans constructeur public ni setter : Doctrine
 * l'hydrate, personne ne la fabrique. Invariant : pour chaque ligne, la somme
 * des `MouvementStock` du couple emplacement × produit vaut `quantite` —
 * `StockManager::recalculerStock()` le rétablit s'il a été rompu.
 *
 * Deux contraintes d'unicité et non une : un couple porte soit un article, soit
 * une matière, et l'autre colonne reste NULL — ce qui ne se heurte jamais.
 */
#[ORM\Entity(repositoryClass: StockCourantRepository::class, readOnly: true)]
#[ORM\Table(name: 'stock_courant')]
#[ORM\UniqueConstraint(name: 'uniq_stock_courant_article', columns: ['emplacement_id', 'article_id'])]
#[ORM\UniqueConstraint(name: 'uniq_stock_courant_matiere', columns: ['emplacement_id', 'matiere_premiere_id'])]
class StockCourant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Emplacement::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Emplacement $emplacement;

    /** Donnée dérivée des mouvements : elle disparaît avec son produit. */
    #[ORM\ManyToOne(targetEntity: Article::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Article $article = null;

    #[ORM\ManyToOne(targetEntity: MatierePremiere::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?MatierePremiere $matierePremiere = null;

    /** En millièmes d'unité. Négatif seulement après une vente caisse. */
    #[ORM\Column(type: Types::BIGINT)]
    private int $quantite = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $modifieA;

    private function __construct()
    {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmplacement(): Emplacement
    {
        return $this->emplacement;
    }

    public function getProduit(): Article|MatierePremiere
    {
        return $this->article ?? $this->matierePremiere
            ?? throw new \LogicException('Ligne de stock sans produit.');
    }

    public function getQuantite(): int
    {
        return (int) $this->quantite;
    }

    public function getModifieA(): \DateTimeImmutable
    {
        return $this->modifieA;
    }
}
