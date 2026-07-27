<?php

namespace App\Entity;

use App\Entity\Trait\HorodatageCreation;
use App\Repository\ArticleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ArticleRepository::class)]
class Article
{
    use HorodatageCreation;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    private string $nom;

    #[ORM\ManyToOne(targetEntity: FamilleProduit::class, inversedBy: 'articles')]
    #[ORM\JoinColumn(nullable: true)]
    private ?FamilleProduit $familleProduit = null;

    /** Prix de vente TTC, en centimes de FCFA (jamais de float pour l'argent). */
    #[ORM\Column]
    private int $prixVenteTtc;

    /** Unité de vente (ex. « pièce », « kg », « portion »). */
    #[ORM\Column(length: 20)]
    private string $unite;

    /** Taux de TVA en points de base : 1800 = 18,00 %. */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $tauxTva = 0;

    #[ORM\Column(options: ['default' => true])]
    private bool $actif = true;

    /** Couleur de la touche sur la grille de caisse (code hexadécimal). */
    #[ORM\Column(length: 7, nullable: true)]
    private ?string $couleur = null;

    /**
     * Nom du fichier image de la touche, dans `public/uploads/articles/`.
     *
     * Le **nom seul**, jamais un chemin ni une URL : déplacer le répertoire de
     * stockage ne doit pas obliger à réécrire la table. Le chemin public se
     * compose à l'affichage ({@see \App\Service\ImageArticle::chemin()}).
     *
     * `null` — le cas courant — laisse la touche à son aplat de couleur.
     */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $image = null;

    /** Position sur la grille de caisse (ordre d'affichage). */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $positionCaisse = 0;

    #[ORM\OneToOne(mappedBy: 'article', targetEntity: FicheTechnique::class)]
    private ?FicheTechnique $ficheTechnique = null;

    /**
     * Suivi de stock direct de l'article (ex. boissons revendues sans fiche technique).
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $suiviStock = false;

    /** Stock courant, en millièmes d'unité (pour les articles suivis en stock). */
    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int $stockActuel = 0;

    /** Seuil d'alerte, en millièmes d'unité. */
    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int $stockMini = 0;

    public function __construct(string $nom, int $prixVenteTtc, string $unite)
    {
        $this->nom = $nom;
        $this->prixVenteTtc = $prixVenteTtc;
        $this->unite = $unite;
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

    public function getFamilleProduit(): ?FamilleProduit
    {
        return $this->familleProduit;
    }

    public function setFamilleProduit(?FamilleProduit $familleProduit): self
    {
        $this->familleProduit = $familleProduit;

        return $this;
    }

    public function getPrixVenteTtc(): int
    {
        return $this->prixVenteTtc;
    }

    public function setPrixVenteTtc(int $prixVenteTtc): self
    {
        $this->prixVenteTtc = $prixVenteTtc;

        return $this;
    }

    public function getUnite(): string
    {
        return $this->unite;
    }

    public function setUnite(string $unite): self
    {
        $this->unite = $unite;

        return $this;
    }

    public function getTauxTva(): int
    {
        return $this->tauxTva;
    }

    public function setTauxTva(int $tauxTva): self
    {
        $this->tauxTva = $tauxTva;

        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): self
    {
        $this->actif = $actif;

        return $this;
    }

    public function getCouleur(): ?string
    {
        return $this->couleur;
    }

    public function setCouleur(?string $couleur): self
    {
        $this->couleur = $couleur;

        return $this;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): self
    {
        $this->image = $image;

        return $this;
    }

    public function getPositionCaisse(): int
    {
        return $this->positionCaisse;
    }

    public function setPositionCaisse(int $positionCaisse): self
    {
        $this->positionCaisse = $positionCaisse;

        return $this;
    }

    public function getFicheTechnique(): ?FicheTechnique
    {
        return $this->ficheTechnique;
    }

    public function setFicheTechnique(?FicheTechnique $ficheTechnique): self
    {
        $this->ficheTechnique = $ficheTechnique;

        return $this;
    }

    public function isSuiviStock(): bool
    {
        return $this->suiviStock;
    }

    public function setSuiviStock(bool $suiviStock): self
    {
        $this->suiviStock = $suiviStock;

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
}
