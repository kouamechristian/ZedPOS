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
    ) {
        $this->sessionCaisse = $sessionCaisse;
        $this->mode = $mode;
        $this->numero = $numero;
        $this->totalHt = $totalHt;
        $this->totalTva = $totalTva;
        $this->totalTtc = $totalTtc;
        $this->uuid = Uuid::v4();
        $this->statut = StatutVente::VALIDEE;
        $this->lignes = new ArrayCollection();
        $this->reglements = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
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
    public function annuler(): self
    {
        if (StatutVente::ANNULEE === $this->statut) {
            throw new \LogicException('Cette vente est déjà annulée.');
        }

        $this->statut = StatutVente::ANNULEE;

        return $this;
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
