<?php

namespace App\Entity;

use App\Entity\Trait\HorodatageCreation;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ligne d'une vente. Immuable : figée à la construction, aucun setter public.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ligne_vente')]
class LigneVente
{
    use HorodatageCreation;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Vente::class, inversedBy: 'lignes')]
    #[ORM\JoinColumn(nullable: false)]
    private Vente $vente;

    #[ORM\ManyToOne(targetEntity: Article::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Article $article;

    /** Quantité vendue, en millièmes d'unité (entier, jamais de float). */
    #[ORM\Column]
    private int $quantite;

    /** Prix unitaire TTC appliqué, en centimes de FCFA. */
    #[ORM\Column]
    private int $prixUnitaire;

    /** Remise sur la ligne, en centimes de FCFA. */
    #[ORM\Column(options: ['default' => 0])]
    private int $remise;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $commentaire;

    public function __construct(
        Vente $vente,
        Article $article,
        int $quantite,
        int $prixUnitaire,
        int $remise = 0,
        ?string $commentaire = null,
    ) {
        $this->vente = $vente;
        $this->article = $article;
        $this->quantite = $quantite;
        $this->prixUnitaire = $prixUnitaire;
        $this->remise = $remise;
        $this->commentaire = $commentaire;
        $this->createdAt = new \DateTimeImmutable();
        $vente->ajouterLigne($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVente(): Vente
    {
        return $this->vente;
    }

    public function getArticle(): Article
    {
        return $this->article;
    }

    public function getQuantite(): int
    {
        return $this->quantite;
    }

    public function getPrixUnitaire(): int
    {
        return $this->prixUnitaire;
    }

    public function getRemise(): int
    {
        return $this->remise;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    /**
     * Montant TTC de la ligne (quantité en millièmes × prix unitaire, moins la
     * remise), en centimes de FCFA. Calcul entier, arrondi au centime le plus
     * proche ; aucun float manipulé.
     */
    public function getMontantTtc(): int
    {
        return intdiv($this->quantite * $this->prixUnitaire + 500, 1000) - $this->remise;
    }
}
