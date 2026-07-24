<?php

namespace App\Entity;

use App\Entity\Trait\HorodatageCreation;
use App\Enum\MotifPerte;
use App\Repository\PerteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une perte constatée sur une matière première ou un article.
 */
#[ORM\Entity(repositoryClass: PerteRepository::class)]
#[ORM\Table(name: 'perte')]
#[ORM\Index(name: 'idx_perte_created_at', columns: ['created_at'])]
class Perte
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

    #[ORM\Column(length: 30, enumType: MotifPerte::class)]
    private MotifPerte $motif;

    /** Quantité perdue, en millièmes d'unité. */
    #[ORM\Column(type: Types::BIGINT)]
    private int $quantite;

    /** Valorisation de la perte, en centimes de FCFA. */
    #[ORM\Column(options: ['default' => 0])]
    private int $valorisation = 0;

    public function __construct(MotifPerte $motif, int $quantite, int $valorisation = 0)
    {
        $this->motif = $motif;
        $this->quantite = $quantite;
        $this->valorisation = $valorisation;
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

    public function getMotif(): MotifPerte
    {
        return $this->motif;
    }

    public function getQuantite(): int
    {
        return $this->quantite;
    }

    public function getValorisation(): int
    {
        return $this->valorisation;
    }

    public function setValorisation(int $valorisation): self
    {
        $this->valorisation = $valorisation;

        return $this;
    }
}
