<?php

namespace App\Entity\Magasin;

use App\Enum\Magasin\MotifRejetMagasin;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un produit d'une réception, étape par étape :
 *
 * 1. **annoncé** — ce que dit le bon de livraison ;
 * 2. **compté** — ce qui est réellement là (contrôle quantitatif) ;
 * 3. **accepté / rejeté** + motif — l'état de la marchandise (inspection) ;
 * 4. **emplacement** — où l'accepté est rangé (stockage).
 *
 * Les quantités sont en **millièmes d'unité de saisie** : l'unité d'achat du
 * produit (« sac ») s'il en a une, son unité de stock sinon. Unité et contenance
 * sont **figées** sur la ligne : changer plus tard la taille des sacs dans la
 * fiche produit ne réécrit pas une réception déjà faite.
 *
 * Le prix, facultatif, est par unité de saisie, en centimes (« 25 000 F le sac »).
 * Seule la dirigeante le saisit et le voit.
 */
#[ORM\Entity]
#[ORM\Table(name: 'magasin_ligne_reception')]
class MagasinLigneReception
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MagasinReception::class, inversedBy: 'lignes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MagasinReception $reception;

    #[ORM\ManyToOne(targetEntity: MagasinProduit::class)]
    #[ORM\JoinColumn(nullable: false)]
    private MagasinProduit $produit;

    #[ORM\Column(length: 30)]
    private string $uniteSaisie;

    /** Millièmes d'unité de stock par unité de saisie : 50 000 pour un sac de 50 kg, 1 000 sans unité d'achat. */
    #[ORM\Column(type: Types::BIGINT)]
    private int $contenance;

    #[ORM\Column(type: Types::BIGINT)]
    private int $qteAnnoncee;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $qteComptee = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $qteAcceptee = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $qteRejetee = null;

    #[ORM\Column(length: 20, nullable: true, enumType: MotifRejetMagasin::class)]
    private ?MotifRejetMagasin $motifRejet = null;

    #[ORM\ManyToOne(targetEntity: MagasinEmplacement::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?MagasinEmplacement $emplacement = null;

    /** Centimes par unité de saisie, facultatif. */
    #[ORM\Column(nullable: true)]
    private ?int $prixUnitaire = null;

    public function __construct(MagasinReception $reception, MagasinProduit $produit, int $qteAnnoncee, ?int $prixUnitaire)
    {
        $this->reception = $reception;
        $this->produit = $produit;
        $this->uniteSaisie = $produit->aUneUniteAchat() ? (string) $produit->getUniteAchat() : $produit->getUniteStock();
        $this->contenance = $produit->aUneUniteAchat() ? (int) $produit->getContenanceAchat() : 1000;
        $this->qteAnnoncee = $qteAnnoncee;
        $this->prixUnitaire = $prixUnitaire;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReception(): MagasinReception
    {
        return $this->reception;
    }

    public function getProduit(): MagasinProduit
    {
        return $this->produit;
    }

    public function getUniteSaisie(): string
    {
        return $this->uniteSaisie;
    }

    public function getContenance(): int
    {
        return (int) $this->contenance;
    }

    public function getQteAnnoncee(): int
    {
        return (int) $this->qteAnnoncee;
    }

    public function getQteComptee(): ?int
    {
        return null !== $this->qteComptee ? (int) $this->qteComptee : null;
    }

    public function getQteAcceptee(): ?int
    {
        return null !== $this->qteAcceptee ? (int) $this->qteAcceptee : null;
    }

    public function getQteRejetee(): ?int
    {
        return null !== $this->qteRejetee ? (int) $this->qteRejetee : null;
    }

    public function getMotifRejet(): ?MotifRejetMagasin
    {
        return $this->motifRejet;
    }

    public function getEmplacement(): ?MagasinEmplacement
    {
        return $this->emplacement;
    }

    public function getPrixUnitaire(): ?int
    {
        return $this->prixUnitaire;
    }

    /** Le compté diffère-t-il de l'annoncé ? C'est ce que le contrôle signale. */
    public function aUnEcartDeComptage(): bool
    {
        return null !== $this->qteComptee && (int) $this->qteComptee !== $this->getQteAnnoncee();
    }

    /** Ce qui entre en stock au stockage, en millièmes d'unité de stock. */
    public function qteAccepteeEnStock(): int
    {
        return self::diviserArrondi((int) $this->getQteAcceptee() * $this->getContenance(), 1000);
    }

    /** Valeur de l'accepté au prix saisi, en centimes ; nulle sans prix. */
    public function valeurAcceptee(): ?int
    {
        return null !== $this->prixUnitaire ? self::diviserArrondi((int) $this->getQteAcceptee() * $this->prixUnitaire, 1000) : null;
    }

    // --- Écritures, appelées par MagasinReception seulement -----------------

    /** @internal étape 1 : l'annonce se corrige tant que le contrôle n'a pas eu lieu */
    public function corrigerAnnonce(int $qteAnnoncee): void
    {
        $this->qteAnnoncee = $qteAnnoncee;
    }

    /** @internal étape 2 */
    public function compter(int $qteComptee): void
    {
        if ($qteComptee < 0) {
            throw new \DomainException(\sprintf('« %s » : la quantité comptée ne peut pas être négative.', $this->produit->getNom()));
        }
        $this->qteComptee = $qteComptee;
    }

    /**
     * @internal étape 3 : tout ce qui est compté est accepté ou rejeté, et un rejet a un motif
     *
     * @throws \DomainException
     */
    public function inspecter(int $acceptee, int $rejetee, ?MotifRejetMagasin $motif): void
    {
        $nom = $this->produit->getNom();
        if ($acceptee < 0 || $rejetee < 0) {
            throw new \DomainException(\sprintf('« %s » : aucune quantité ne peut être négative.', $nom));
        }
        if ($acceptee + $rejetee !== $this->getQteComptee()) {
            throw new \DomainException(\sprintf('« %s » : accepté et rejeté doivent faire ensemble la quantité comptée.', $nom));
        }
        if ($rejetee > 0 && null === $motif) {
            throw new \DomainException(\sprintf('« %s » : indiquez le motif du rejet.', $nom));
        }

        $this->qteAcceptee = $acceptee;
        $this->qteRejetee = $rejetee;
        $this->motifRejet = $rejetee > 0 ? $motif : null;
    }

    /** @internal étape 4 */
    public function ranger(?MagasinEmplacement $emplacement): void
    {
        $this->emplacement = $emplacement;
    }

    /** @internal prix d'achat, avant le stockage */
    public function fixerPrix(?int $prixUnitaire): void
    {
        if (null !== $prixUnitaire && $prixUnitaire < 0) {
            throw new \DomainException(\sprintf('« %s » : le prix ne peut pas être négatif.', $this->produit->getNom()));
        }
        $this->prixUnitaire = $prixUnitaire;
    }

    private static function diviserArrondi(int $numerateur, int $denominateur): int
    {
        $signe = ($numerateur < 0) !== ($denominateur < 0) ? -1 : 1;

        return $signe * intdiv(abs($numerateur) * 2 + abs($denominateur), abs($denominateur) * 2);
    }
}
