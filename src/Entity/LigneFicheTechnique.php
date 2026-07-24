<?php

namespace App\Entity;

use App\Entity\Trait\HorodatageCreation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ligne d'une fiche technique : une matière première consommée par l'article,
 * avec sa quantité et le pourcentage de perte attendu lors de la production.
 */
#[ORM\Entity]
class LigneFicheTechnique
{
    use HorodatageCreation;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: FicheTechnique::class, inversedBy: 'lignes')]
    #[ORM\JoinColumn(nullable: false)]
    private FicheTechnique $ficheTechnique;

    #[ORM\ManyToOne(targetEntity: MatierePremiere::class)]
    #[ORM\JoinColumn(nullable: false)]
    private MatierePremiere $matierePremiere;

    /** Quantité consommée, en millièmes d'unité de stock. */
    #[ORM\Column(type: Types::BIGINT)]
    private int $quantite;

    /** Pourcentage de perte, en points de base : 500 = 5,00 %. */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $pourcentagePerte = 0;

    public function __construct(FicheTechnique $ficheTechnique, MatierePremiere $matierePremiere, int $quantite, int $pourcentagePerte = 0)
    {
        $this->ficheTechnique = $ficheTechnique;
        $this->matierePremiere = $matierePremiere;
        $this->quantite = $quantite;
        $this->pourcentagePerte = $pourcentagePerte;
        $this->createdAt = new \DateTimeImmutable();
        $ficheTechnique->ajouterLigne($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFicheTechnique(): FicheTechnique
    {
        return $this->ficheTechnique;
    }

    public function getMatierePremiere(): MatierePremiere
    {
        return $this->matierePremiere;
    }

    public function getQuantite(): int
    {
        return $this->quantite;
    }

    public function setQuantite(int $quantite): self
    {
        $this->quantite = $quantite;

        return $this;
    }

    public function getPourcentagePerte(): int
    {
        return $this->pourcentagePerte;
    }

    public function setPourcentagePerte(int $pourcentagePerte): self
    {
        $this->pourcentagePerte = $pourcentagePerte;

        return $this;
    }
}
