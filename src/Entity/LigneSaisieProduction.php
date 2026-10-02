<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un article déclaré en production ou en vitrine. Quantité en millièmes, comme
 * partout ailleurs ; l'écran ne saisit que des unités entières.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ligne_saisie_production')]
#[ORM\UniqueConstraint(name: 'uniq_ligne_saisie_production_article', columns: ['saisie_id', 'article_id'])]
class LigneSaisieProduction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SaisieProduction::class, inversedBy: 'lignes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SaisieProduction $saisie;

    #[ORM\ManyToOne(targetEntity: Article::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Article $article;

    /** En millièmes d'unité. */
    #[ORM\Column(type: Types::BIGINT)]
    private int $quantite;

    public function __construct(SaisieProduction $saisie, Article $article, int $quantite)
    {
        $this->saisie = $saisie;
        $this->article = $article;
        $this->quantite = $quantite;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSaisie(): SaisieProduction
    {
        return $this->saisie;
    }

    public function getArticle(): Article
    {
        return $this->article;
    }

    public function getQuantite(): int
    {
        return (int) $this->quantite;
    }
}
