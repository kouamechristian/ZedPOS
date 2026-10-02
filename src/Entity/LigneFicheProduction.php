<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un article de la fiche de production : produit, mis en vitrine et repris de
 * la caisse précédente, en millièmes. Produit et mis en vitrine partent de zéro
 * à l'ouverture et ne bougent que par une déclaration ({@see SaisieProduction})
 * ou son annulation ; la reprise est fixée à l'ouverture.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ligne_fiche_production')]
#[ORM\UniqueConstraint(name: 'uniq_ligne_fiche_production_article', columns: ['fiche_id', 'article_id'])]
class LigneFicheProduction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: FicheProduction::class, inversedBy: 'lignes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private FicheProduction $fiche;

    #[ORM\ManyToOne(targetEntity: Article::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Article $article;

    #[ORM\Column(type: Types::BIGINT)]
    private int $qteProduite = 0;

    #[ORM\Column(type: Types::BIGINT)]
    private int $qteVitrine = 0;

    /**
     * Resté en vitrine à la clôture de la caisse précédente, repris ici à
     * l'ouverture. Compte dans « en vitrine » ; n'a pas été produit aujourd'hui.
     */
    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int $qteReprise = 0;

    public function __construct(FicheProduction $fiche, Article $article)
    {
        $this->fiche = $fiche;
        $this->article = $article;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFiche(): FicheProduction
    {
        return $this->fiche;
    }

    public function getArticle(): Article
    {
        return $this->article;
    }

    public function getQteProduite(): int
    {
        return (int) $this->qteProduite;
    }

    /** Mis en vitrine par déclaration sur cette caisse, hors reprise. */
    public function getQteVitrine(): int
    {
        return (int) $this->qteVitrine;
    }

    public function getQteReprise(): int
    {
        return (int) $this->qteReprise;
    }

    /** Fixée une fois, à l'ouverture de la caisse : la reprise ne se déclare pas. */
    public function reprendre(int $quantite): void
    {
        if ($quantite < 0) {
            throw new \DomainException(\sprintf('Reprise négative pour « %s ».', $this->article->getNom()));
        }
        $this->qteReprise = $quantite;
    }

    /**
     * Ajoute (ou retire, à l'annulation) une quantité produite.
     *
     * @throws \DomainException la fiche passerait sous zéro
     */
    public function ajouterProduction(int $quantite): void
    {
        $this->qteProduite = $this->ajouter($this->getQteProduite(), $quantite);
    }

    /** @throws \DomainException la fiche passerait sous zéro */
    public function ajouterVitrine(int $quantite): void
    {
        $this->qteVitrine = $this->ajouter($this->getQteVitrine(), $quantite);
    }

    private function ajouter(int $actuel, int $quantite): int
    {
        if ($actuel + $quantite < 0) {
            throw new \DomainException(\sprintf('La fiche de « %s » passerait sous zéro.', $this->article->getNom()));
        }

        return $actuel + $quantite;
    }
}
