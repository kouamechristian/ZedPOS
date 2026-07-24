<?php

namespace App\Entity;

use App\Entity\Trait\HorodatageCreation;
use App\Repository\FicheTechniqueRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Recette de production d'un article : la liste des matières premières consommées.
 */
#[ORM\Entity(repositoryClass: FicheTechniqueRepository::class)]
class FicheTechnique
{
    use HorodatageCreation;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'ficheTechnique', targetEntity: Article::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Article $article;

    /** @var Collection<int, LigneFicheTechnique> */
    #[ORM\OneToMany(mappedBy: 'ficheTechnique', targetEntity: LigneFicheTechnique::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $lignes;

    public function __construct(Article $article)
    {
        $this->article = $article;
        $this->lignes = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $article->setFicheTechnique($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getArticle(): Article
    {
        return $this->article;
    }

    /** @return Collection<int, LigneFicheTechnique> */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    public function ajouterLigne(LigneFicheTechnique $ligne): self
    {
        if (!$this->lignes->contains($ligne)) {
            $this->lignes->add($ligne);
        }

        return $this;
    }

    public function retirerLigne(LigneFicheTechnique $ligne): self
    {
        $this->lignes->removeElement($ligne);

        return $this;
    }
}
