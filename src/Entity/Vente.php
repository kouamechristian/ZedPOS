<?php

namespace App\Entity;

use App\Entity\Trait\HorodatageCreation;
use App\Enum\ModeVente;
use App\Enum\StatutVente;
use App\Repository\VenteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Une vente encaissée.
 *
 * Entité immuable après validation : aucun setter public sur les montants ni
 * sur les données d'identité. Une vente n'est jamais supprimée physiquement ;
 * elle est annulée via {@see self::annuler()}.
 */
#[ORM\Entity(repositoryClass: VenteRepository::class)]
#[ORM\Table(name: 'vente')]
#[ORM\Index(name: 'idx_vente_created_at', columns: ['created_at'])]
#[ORM\UniqueConstraint(name: 'uniq_vente_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_vente_numero', columns: ['numero'])]
class Vente
{
    use HorodatageCreation;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $uuid;

    /** Numéro de ticket lisible, unique. */
    #[ORM\Column(length: 30)]
    private string $numero;

    #[ORM\ManyToOne(targetEntity: SessionCaisse::class, inversedBy: 'ventes')]
    #[ORM\JoinColumn(nullable: false)]
    private SessionCaisse $sessionCaisse;

    #[ORM\Column(length: 20, enumType: ModeVente::class)]
    private ModeVente $mode;

    /** Total hors taxes, en centimes de FCFA. */
    #[ORM\Column]
    private int $totalHt;

    /** Total de TVA, en centimes de FCFA. */
    #[ORM\Column]
    private int $totalTva;

    /** Total toutes taxes comprises, en centimes de FCFA. */
    #[ORM\Column]
    private int $totalTtc;

    #[ORM\Column(length: 20, enumType: StatutVente::class)]
    private StatutVente $statut;

    /** Remise appliquée sur la vente, en centimes de FCFA. */
    #[ORM\Column(options: ['default' => 0])]
    private int $remise = 0;

    /** Motif de la remise (obligatoire au-delà de 500 FCFA). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motifRemise = null;

    /** Rendu de monnaie, en centimes de FCFA. */
    #[ORM\Column(options: ['default' => 0])]
    private int $rendu = 0;

    /** Motif d'annulation (obligatoire ; la vente n'est jamais supprimée). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motifAnnulation = null;

    /** @var Collection<int, LigneVente> */
    #[ORM\OneToMany(mappedBy: 'vente', targetEntity: LigneVente::class, cascade: ['persist'])]
    private Collection $lignes;

    /** @var Collection<int, Reglement> */
    #[ORM\OneToMany(mappedBy: 'vente', targetEntity: Reglement::class, cascade: ['persist'])]
    private Collection $reglements;

    public function __construct(
        SessionCaisse $sessionCaisse,
        ModeVente $mode,
        string $numero,
        int $totalHt,
        int $totalTva,
        int $totalTtc,
        ?Uuid $uuid = null,
    ) {
        $this->sessionCaisse = $sessionCaisse;
        $this->mode = $mode;
        $this->numero = $numero;
        $this->totalHt = $totalHt;
        $this->totalTva = $totalTva;
        $this->totalTtc = $totalTtc;
        // L'UUID peut être fourni par le client (idempotence) ou généré ici.
        $this->uuid = $uuid ?? Uuid::v4();
        $this->statut = StatutVente::VALIDEE;
        $this->lignes = new ArrayCollection();
        $this->reglements = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * Renseigne remise et rendu au moment de la création de la vente.
     */
    public function enregistrerRemiseEtRendu(int $remise, ?string $motifRemise, int $rendu): self
    {
        $this->remise = $remise;
        $this->motifRemise = $motifRemise;
        $this->rendu = $rendu;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getNumero(): string
    {
        return $this->numero;
    }

    public function getSessionCaisse(): SessionCaisse
    {
        return $this->sessionCaisse;
    }

    public function getMode(): ModeVente
    {
        return $this->mode;
    }

    public function getTotalHt(): int
    {
        return $this->totalHt;
    }

    public function getTotalTva(): int
    {
        return $this->totalTva;
    }

    public function getTotalTtc(): int
    {
        return $this->totalTtc;
    }

    public function getStatut(): StatutVente
    {
        return $this->statut;
    }

    public function estValidee(): bool
    {
        return StatutVente::VALIDEE === $this->statut;
    }

    /**
     * Annule la vente. Seule mutation autorisée après création : le statut
     * passe à ANNULEE, les montants restent intacts pour la traçabilité.
     */
    public function annuler(?string $motif = null): self
    {
        if (StatutVente::ANNULEE === $this->statut) {
            throw new \LogicException('Cette vente est déjà annulée.');
        }

        $this->statut = StatutVente::ANNULEE;
        $this->motifAnnulation = $motif;

        return $this;
    }

    public function getRemise(): int
    {
        return $this->remise;
    }

    public function getMotifRemise(): ?string
    {
        return $this->motifRemise;
    }

    public function getRendu(): int
    {
        return $this->rendu;
    }

    public function getMotifAnnulation(): ?string
    {
        return $this->motifAnnulation;
    }

    /**
     * Rattache une ligne à la vente. N'altère pas les montants (fixés à la
     * construction) : sert uniquement à maintenir la relation bidirectionnelle.
     */
    public function ajouterLigne(LigneVente $ligne): self
    {
        if (!$this->lignes->contains($ligne)) {
            $this->lignes->add($ligne);
        }

        return $this;
    }

    /** @return Collection<int, LigneVente> */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    public function ajouterReglement(Reglement $reglement): self
    {
        if (!$this->reglements->contains($reglement)) {
            $this->reglements->add($reglement);
        }

        return $this;
    }

    /** @return Collection<int, Reglement> */
    public function getReglements(): Collection
    {
        return $this->reglements;
    }
}
