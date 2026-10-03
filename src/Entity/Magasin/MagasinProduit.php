<?php

namespace App\Entity\Magasin;

use App\Entity\Fournisseur;
use App\Entity\Trait\HorodatageCreation;
use App\Enum\Magasin\CategorieProduitMagasin;
use App\Repository\Magasin\MagasinProduitRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un produit du magasin : matière, emballage, produit d'entretien.
 *
 * **Indépendant du reste de l'application** : ce n'est pas une
 * `MatierePremiere`, il n'entre dans aucune fiche technique et aucune vente ne
 * le fait bouger. Seuls les bons du module Magasin modifient son stock.
 *
 * Deux unités : l'**unité de stock** (kg, L, pièce), dans laquelle le stock est
 * tenu, et l'**unité d'achat** (sac, plaquette, carton) avec sa contenance, dans
 * laquelle on reçoit et on compte au magasin. « 20 sacs (1 000 kg) ».
 */
#[ORM\Entity(repositoryClass: MagasinProduitRepository::class)]
#[ORM\Table(name: 'magasin_produit')]
#[ORM\UniqueConstraint(name: 'uniq_magasin_produit_nom', columns: ['nom'])]
class MagasinProduit
{
    use HorodatageCreation;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    private string $nom;

    #[ORM\Column(length: 20, enumType: CategorieProduitMagasin::class)]
    private CategorieProduitMagasin $categorie;

    /** kg, L, pièce… : l'unité dans laquelle le stock est tenu. */
    #[ORM\Column(length: 20)]
    private string $uniteStock;

    /** sac, plaquette, carton… — nulle si le produit s'achète dans son unité de stock. */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $uniteAchat = null;

    /** Contenu d'une unité d'achat, en millièmes d'unité de stock : 50 000 pour un sac de 50 kg. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $contenanceAchat = null;

    /** Seuil d'alerte, en millièmes d'unité de stock, tous emplacements confondus. */
    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int $seuilAlerte = 0;

    /** Fournisseur habituel, proposé d'office sur une réception. Relation à sens unique : Fournisseur n'en sait rien. */
    #[ORM\ManyToOne(targetEntity: Fournisseur::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Fournisseur $fournisseurHabituel = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $actif = true;

    /**
     * Coût moyen pondéré, en centimes par unité de stock. Tenu par le stockage
     * des réceptions (quand un prix est saisi) — jamais saisi à la main.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $coutMoyen = 0;

    public function __construct(string $nom, CategorieProduitMagasin $categorie, string $uniteStock)
    {
        $this->nom = $nom;
        $this->categorie = $categorie;
        $this->uniteStock = $uniteStock;
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
        $this->nom = trim($nom);

        return $this;
    }

    public function getCategorie(): CategorieProduitMagasin
    {
        return $this->categorie;
    }

    public function setCategorie(CategorieProduitMagasin $categorie): self
    {
        $this->categorie = $categorie;

        return $this;
    }

    public function getUniteStock(): string
    {
        return $this->uniteStock;
    }

    public function setUniteStock(string $uniteStock): self
    {
        $this->uniteStock = trim($uniteStock);

        return $this;
    }

    public function getUniteAchat(): ?string
    {
        return $this->uniteAchat;
    }

    /** Une unité blanche vaut « pas d'unité d'achat ». */
    public function setUniteAchat(?string $uniteAchat): self
    {
        $uniteAchat = null !== $uniteAchat ? trim($uniteAchat) : null;
        $this->uniteAchat = '' !== $uniteAchat ? $uniteAchat : null;

        return $this;
    }

    public function getContenanceAchat(): ?int
    {
        return null !== $this->contenanceAchat ? (int) $this->contenanceAchat : null;
    }

    public function setContenanceAchat(?int $contenanceAchat): self
    {
        $this->contenanceAchat = $contenanceAchat;

        return $this;
    }

    public function getSeuilAlerte(): int
    {
        return (int) $this->seuilAlerte;
    }

    public function setSeuilAlerte(int $seuilAlerte): self
    {
        $this->seuilAlerte = max(0, $seuilAlerte);

        return $this;
    }

    public function getFournisseurHabituel(): ?Fournisseur
    {
        return $this->fournisseurHabituel;
    }

    public function setFournisseurHabituel(?Fournisseur $fournisseur): self
    {
        $this->fournisseurHabituel = $fournisseur;

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

    public function getCoutMoyen(): int
    {
        return $this->coutMoyen;
    }

    /**
     * Réservé au service de réception du module (stockage, annulation) : le CMP
     * découle des achats, il ne se saisit pas.
     */
    public function definirCoutMoyen(int $centimesParUnite): self
    {
        $this->coutMoyen = max(0, $centimesParUnite);

        return $this;
    }

    /** L'unité d'achat est-elle utilisable : nommée, et d'une contenance positive ? */
    public function aUneUniteAchat(): bool
    {
        return null !== $this->uniteAchat && null !== $this->contenanceAchat && $this->contenanceAchat > 0;
    }

    /**
     * Unités d'achat → unité de stock : 20 sacs (20 000 millièmes) de 50 kg font
     * 1 000 000 millièmes de kg. Arithmétique entière, arrondie au millième.
     *
     * @throws \LogicException sans unité d'achat
     */
    public function versUniteStock(int $millimesAchat): int
    {
        if (!$this->aUneUniteAchat()) {
            throw new \LogicException(\sprintf('« %s » n\'a pas d\'unité d\'achat.', $this->nom));
        }

        return self::diviserArrondi($millimesAchat * (int) $this->contenanceAchat, 1000);
    }

    /**
     * Unité de stock → unités d'achat, pour l'affichage. Nul sans unité d'achat :
     * « non défini » n'est pas « zéro sac ».
     */
    public function versUniteAchat(int $millimesStock): ?int
    {
        if (!$this->aUneUniteAchat()) {
            return null;
        }

        return self::diviserArrondi($millimesStock * 1000, (int) $this->contenanceAchat);
    }

    /** Division entière arrondie au plus proche, symétrique pour les négatifs. */
    private static function diviserArrondi(int $numerateur, int $denominateur): int
    {
        $signe = ($numerateur < 0) !== ($denominateur < 0) ? -1 : 1;

        return $signe * intdiv(abs($numerateur) * 2 + abs($denominateur), abs($denominateur) * 2);
    }

    public function __toString(): string
    {
        return $this->nom;
    }
}
