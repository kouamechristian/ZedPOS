<?php

namespace App\Entity;

use App\Entity\Trait\HorodatageCreation;
use App\Enum\TypeMouvementStock;
use App\Repository\MouvementStockRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mouvement de stock d'un produit (article **ou** matière) dans un emplacement.
 *
 * **Créé uniquement par `StockManager`**, qui met à jour `StockCourant` dans la
 * même transaction : un mouvement écrit ailleurs ferait diverger le stock de
 * son historique sans que rien ne le signale.
 *
 * Quantité signée (+ entrée, − sortie) en millièmes d'unité. Le document à
 * l'origine du mouvement est référencé de façon polymorphe par
 * {@see $documentType} / {@see $documentId} (« vente » + id, « perte » + id…).
 * L'historique est immuable : aucun setter, hormis le rattachement tardif du
 * document quand celui-ci n'a pas encore d'id au moment du mouvement.
 */
#[ORM\Entity(repositoryClass: MouvementStockRepository::class)]
#[ORM\Table(name: 'mouvement_stock')]
#[ORM\Index(name: 'idx_mouvement_stock_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_mouvement_stock_document', columns: ['document_type', 'document_id'])]
class MouvementStock
{
    use HorodatageCreation;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Emplacement::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Emplacement $emplacement;

    #[ORM\ManyToOne(targetEntity: MatierePremiere::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?MatierePremiere $matierePremiere = null;

    #[ORM\ManyToOne(targetEntity: Article::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Article $article = null;

    #[ORM\Column(length: 30, enumType: TypeMouvementStock::class)]
    private TypeMouvementStock $type;

    /** Quantité signée, en millièmes d'unité (+ entrée, − sortie). */
    #[ORM\Column(type: Types::BIGINT)]
    private int $quantite;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motif;

    /** Type du document d'origine (ex. « vente », « perte », « inventaire »). */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $documentType;

    #[ORM\Column(nullable: true)]
    private ?int $documentId;

    /** Auteur du geste. Nul en console, dans les fixtures et pour la reprise de l'existant. */
    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Utilisateur $utilisateur;

    public function __construct(
        Article|MatierePremiere $produit,
        Emplacement $emplacement,
        TypeMouvementStock $type,
        int $quantite,
        ?string $motif = null,
        ?string $documentType = null,
        ?int $documentId = null,
        ?Utilisateur $utilisateur = null,
    ) {
        if ($produit instanceof Article) {
            $this->article = $produit;
        } else {
            $this->matierePremiere = $produit;
        }

        $this->emplacement = $emplacement;
        $this->type = $type;
        $this->quantite = $quantite;
        $this->motif = $motif;
        $this->documentType = $documentType;
        $this->documentId = $documentId;
        $this->utilisateur = $utilisateur;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmplacement(): Emplacement
    {
        return $this->emplacement;
    }

    public function getProduit(): Article|MatierePremiere
    {
        return $this->article ?? $this->matierePremiere
            ?? throw new \LogicException('Mouvement de stock sans produit.');
    }

    public function getMatierePremiere(): ?MatierePremiere
    {
        return $this->matierePremiere;
    }

    public function getArticle(): ?Article
    {
        return $this->article;
    }

    public function getType(): TypeMouvementStock
    {
        return $this->type;
    }

    public function getQuantite(): int
    {
        return (int) $this->quantite;
    }

    public function getMotif(): ?string
    {
        return $this->motif;
    }

    public function getDocumentType(): ?string
    {
        return $this->documentType;
    }

    public function getDocumentId(): ?int
    {
        return $this->documentId;
    }

    public function getUtilisateur(): ?Utilisateur
    {
        return $this->utilisateur;
    }

    /**
     * Rattache le document une fois qu'il existe. Une perte, par exemple, n'a
     * d'id qu'après son INSERT, alors que son mouvement doit être accepté — ou
     * refusé pour stock insuffisant — **avant** qu'elle ne soit enregistrée.
     *
     * Une seule fois : un mouvement ne change pas de justificatif.
     */
    public function rattacherDocument(string $documentType, int $documentId): self
    {
        if (null !== $this->documentId) {
            throw new \LogicException('Ce mouvement de stock est déjà rattaché à un document.');
        }

        $this->documentType = $documentType;
        $this->documentId = $documentId;

        return $this;
    }
}
