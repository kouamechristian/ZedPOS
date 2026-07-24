<?php

namespace App\Entity;

use App\Entity\Trait\HorodatageCreation;
use App\Repository\FamilleProduitRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FamilleProduitRepository::class)]
class FamilleProduit
{
    use HorodatageCreation;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $nom;

    /** Couleur d'affichage sur la grille de caisse (code hexadécimal). */
    #[ORM\Column(length: 7, nullable: true)]
    private ?string $couleur = null;

    /** Ordre d'affichage dans la caisse. */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(options: ['default' => true])]
    private bool $actif = true;

    /** @var Collection<int, Article> */
    #[ORM\OneToMany(mappedBy: 'familleProduit', targetEntity: Article::class)]
    private Collection $articles;

    public function __construct(string $nom)
    {
        $this->nom = $nom;
        $this->articles = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): self
    {
        $this->nom = $nom;

        return $this;
    }

    public function getCouleur(): ?string
    {
        return $this->couleur;
    }

    public function setCouleur(?string $couleur): self
    {
        $this->couleur = $couleur;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): self
    {
        $this->actif = $actif;

        return $this;
    }

    /** @return Collection<int, Article> */
    public function getArticles(): Collection
    {
        return $this->articles;
    }
}
