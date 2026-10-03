<?php

namespace App\Entity\Magasin;

use App\Entity\Utilisateur;
use App\Enum\Magasin\TypeMouvementMagasin;
use App\Repository\Magasin\MagasinMouvementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un mouvement du stock du magasin. **Immuable** : aucun setter, et une erreur
 * se corrige par un mouvement de type ANNULATION qui la contre-passe — le
 * premier reste, l'historique garde les deux.
 *
 * Écrit uniquement par {@see \App\Service\Magasin\MagasinStockService}.
 * Quantité signée, en millièmes d'unité de stock (+ entrée, − sortie).
 */
#[ORM\Entity(repositoryClass: MagasinMouvementRepository::class, readOnly: true)]
#[ORM\Table(name: 'magasin_mouvement')]
#[ORM\Index(name: 'idx_magasin_mouvement_date', columns: ['created_at'])]
#[ORM\Index(name: 'idx_magasin_mouvement_document', columns: ['document_type', 'document_id'])]
class MagasinMouvement
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

    #[ORM\Column(length: 30, enumType: TypeMouvementMagasin::class)]
    private TypeMouvementMagasin $type;

    #[ORM\Column(type: Types::BIGINT)]
    private int $quantite;

    /** « reception », « sortie », « inventaire » : avec documentId, de quoi retrouver le bon. */
    #[ORM\Column(length: 30)]
    private string $documentType;

    #[ORM\Column]
    private int $documentId;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motif;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Utilisateur $auteur;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        MagasinProduit $produit,
        MagasinEmplacement $emplacement,
        TypeMouvementMagasin $type,
        int $quantite,
        string $documentType,
        int $documentId,
        ?string $motif,
        ?Utilisateur $auteur,
    ) {
        $this->produit = $produit;
        $this->emplacement = $emplacement;
        $this->type = $type;
        $this->quantite = $quantite;
        $this->documentType = $documentType;
        $this->documentId = $documentId;
        $this->motif = $motif;
        $this->auteur = $auteur;
        $this->createdAt = new \DateTimeImmutable();
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

    public function getType(): TypeMouvementMagasin
    {
        return $this->type;
    }

    public function getQuantite(): int
    {
        return (int) $this->quantite;
    }

    public function getDocumentType(): string
    {
        return $this->documentType;
    }

    public function getDocumentId(): int
    {
        return $this->documentId;
    }

    public function getMotif(): ?string
    {
        return $this->motif;
    }

    public function getAuteur(): ?Utilisateur
    {
        return $this->auteur;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
