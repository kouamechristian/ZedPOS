<?php

namespace App\Entity\Magasin;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un produit d'une sortie, pris dans un emplacement.
 *
 * La quantité se saisit **en unité d'achat ou en unité de stock**, au choix de la
 * gérante : « 3 sacs » ou « 12,5 kg ». La saisie est gardée telle quelle pour
 * l'affichage et l'impression ; la quantité qui sort du stock est calculée une
 * fois, à la saisie, avec la contenance **figée** — changer plus tard la taille
 * des sacs ne réécrit pas un bon.
 *
 * Le coût moyen du produit est figé à la validation : la valeur d'une sortie ne
 * change pas parce que le coût moyen a bougé depuis.
 */
#[ORM\Entity]
#[ORM\Table(name: 'magasin_ligne_sortie')]
class MagasinLigneSortie
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MagasinSortie::class, inversedBy: 'lignes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MagasinSortie $sortie;

    #[ORM\ManyToOne(targetEntity: MagasinProduit::class)]
    #[ORM\JoinColumn(nullable: false)]
    private MagasinProduit $produit;

    #[ORM\ManyToOne(targetEntity: MagasinEmplacement::class)]
    #[ORM\JoinColumn(nullable: false)]
    private MagasinEmplacement $emplacement;

    /** La saisie était-elle en unité d'achat (« sac ») ? */
    #[ORM\Column]
    private bool $enUniteAchat;

    #[ORM\Column(length: 30)]
    private string $uniteSaisie;

    /** Millièmes d'unité de stock par unité de saisie : 50 000 pour un sac de 50 kg, 1 000 en unité de stock. */
    #[ORM\Column(type: Types::BIGINT)]
    private int $contenance;

    /** Millièmes d'unité de saisie. */
    #[ORM\Column(type: Types::BIGINT)]
    private int $qteSaisie;

    /** Millièmes d'unité de stock : ce qui sort. */
    #[ORM\Column(type: Types::BIGINT)]
    private int $quantite;

    /** Centimes par unité de stock, figé à la validation. */
    #[ORM\Column(nullable: true)]
    private ?int $coutUnitaire = null;

    /**
     * @throws \DomainException quantité nulle ou négative
     */
    public function __construct(MagasinSortie $sortie, MagasinProduit $produit, MagasinEmplacement $emplacement, int $qteSaisie, bool $enUniteAchat)
    {
        if ($qteSaisie <= 0) {
            throw new \DomainException(\sprintf('« %s » : la quantité doit être positive.', $produit->getNom()));
        }
        // Un produit sans unité d'achat ne se saisit qu'en unité de stock.
        $enUniteAchat = $enUniteAchat && $produit->aUneUniteAchat();

        $this->sortie = $sortie;
        $this->produit = $produit;
        $this->emplacement = $emplacement;
        $this->enUniteAchat = $enUniteAchat;
        $this->uniteSaisie = $enUniteAchat ? (string) $produit->getUniteAchat() : $produit->getUniteStock();
        $this->contenance = $enUniteAchat ? (int) $produit->getContenanceAchat() : 1000;
        $this->qteSaisie = $qteSaisie;
        $this->quantite = self::diviserArrondi($qteSaisie * $this->contenance, 1000);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSortie(): MagasinSortie
    {
        return $this->sortie;
    }

    public function getProduit(): MagasinProduit
    {
        return $this->produit;
    }

    public function getEmplacement(): MagasinEmplacement
    {
        return $this->emplacement;
    }

    public function isEnUniteAchat(): bool
    {
        return $this->enUniteAchat;
    }

    public function getUniteSaisie(): string
    {
        return $this->uniteSaisie;
    }

    public function getContenance(): int
    {
        return (int) $this->contenance;
    }

    public function getQteSaisie(): int
    {
        return (int) $this->qteSaisie;
    }

    public function getQuantite(): int
    {
        return (int) $this->quantite;
    }

    public function getCoutUnitaire(): ?int
    {
        return $this->coutUnitaire;
    }

    /** Valeur sortie au coût figé, en centimes ; nulle tant que la sortie n'est pas validée. */
    public function getValeur(): ?int
    {
        return null !== $this->coutUnitaire ? self::diviserArrondi($this->getQuantite() * $this->coutUnitaire, 1000) : null;
    }

    /** @internal appelée par MagasinSortie::valider() */
    public function figerCout(int $coutUnitaire): void
    {
        $this->coutUnitaire = $coutUnitaire;
    }

    private static function diviserArrondi(int $numerateur, int $denominateur): int
    {
        $signe = ($numerateur < 0) !== ($denominateur < 0) ? -1 : 1;

        return $signe * intdiv(abs($numerateur) * 2 + abs($denominateur), abs($denominateur) * 2);
    }
}
