<?php

namespace App\Entity;

use App\Enum\MotifRetour;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Un produit revenu d'un stand, pour un motif. Quantité en millièmes. */
#[ORM\Entity]
#[ORM\Table(name: 'ligne_retour')]
#[ORM\UniqueConstraint(name: 'uniq_ligne_retour_produit_motif', columns: ['bon_id', 'produit_id', 'motif'])]
class LigneRetour
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BonRetour::class, inversedBy: 'lignes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private BonRetour $bon;

    #[ORM\ManyToOne(targetEntity: Article::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Article $produit;

    #[ORM\Column(type: Types::BIGINT)]
    private int $quantite;

    #[ORM\Column(length: 10, enumType: MotifRetour::class)]
    private MotifRetour $motif;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $commentaire = null;

    public function __construct(BonRetour $bon, Article $produit, int $quantite, MotifRetour $motif, ?string $commentaire = null)
    {
        $this->bon = $bon;
        $this->produit = $produit;
        $this->motif = $motif;
        $this->commentaire = $commentaire;
        $this->modifierQuantite($quantite);
    }

    /** @internal Réservé à {@see BonRetour::definirLignes()}, qui garantit le brouillon. */
    public function modifierQuantite(int $quantite): void
    {
        if ($quantite <= 0) {
            throw new \DomainException('Une ligne de retour porte une quantité positive.');
        }

        $this->quantite = $quantite;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBon(): BonRetour
    {
        return $this->bon;
    }

    public function getProduit(): Article
    {
        return $this->produit;
    }

    public function getQuantite(): int
    {
        return (int) $this->quantite;
    }

    public function getMotif(): MotifRetour
    {
        return $this->motif;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }
}
