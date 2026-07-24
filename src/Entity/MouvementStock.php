<?php

namespace App\Entity;

use App\Entity\Trait\HorodatageCreation;
use App\Enum\TypeMouvementStock;
use App\Repository\MouvementStockRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mouvement de stock d'une matière première ou d'un article.
 *
 * La quantité est signée (positive pour une entrée, négative pour une sortie),
 * exprimée en millièmes d'unité. La source du mouvement est référencée de façon
 * polymorphe via {@see $sourceType} / {@see $sourceId} (ex. « vente » + id).
 */
#[ORM\Entity(repositoryClass: MouvementStockRepository::class)]
#[ORM\Table(name: 'mouvement_stock')]
#[ORM\Index(name: 'idx_mouvement_stock_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_mouvement_stock_source', columns: ['source_type', 'source_id'])]
class MouvementStock
{
    use HorodatageCreation;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MatierePremiere::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?MatierePremiere $matierePremiere = null;

    #[ORM\ManyToOne(targetEntity: Article::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Article $article = null;

    #[ORM\Column(length: 30, enumType: TypeMouvementStock::class)]
    private TypeMouvementStock $type;

    /** Quantité signée, en millièmes d'unité (+ entrée, − sortie). */
    #[ORM\Column(type: Types::BIGINT)]
    private int $quantite;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motif = null;

    /** Type de la source polymorphe (ex. « vente », « perte », « inventaire »). */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $sourceType = null;

    /** Identifiant de la source polymorphe. */
    #[ORM\Column(nullable: true)]
    private ?int $sourceId = null;

    public function __construct(TypeMouvementStock $type, int $quantite)
    {
        $this->type = $type;
        $this->quantite = $quantite;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMatierePremiere(): ?MatierePremiere
    {
        return $this->matierePremiere;
    }

    public function setMatierePremiere(?MatierePremiere $matierePremiere): self
    {
        $this->matierePremiere = $matierePremiere;

        return $this;
    }

    public function getArticle(): ?Article
    {
        return $this->article;
    }

    public function setArticle(?Article $article): self
    {
        $this->article = $article;

        return $this;
    }

    public function getType(): TypeMouvementStock
    {
        return $this->type;
    }

    public function getQuantite(): int
    {
        return $this->quantite;
    }

    public function getMotif(): ?string
    {
        return $this->motif;
    }

    public function setMotif(?string $motif): self
    {
        $this->motif = $motif;

        return $this;
    }

    public function getSourceType(): ?string
    {
        return $this->sourceType;
    }

    public function getSourceId(): ?int
    {
        return $this->sourceId;
    }

    /**
     * Définit la source polymorphe du mouvement (ex. « vente », 42).
     */
    public function setSource(?string $sourceType, ?int $sourceId): self
    {
        $this->sourceType = $sourceType;
        $this->sourceId = $sourceId;

        return $this;
    }
}
