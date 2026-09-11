<?php

namespace App\Entity;

use App\Entity\Trait\HorodatageCreation;
use App\Enum\ModeRemuneration;
use App\Enum\StatutBonDotation;
use App\Repository\BonDotationRepository;
use App\Service\Point\PointCalculator;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Marchandise confiée un matin à un vendeur, pour un stand.
 *
 * Cycle : BROUILLON (se modifie librement) → VALIDE (stock transféré, prix figés,
 * **immuable**) → ANNULE (mouvements inverses). Un brouillon peut aussi être
 * annulé, sans mouvement puisqu'il n'en a produit aucun.
 *
 * Les règles qui ne dépendent d'aucun appelant sont ici et lèvent **avant toute
 * écriture** ; le transfert de stock et la trace d'audit appartiennent à
 * {@see \App\Service\BonDotationService}. Plusieurs bons par jour sur un même stand
 * sont permis : c'est un réapprovisionnement.
 */
#[ORM\Entity(repositoryClass: BonDotationRepository::class)]
#[ORM\Table(name: 'bon_dotation')]
#[ORM\UniqueConstraint(name: 'uniq_bon_dotation_numero', columns: ['numero'])]
#[ORM\Index(name: 'idx_bon_dotation_date', columns: ['date_dotation'])]
class BonDotation
{
    use HorodatageCreation;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** DOT-AAAAMMJJ-XXX, d'après la date de dotation. */
    #[ORM\Column(length: 20)]
    private string $numero;

    #[ORM\ManyToOne(targetEntity: Emplacement::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Emplacement $stand;

    #[ORM\ManyToOne(targetEntity: Vendeur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Vendeur $vendeur;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dateDotation;

    #[ORM\Column(length: 20, enumType: StatutBonDotation::class)]
    private StatutBonDotation $statut = StatutBonDotation::BROUILLON;

    /** Rempli par l'arrêté de période qui solde ce bon. */
    #[ORM\ManyToOne(targetEntity: Arrete::class, inversedBy: 'bonsDotation')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Arrete $arrete = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Utilisateur $createdBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $valideAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $annuleAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motifAnnulation = null;

    /** @var Collection<int, LigneDotation> */
    #[ORM\OneToMany(mappedBy: 'bon', targetEntity: LigneDotation::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lignes;

    public function __construct(string $numero, Emplacement $stand, Vendeur $vendeur, \DateTimeImmutable $dateDotation, Utilisateur $createdBy)
    {
        if (!$stand->estStand()) {
            throw new \DomainException('Une dotation se fait vers un stand, pas vers un dépôt.');
        }
        if (!$stand->isActif()) {
            throw new \DomainException(\sprintf('Le stand « %s » est désactivé.', $stand->getLibelle()));
        }

        $this->numero = $numero;
        $this->stand = $stand;
        $this->statut = StatutBonDotation::BROUILLON;
        $this->changerVendeur($vendeur);
        $this->dateDotation = $dateDotation->setTime(0, 0);
        $this->createdBy = $createdBy;
        $this->lignes = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumero(): string
    {
        return $this->numero;
    }

    public function getStand(): Emplacement
    {
        return $this->stand;
    }

    public function getVendeur(): Vendeur
    {
        return $this->vendeur;
    }

    public function getDateDotation(): \DateTimeImmutable
    {
        return $this->dateDotation;
    }

    public function getStatut(): StatutBonDotation
    {
        return $this->statut;
    }

    public function estBrouillon(): bool
    {
        return StatutBonDotation::BROUILLON === $this->statut;
    }

    public function estValide(): bool
    {
        return StatutBonDotation::VALIDE === $this->statut;
    }

    public function estAnnule(): bool
    {
        return StatutBonDotation::ANNULE === $this->statut;
    }

    public function getArrete(): ?Arrete
    {
        return $this->arrete;
    }

    public function getCreatedBy(): Utilisateur
    {
        return $this->createdBy;
    }

    public function getValideAt(): ?\DateTimeImmutable
    {
        return $this->valideAt;
    }

    public function getAnnuleAt(): ?\DateTimeImmutable
    {
        return $this->annuleAt;
    }

    public function getMotifAnnulation(): ?string
    {
        return $this->motifAnnulation;
    }

    /** @return Collection<int, LigneDotation> */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    // ------------------------------------------------------------- Brouillon

    public function changerVendeur(Vendeur $vendeur): self
    {
        // Appelée aussi par le constructeur, où le bon est forcément un brouillon.
        $this->garantirBrouillon();
        if (!$vendeur->isActif()) {
            throw new \DomainException(\sprintf('Le vendeur « %s » est désactivé.', $vendeur->getNom()));
        }

        $this->vendeur = $vendeur;

        return $this;
    }

    public function changerDate(\DateTimeImmutable $dateDotation): self
    {
        $this->garantirBrouillon();
        $this->dateDotation = $dateDotation->setTime(0, 0);

        return $this;
    }

    /**
     * Remplace les quantités du bon. Une quantité nulle retire le produit.
     *
     * Les lignes existantes sont **mises à jour en place**, pas supprimées puis
     * recréées : Doctrine insère avant de supprimer, et la contrainte d'unicité
     * bon × produit tomberait sur un produit qui change seulement de quantité.
     *
     * @param list<array{0: Article, 1: int}> $quantites produit et quantité en millièmes
     */
    public function definirQuantites(array $quantites): self
    {
        $this->garantirBrouillon();

        $voulues = [];
        foreach ($quantites as [$produit, $quantite]) {
            if ($quantite < 0) {
                throw new \DomainException(\sprintf('Quantité négative pour « %s ».', $produit->getNom()));
            }
            if ($quantite > 0) {
                $voulues[$produit->getId()] = [$produit, $quantite];
            }
        }

        foreach ($this->lignes->toArray() as $ligne) {
            $id = $ligne->getProduit()->getId();
            if (isset($voulues[$id])) {
                $ligne->modifierQuantite($voulues[$id][1]);
                unset($voulues[$id]);
            } else {
                $this->lignes->removeElement($ligne);
            }
        }

        foreach ($voulues as [$produit, $quantite]) {
            $this->lignes->add(new LigneDotation($this, $produit, $quantite));
        }

        return $this;
    }

    // ------------------------------------------------------------- Validation

    /**
     * Ce bon peut-il être validé ? Contrôle sans rien modifier, pour que le service
     * refuse **avant** de toucher au stock.
     *
     * @throws \DomainException
     */
    public function verifierValidable(): void
    {
        $this->garantirBrouillon();

        if ($this->lignes->isEmpty()) {
            throw new \DomainException('Le bon est vide : saisissez au moins une quantité.');
        }

        // En MARGE, le vendeur garde prix de vente − prix de cession : un prix de
        // cession nul lui laisserait toute la vente. C'est à la dirigeante de le
        // fixer. En COMMISSION, il n'entre dans aucun calcul et n'est pas exigé.
        $sansPrix = [];
        foreach ($this->stand->getModeRemuneration() === ModeRemuneration::MARGE ? $this->lignes : [] as $ligne) {
            if ($ligne->getProduit()->getPrixCession() <= 0) {
                $sansPrix[] = $ligne->getProduit()->getNom();
            }
        }

        if ([] !== $sansPrix) {
            throw new \DomainException(\sprintf(
                'Prix de cession non fixé pour : %s. Seule la dirigeante peut le fixer, sur la fiche article.',
                implode(', ', $sansPrix),
            ));
        }
    }

    /**
     * Valide le bon : les prix du moment sont **copiés** sur chaque ligne, et le bon
     * devient immuable. Le transfert de stock est fait par le service, juste avant.
     */
    public function valider(\DateTimeImmutable $le): self
    {
        $this->verifierValidable();

        foreach ($this->lignes as $ligne) {
            $ligne->figerPrix();
        }

        $this->statut = StatutBonDotation::VALIDE;
        $this->valideAt = $le;

        return $this;
    }

    // ------------------------------------------------------------- Annulation

    /**
     * Ce bon peut-il être annulé ? Contrôle sans rien modifier.
     *
     * @throws \DomainException
     */
    public function verifierAnnulable(?string $motif): void
    {
        if ($this->estAnnule()) {
            throw new \DomainException(\sprintf('Le bon %s est déjà annulé.', $this->numero));
        }

        // L'arrêté a soldé ce qui a été confié : annuler le bon ferait mentir le
        // point fait avec le vendeur, et ce qu'il a payé.
        if (null !== $this->arrete && $this->arrete->estValide()) {
            throw new \DomainException(\sprintf(
                'Le bon %s est rattaché à l\'arrêté validé du %s au %s : il ne s\'annule plus.',
                $this->numero,
                $this->arrete->getDateDebut()->format('d/m/Y'),
                $this->arrete->getDateFin()->format('d/m/Y'),
            ));
        }

        if ($this->estValide() && '' === trim((string) $motif)) {
            throw new \DomainException('Le motif d\'annulation est obligatoire.');
        }
    }

    public function annuler(?string $motif, \DateTimeImmutable $le): self
    {
        $this->verifierAnnulable($motif);

        $motif = trim((string) $motif);
        $this->statut = StatutBonDotation::ANNULE;
        $this->annuleAt = $le;
        $this->motifAnnulation = '' !== $motif ? $motif : null;

        return $this;
    }

    /** Rattachement à l'arrêté qui solde ce bon, à sa validation. */
    public function rattacherArrete(Arrete $arrete): self
    {
        if (!$this->estValide()) {
            throw new \DomainException('Seul un bon validé se rattache à un arrêté.');
        }
        if ($arrete->getStand() !== $this->stand) {
            throw new \DomainException('Le bon et l\'arrêté ne portent pas sur le même stand.');
        }
        if (null !== $this->arrete && $this->arrete !== $arrete && !$this->arrete->estAnnule()) {
            throw new \DomainException(\sprintf('Le bon %s est déjà rattaché à l\'arrêté %s.', $this->numero, $this->arrete->getNumero()));
        }

        $this->arrete = $arrete;
        $arrete->suivreBonDotation($this, true);

        return $this;
    }

    /** @internal Réservé à l'annulation de l'arrêté : le bon redevient à arrêter. */
    public function detacherArrete(Arrete $arrete): self
    {
        if ($this->arrete !== $arrete || !$arrete->estAnnule()) {
            throw new \DomainException('Seule l\'annulation de son arrêté détache un bon.');
        }

        $this->arrete = null;
        $arrete->suivreBonDotation($this, false);
        foreach ($this->lignes as $ligne) {
            $ligne->oublierVente();
        }

        return $this;
    }

    // --------------------------------------------------------------- Montants

    /** Total au prix de vente, en centimes : figé si validé, estimé sinon. */
    public function totalVente(): int
    {
        return array_sum(array_map(static fn (LigneDotation $l): int => $l->montantVente(), $this->lignes->toArray()));
    }

    /** Total au prix de cession, en centimes : ce que le vendeur doit pour tout vendre. */
    public function totalCession(): int
    {
        return array_sum(array_map(static fn (LigneDotation $l): int => $l->montantCession(), $this->lignes->toArray()));
    }

    /**
     * Ce que le vendeur remettrait s'il vendait tout, selon la rémunération **actuelle**
     * du stand : prix de cession en MARGE, prix de vente moins la commission sinon.
     * Indicatif — c'est l'arrêté qui fige mode et taux, pour ce qui a été vendu.
     */
    public function netSiToutVendu(): int
    {
        if (ModeRemuneration::MARGE === $this->stand->getModeRemuneration()) {
            return $this->totalCession();
        }

        return $this->totalVente() - PointCalculator::commission($this->totalVente(), $this->stand->getTauxCommission());
    }

    public function garantirBrouillon(): void
    {
        if (!$this->estBrouillon()) {
            throw new \DomainException(\sprintf(
                'Le bon %s est %s : il ne se modifie plus.',
                $this->numero,
                mb_strtolower($this->statut->libelle()),
            ));
        }
    }
}
