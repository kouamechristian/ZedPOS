<?php

namespace App\Entity\Magasin;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une ligne de feuille d'inventaire : un produit dans un emplacement.
 *
 * Le **théorique** et le **coût moyen** sont figés à l'ouverture : la feuille est
 * un document daté, et l'écart valorisé ne doit pas changer parce que le coût
 * moyen a bougé depuis.
 *
 * Le compté se saisit en unité d'achat ou en unité de stock, comme une sortie ;
 * la saisie est gardée telle quelle pour le réafficher, la quantité en unité de
 * stock est calculée avec la contenance figée. **Compté nul (null) = pas
 * compté** : la ligne est ignorée à la validation. Zéro veut dire « il n'en reste
 * aucun ».
 */
#[ORM\Entity]
#[ORM\Table(name: 'magasin_ligne_inventaire')]
class MagasinLigneInventaire
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MagasinInventaire::class, inversedBy: 'lignes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MagasinInventaire $inventaire;

    #[ORM\ManyToOne(targetEntity: MagasinProduit::class)]
    #[ORM\JoinColumn(nullable: false)]
    private MagasinProduit $produit;

    #[ORM\ManyToOne(targetEntity: MagasinEmplacement::class)]
    #[ORM\JoinColumn(nullable: false)]
    private MagasinEmplacement $emplacement;

    /** Millièmes d'unité de stock, figé à l'ouverture. */
    #[ORM\Column(type: Types::BIGINT)]
    private int $qteTheorique;

    /** Centimes par unité de stock, figé à l'ouverture. */
    #[ORM\Column]
    private int $coutUnitaire;

    /** L'unité d'achat à l'ouverture (« sac ») ; nulle si le produit n'en avait pas. */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $uniteAchat;

    /** Millièmes d'unité de stock par unité d'achat, figé à l'ouverture ; 1 000 sans unité d'achat. */
    #[ORM\Column(type: Types::BIGINT)]
    private int $contenance;

    /** Millièmes d'unité de stock ; nul tant que la ligne n'est pas comptée. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $qteComptee = null;

    /** La saisie telle que tapée, en millièmes de l'unité choisie. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $qteSaisie = null;

    #[ORM\Column]
    private bool $enUniteAchat = false;

    public function __construct(MagasinInventaire $inventaire, MagasinProduit $produit, MagasinEmplacement $emplacement, int $qteTheorique)
    {
        $this->inventaire = $inventaire;
        $this->produit = $produit;
        $this->emplacement = $emplacement;
        $this->qteTheorique = $qteTheorique;
        $this->coutUnitaire = $produit->getCoutMoyen();
        $this->uniteAchat = $produit->aUneUniteAchat() ? $produit->getUniteAchat() : null;
        $this->contenance = $produit->aUneUniteAchat() ? (int) $produit->getContenanceAchat() : 1000;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInventaire(): MagasinInventaire
    {
        return $this->inventaire;
    }

    public function getProduit(): MagasinProduit
    {
        return $this->produit;
    }

    public function getEmplacement(): MagasinEmplacement
    {
        return $this->emplacement;
    }

    public function getQteTheorique(): int
    {
        return (int) $this->qteTheorique;
    }

    public function getCoutUnitaire(): int
    {
        return $this->coutUnitaire;
    }

    public function getContenance(): int
    {
        return (int) $this->contenance;
    }

    public function getQteComptee(): ?int
    {
        return null !== $this->qteComptee ? (int) $this->qteComptee : null;
    }

    public function getQteSaisie(): ?int
    {
        return null !== $this->qteSaisie ? (int) $this->qteSaisie : null;
    }

    public function isEnUniteAchat(): bool
    {
        return $this->enUniteAchat;
    }

    public function getUniteAchat(): ?string
    {
        return $this->uniteAchat;
    }

    /** L'unité d'achat existait-elle à l'ouverture ? Sinon on ne compte qu'en unité de stock. */
    public function peutSeCompterEnUniteAchat(): bool
    {
        return null !== $this->uniteAchat;
    }

    public function estComptee(): bool
    {
        return null !== $this->qteComptee;
    }

    /** Compté − théorique, en millièmes d'unité de stock ; nul si la ligne n'est pas comptée. */
    public function getEcart(): ?int
    {
        return null !== $this->qteComptee ? (int) $this->qteComptee - $this->getQteTheorique() : null;
    }

    /** Écart valorisé au coût figé, en centimes ; nul si la ligne n'est pas comptée. */
    public function getValeurEcart(): ?int
    {
        $ecart = $this->getEcart();

        return null !== $ecart ? self::diviserArrondi($ecart * $this->coutUnitaire, 1000) : null;
    }

    /**
     * @internal appelée par MagasinInventaire::compter()
     *
     * @param ?int $qteSaisie millièmes de l'unité choisie ; null = pas compté
     */
    public function compter(?int $qteSaisie, bool $enUniteAchat): void
    {
        if (null === $qteSaisie) {
            $this->qteSaisie = $this->qteComptee = null;
            $this->enUniteAchat = false;

            return;
        }
        if ($qteSaisie < 0) {
            throw new \DomainException(\sprintf('« %s » : une quantité comptée ne peut pas être négative.', $this->produit->getNom()));
        }
        $enUniteAchat = $enUniteAchat && $this->peutSeCompterEnUniteAchat();

        $this->qteSaisie = $qteSaisie;
        $this->enUniteAchat = $enUniteAchat;
        $this->qteComptee = $enUniteAchat ? self::diviserArrondi($qteSaisie * $this->getContenance(), 1000) : $qteSaisie;
    }

    private static function diviserArrondi(int $numerateur, int $denominateur): int
    {
        $signe = ($numerateur < 0) !== ($denominateur < 0) ? -1 : 1;

        return $signe * intdiv(abs($numerateur) * 2 + abs($denominateur), abs($denominateur) * 2);
    }
}
