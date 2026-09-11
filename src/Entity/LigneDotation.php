<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un produit confié sur un bon de dotation.
 *
 * Les prix restent **nuls tant que le bon est un brouillon** — les montants sont
 * alors estimés sur les prix courants — et sont copiés depuis l'article à la
 * validation. Un changement de prix ensuite ne touche plus ce bon.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ligne_dotation')]
#[ORM\UniqueConstraint(name: 'uniq_ligne_dotation_produit', columns: ['bon_id', 'produit_id'])]
class LigneDotation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BonDotation::class, inversedBy: 'lignes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private BonDotation $bon;

    #[ORM\ManyToOne(targetEntity: Article::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Article $produit;

    /** En millièmes d'unité. */
    #[ORM\Column(type: Types::BIGINT)]
    private int $quantite;

    /** Centimes de FCFA, figé à la validation. */
    #[ORM\Column(nullable: true)]
    private ?int $prixVenteUnitaire = null;

    /** Centimes de FCFA, figé à la validation. */
    #[ORM\Column(nullable: true)]
    private ?int $prixCessionUnitaire = null;

    /*
     * La part de cette ligne dans le point qui l'a soldée — nulle tant qu'aucun point
     * validé ne l'a arrêtée. C'est ce que lisent les rapports des stands, à la date
     * de la dotation : voir App\Service\Point\RepartitionVentes.
     */

    /** Millièmes. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $qteVendue = null;

    /** Millièmes, retours INVENDU. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $qteRetournee = null;

    /** Millièmes, casse, périmé, autre. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $qtePerdue = null;

    /** Centimes : le vendu au prix de vente figé. */
    #[ORM\Column(nullable: true)]
    private ?int $montantVendu = null;

    /** Centimes : la part de la rémunération du vendeur. */
    #[ORM\Column(nullable: true)]
    private ?int $remuneration = null;

    public function __construct(BonDotation $bon, Article $produit, int $quantite)
    {
        $this->bon = $bon;
        $this->produit = $produit;
        $this->modifierQuantite($quantite);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBon(): BonDotation
    {
        return $this->bon;
    }

    public function getProduit(): Article
    {
        return $this->produit;
    }

    public function getQuantite(): int
    {
        return (int) $this->quantite;
    }

    public function getPrixVenteUnitaire(): ?int
    {
        return $this->prixVenteUnitaire;
    }

    public function getPrixCessionUnitaire(): ?int
    {
        return $this->prixCessionUnitaire;
    }

    /** @internal Réservé à {@see BonDotation::definirQuantites()}, qui garantit le brouillon. */
    public function modifierQuantite(int $quantite): void
    {
        if ($quantite <= 0) {
            throw new \DomainException('Une ligne de dotation porte une quantité positive.');
        }

        $this->quantite = $quantite;
    }

    /** @internal Réservé à {@see BonDotation::valider()}. */
    public function figerPrix(): void
    {
        $this->prixVenteUnitaire = $this->produit->getPrixVenteTtc();
        $this->prixCessionUnitaire = $this->produit->getPrixCession();
    }

    /**
     * @internal Réservé à la validation d'un point ({@see \App\Service\ArreteService}).
     *
     * @param array{vendue: int, retournee: int, perdue: int, montant: int, remuneration: int} $part
     */
    public function figerVente(array $part): void
    {
        if (null === $this->prixVenteUnitaire) {
            throw new \LogicException('Une ligne de bon non validé ne se solde pas.');
        }
        if ($part['vendue'] + $part['retournee'] + $part['perdue'] !== $this->getQuantite()) {
            throw new \LogicException(\sprintf('« %s » : la part soldée ne correspond pas à la quantité confiée.', $this->produit->getNom()));
        }

        $this->qteVendue = $part['vendue'];
        $this->qteRetournee = $part['retournee'];
        $this->qtePerdue = $part['perdue'];
        $this->montantVendu = $part['montant'];
        $this->remuneration = $part['remuneration'];
    }

    /** @internal Réservé à l'annulation du point : la ligne redevient à arrêter. */
    public function oublierVente(): void
    {
        $this->qteVendue = $this->qteRetournee = $this->qtePerdue = $this->montantVendu = $this->remuneration = null;
    }

    public function getQteVendue(): ?int
    {
        return null === $this->qteVendue ? null : (int) $this->qteVendue;
    }

    public function getQteRetournee(): ?int
    {
        return null === $this->qteRetournee ? null : (int) $this->qteRetournee;
    }

    public function getQtePerdue(): ?int
    {
        return null === $this->qtePerdue ? null : (int) $this->qtePerdue;
    }

    public function getMontantVendu(): ?int
    {
        return $this->montantVendu;
    }

    public function getRemuneration(): ?int
    {
        return $this->remuneration;
    }

    /** Montant au prix de vente, en centimes. Quantités entières d'unité : division exacte. */
    public function montantVente(): int
    {
        return intdiv($this->getQuantite() * ($this->prixVenteUnitaire ?? $this->produit->getPrixVenteTtc()), 1000);
    }

    public function montantCession(): int
    {
        return intdiv($this->getQuantite() * ($this->prixCessionUnitaire ?? $this->produit->getPrixCession()), 1000);
    }
}
