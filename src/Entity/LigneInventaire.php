<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une ligne de feuille de comptage : un article **ou** une matière première,
 * jamais les deux.
 *
 * Quantités en **millièmes d'unité**, coût unitaire en **centimes de FCFA** —
 * conventions du projet. Aucun flottant ne touche ni l'un ni l'autre.
 *
 * Le libellé et l'unité sont **recopiés** à l'ouverture plutôt que lus dans la
 * relation : une feuille d'inventaire est un document daté, elle doit rester
 * lisible telle qu'elle a été imprimée même si l'article est renommé ensuite.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ligne_inventaire')]
class LigneInventaire
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Inventaire::class, inversedBy: 'lignes')]
    #[ORM\JoinColumn(nullable: false)]
    private Inventaire $inventaire;

    #[ORM\ManyToOne(targetEntity: MatierePremiere::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?MatierePremiere $matierePremiere = null;

    #[ORM\ManyToOne(targetEntity: Article::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Article $article = null;

    #[ORM\Column(length: 180)]
    private string $libelle;

    #[ORM\Column(length: 30)]
    private string $unite;

    /** Stock connu de l'application au moment de l'ouverture, en millièmes. */
    #[ORM\Column(type: Types::BIGINT)]
    private int $quantiteTheorique;

    /** Quantité relevée en rayon, en millièmes. `null` tant qu'elle n'est pas saisie. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $quantiteComptee = null;

    /**
     * Coût unitaire au moment de l'ouverture, en centimes.
     *
     * Figé lui aussi : valoriser l'écart avec le coût d'aujourd'hui donnerait, un
     * mois plus tard, un montant différent pour le même inventaire.
     */
    #[ORM\Column]
    private int $coutUnitaire;

    public function __construct(
        Inventaire $inventaire,
        string $libelle,
        string $unite,
        int $quantiteTheorique,
        int $coutUnitaire = 0,
    ) {
        $this->inventaire = $inventaire;
        $this->libelle = $libelle;
        $this->unite = $unite;
        $this->quantiteTheorique = $quantiteTheorique;
        $this->coutUnitaire = $coutUnitaire;

        $inventaire->ajouterLigne($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInventaire(): Inventaire
    {
        return $this->inventaire;
    }

    public function getMatierePremiere(): ?MatierePremiere
    {
        return $this->matierePremiere;
    }

    public function setMatierePremiere(?MatierePremiere $matierePremiere): self
    {
        $this->matierePremiere = $matierePremiere;

        return $this;
    }

    public function getArticle(): ?Article
    {
        return $this->article;
    }

    public function setArticle(?Article $article): self
    {
        $this->article = $article;

        return $this;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function getUnite(): string
    {
        return $this->unite;
    }

    public function getQuantiteTheorique(): int
    {
        return $this->quantiteTheorique;
    }

    public function getQuantiteComptee(): ?int
    {
        return $this->quantiteComptee;
    }

    /**
     * Saisit — ou efface — la quantité comptée.
     *
     * `null` remet la ligne à « non comptée » : une saisie effacée doit pouvoir
     * l'être vraiment, sinon corriger une erreur de frappe obligerait à écrire un
     * zéro, qui viderait le stock à la validation.
     */
    public function compter(?int $quantiteComptee): self
    {
        $this->inventaire->garantirEnCours();

        if (null !== $quantiteComptee && $quantiteComptee < 0) {
            throw new \DomainException('Une quantité comptée ne peut pas être négative.');
        }

        $this->quantiteComptee = $quantiteComptee;

        return $this;
    }

    public function estComptee(): bool
    {
        return null !== $this->quantiteComptee;
    }

    /** Écart en millièmes : positif s'il y a plus en rayon que dans l'application. */
    public function ecart(): int
    {
        return $this->estComptee() ? $this->quantiteComptee - $this->quantiteTheorique : 0;
    }

    public function getCoutUnitaire(): int
    {
        return $this->coutUnitaire;
    }

    /**
     * Écart valorisé, en centimes. Le coût est au millième d'unité, d'où la
     * division entière par 1000 — jamais de flottant sur de l'argent.
     */
    public function ecartValorise(): int
    {
        return intdiv($this->ecart() * $this->coutUnitaire, 1000);
    }
}
