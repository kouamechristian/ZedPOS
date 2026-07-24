<?php

namespace App\Entity;

use App\Entity\Trait\HorodatageCreation;
use App\Repository\MatierePremiereRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MatierePremiereRepository::class)]
class MatierePremiere
{
    use HorodatageCreation;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    private string $nom;

    /** Unité de gestion du stock (ex. « kg », « L », « pièce »). */
    #[ORM\Column(length: 20)]
    private string $uniteStock;

    /** Stock courant, exprimé en millièmes d'unité (entier, jamais de float). */
    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int $stockActuel = 0;

    /** Seuil d'alerte, exprimé en millièmes d'unité. */
    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int $stockMini = 0;

    /** Coût moyen pondéré par unité, en centimes de FCFA. */
    #[ORM\Column(options: ['default' => 0])]
    private int $coutMoyenPondere = 0;

    public function __construct(string $nom, string $uniteStock)
    {
        $this->nom = $nom;
        $this->uniteStock = $uniteStock;
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

    public function getUniteStock(): string
    {
        return $this->uniteStock;
    }

    public function setUniteStock(string $uniteStock): self
    {
        $this->uniteStock = $uniteStock;

        return $this;
    }

    public function getStockActuel(): int
    {
        return $this->stockActuel;
    }

    public function setStockActuel(int $stockActuel): self
    {
        $this->stockActuel = $stockActuel;

        return $this;
    }

    public function getStockMini(): int
    {
        return $this->stockMini;
    }

    public function setStockMini(int $stockMini): self
    {
        $this->stockMini = $stockMini;

        return $this;
    }

    public function getCoutMoyenPondere(): int
    {
        return $this->coutMoyenPondere;
    }

    public function setCoutMoyenPondere(int $coutMoyenPondere): self
    {
        $this->coutMoyenPondere = $coutMoyenPondere;

        return $this;
    }
}
