<?php

namespace App\Entity;

use App\Entity\Trait\HorodatageCreation;
use App\Enum\Atelier;
use App\Enum\StatutSaisieProduction;
use App\Enum\TypeSaisieProduction;
use App\Repository\SaisieProductionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une déclaration de l'atelier ou de la vitrine, rattachée à la caisse ouverte.
 *
 * - PRODUCTION : ce que le boulanger ou le pâtissier vient de sortir du four
 *   (`atelier` renseigné) ;
 * - VITRINE : ce que la vendeuse a sorti du fournil pour le mettre en vente.
 *
 * Plusieurs déclarations par caisse : une par fournée, une par réassort.
 *
 * Elle naît **validée** — il n'y a pas de brouillon à l'atelier — et ne se modifie
 * pas : une erreur s'annule, motif à l'appui, et se ressaisit. La déclaration ne
 * touche pas au stock : elle s'ajoute à la fiche de production de la caisse
 * ({@see FicheProduction}), que la gérante lit à côté des ventes.
 */
#[ORM\Entity(repositoryClass: SaisieProductionRepository::class)]
#[ORM\Table(name: 'saisie_production')]
#[ORM\UniqueConstraint(name: 'uniq_saisie_production_numero', columns: ['numero'])]
class SaisieProduction
{
    use HorodatageCreation;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** PRD-AAAAMMJJ-XXX ou VIT-AAAAMMJJ-XXX. */
    #[ORM\Column(length: 20)]
    private string $numero;

    #[ORM\Column(length: 20, enumType: TypeSaisieProduction::class)]
    private TypeSaisieProduction $type;

    /** Renseigné pour une production, nul pour une mise en vitrine. */
    #[ORM\Column(length: 20, nullable: true, enumType: Atelier::class)]
    private ?Atelier $atelier;

    #[ORM\ManyToOne(targetEntity: SessionCaisse::class)]
    #[ORM\JoinColumn(nullable: false)]
    private SessionCaisse $sessionCaisse;

    #[ORM\Column(length: 20, enumType: StatutSaisieProduction::class)]
    private StatutSaisieProduction $statut = StatutSaisieProduction::VALIDEE;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Utilisateur $createdBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $annuleAt = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Utilisateur $annulePar = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motifAnnulation = null;

    /** @var Collection<int, LigneSaisieProduction> */
    #[ORM\OneToMany(mappedBy: 'saisie', targetEntity: LigneSaisieProduction::class, cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lignes;

    /**
     * @param list<array{0: Article, 1: int}> $quantites produit et quantité en millièmes
     *
     * @throws \DomainException caisse clôturée, atelier manquant, déclaration vide
     */
    public function __construct(
        string $numero,
        TypeSaisieProduction $type,
        SessionCaisse $sessionCaisse,
        ?Atelier $atelier,
        array $quantites,
        Utilisateur $createdBy,
    ) {
        // Une déclaration appartient à une journée de caisse en cours : après le Z,
        // la journée est arrêtée, la caisse suivante a sa propre fiche.
        $sessionCaisse->garantirOuverte();

        if (TypeSaisieProduction::PRODUCTION === $type && null === $atelier) {
            throw new \DomainException('Une production se déclare pour un atelier : boulangerie ou pâtisserie.');
        }

        $this->numero = $numero;
        $this->type = $type;
        $this->atelier = TypeSaisieProduction::PRODUCTION === $type ? $atelier : null;
        $this->sessionCaisse = $sessionCaisse;
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();
        $this->lignes = new ArrayCollection();

        foreach ($quantites as [$produit, $quantite]) {
            if ($quantite <= 0) {
                throw new \DomainException(\sprintf('Quantité invalide pour « %s ».', $produit->getNom()));
            }
            $this->lignes->add(new LigneSaisieProduction($this, $produit, $quantite));
        }

        if ($this->lignes->isEmpty()) {
            throw new \DomainException('Rien à déclarer : saisissez au moins une quantité.');
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumero(): string
    {
        return $this->numero;
    }

    public function getType(): TypeSaisieProduction
    {
        return $this->type;
    }

    public function estProduction(): bool
    {
        return TypeSaisieProduction::PRODUCTION === $this->type;
    }

    public function getAtelier(): ?Atelier
    {
        return $this->atelier;
    }

    public function getSessionCaisse(): SessionCaisse
    {
        return $this->sessionCaisse;
    }

    public function getStatut(): StatutSaisieProduction
    {
        return $this->statut;
    }

    public function estValidee(): bool
    {
        return StatutSaisieProduction::VALIDEE === $this->statut;
    }

    public function estAnnulee(): bool
    {
        return StatutSaisieProduction::ANNULEE === $this->statut;
    }

    public function getCreatedBy(): Utilisateur
    {
        return $this->createdBy;
    }

    public function getAnnuleAt(): ?\DateTimeImmutable
    {
        return $this->annuleAt;
    }

    public function getAnnulePar(): ?Utilisateur
    {
        return $this->annulePar;
    }

    public function getMotifAnnulation(): ?string
    {
        return $this->motifAnnulation;
    }

    /** @return Collection<int, LigneSaisieProduction> */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    /** Nombre total d'unités déclarées, en millièmes. */
    public function totalQuantite(): int
    {
        return array_sum(array_map(static fn (LigneSaisieProduction $l): int => $l->getQuantite(), $this->lignes->toArray()));
    }

    /**
     * Contrôle sans rien modifier, pour que le service refuse avant d'écrire.
     *
     *
     * @throws \DomainException
     */
    public function verifierAnnulable(?string $motif): void
    {
        if ($this->estAnnulee()) {
            throw new \DomainException(\sprintf('La déclaration %s est déjà annulée.', $this->numero));
        }
        if ('' === trim((string) $motif)) {
            throw new \DomainException('Le motif d\'annulation est obligatoire.');
        }
    }

    public function annuler(?string $motif, Utilisateur $par, \DateTimeImmutable $le): self
    {
        $this->verifierAnnulable($motif);

        $this->statut = StatutSaisieProduction::ANNULEE;
        $this->motifAnnulation = trim((string) $motif);
        $this->annulePar = $par;
        $this->annuleAt = $le;

        return $this;
    }
}
