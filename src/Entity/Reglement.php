<?php

namespace App\Entity;

use App\Entity\Trait\HorodatageCreation;
use App\Enum\ModeReglement;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un règlement encaissé pour une vente (une vente peut en avoir plusieurs).
 */
#[ORM\Entity]
#[ORM\Table(name: 'reglement')]
class Reglement
{
    use HorodatageCreation;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Vente::class, inversedBy: 'reglements')]
    #[ORM\JoinColumn(nullable: false)]
    private Vente $vente;

    #[ORM\Column(length: 20, enumType: ModeReglement::class)]
    private ModeReglement $mode;

    /** Montant réglé, en centimes de FCFA. */
    #[ORM\Column]
    private int $montant;

    /** Référence externe du paiement (ex. transaction mobile money). */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $reference;

    public function __construct(Vente $vente, ModeReglement $mode, int $montant, ?string $reference = null)
    {
        $this->vente = $vente;
        $this->mode = $mode;
        $this->montant = $montant;
        $this->reference = $reference;
        $this->createdAt = new \DateTimeImmutable();
        $vente->ajouterReglement($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVente(): Vente
    {
        return $this->vente;
    }

    public function getMode(): ModeReglement
    {
        return $this->mode;
    }

    public function getMontant(): int
    {
        return $this->montant;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }
}
