<?php

namespace App\Entity;

use App\Entity\Trait\HorodatageCreation;
use App\Repository\VendeurRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Revendeur ambulant à qui la boutique confie de la marchandise.
 *
 * **Une entité métier, pas un compte** : le vendeur n'a aucun outil, ne se
 * connecte jamais et ne saisit rien. C'est la gérante qui le désigne sur un bon
 * de dotation. Il ne se supprime pas — il a signé des bons — il se désactive.
 */
#[ORM\Entity(repositoryClass: VendeurRepository::class)]
#[ORM\Table(name: 'vendeur')]
class Vendeur
{
    use HorodatageCreation;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $nom;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $telephone = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $actif = true;

    public function __construct(string $nom = '')
    {
        $this->nom = trim($nom);
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
        $this->nom = trim($nom);

        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): self
    {
        $telephone = null !== $telephone ? trim($telephone) : null;
        $this->telephone = '' !== $telephone ? $telephone : null;

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
}
