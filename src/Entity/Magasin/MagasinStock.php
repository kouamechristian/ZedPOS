<?php

namespace App\Entity\Magasin;

use App\Repository\Magasin\MagasinStockRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Stock d'un produit dans un emplacement du magasin, en millièmes d'unité de
 * stock.
 *
 * **En lecture seule** pour tout le reste de l'application : la ligne n'est
 * écrite que par {@see \App\Service\Magasin\MagasinStockService}, en SQL, sous
 * verrou, dans la même transaction que le mouvement qui la justifie. Invariant :
 * quantité = somme des mouvements du couple (`magasin:stock:verifier`).
 */
#[ORM\Entity(repositoryClass: MagasinStockRepository::class, readOnly: true)]
#[ORM\Table(name: 'magasin_stock')]
#[ORM\UniqueConstraint(name: 'uniq_magasin_stock_couple', columns: ['produit_id', 'emplacement_id'])]
class MagasinStock
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MagasinProduit::class)]
    #[ORM\JoinColumn(nullable: false)]
    private MagasinProduit $produit;

    #[ORM\ManyToOne(targetEntity: MagasinEmplacement::class)]
    #[ORM\JoinColumn(nullable: false)]
    private MagasinEmplacement $emplacement;

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

    public function getProduit(): MagasinProduit
    {
        return $this->produit;
    }

    public function getEmplacement(): MagasinEmplacement
    {
        return $this->emplacement;
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
