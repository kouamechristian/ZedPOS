<?php

namespace App\Entity;

use App\Entity\Trait\HorodatageCreation;
use App\Enum\ModeRemuneration;
use App\Enum\PeriodicitePoint;
use App\Enum\TypeEmplacement;
use App\Repository\EmplacementRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un lieu où se trouve du stock : le dépôt de la boutique, un stand de revendeur.
 *
 * Le **code** est l'identité métier et ne change pas : c'est par lui qu'on
 * retrouve le dépôt principal ({@see self::CODE_DEPOT_PRINCIPAL}), dont le stock
 * reste recopié dans les anciens champs `stockActuel` des articles et matières.
 */
#[ORM\Entity(repositoryClass: EmplacementRepository::class)]
#[ORM\Table(name: 'emplacement')]
#[ORM\UniqueConstraint(name: 'uniq_emplacement_code', columns: ['code'])]
class Emplacement
{
    use HorodatageCreation;

    /**
     * Le dépôt créé par la migration, où le stock existant a été basculé. Tant que
     * les champs `stockActuel` subsistent, ils reflètent **ce** dépôt : c'est ce
     * que l'inventaire, les alertes de seuil et l'écran Stock lisent encore.
     */
    public const CODE_DEPOT_PRINCIPAL = 'DEPOT';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $code;

    #[ORM\Column(length: 100)]
    private string $libelle;

    #[ORM\Column(length: 10, enumType: TypeEmplacement::class)]
    private TypeEmplacement $type;

    #[ORM\Column(options: ['default' => true])]
    private bool $actif = true;

    /**
     * Rythme habituel du point avec le vendeur. Stand seulement, sans objet pour
     * un dépôt. **Valeur proposée à l'écran, jamais une contrainte.**
     */
    #[ORM\Column(length: 10, enumType: PeriodicitePoint::class, options: ['default' => 'JOUR'])]
    private PeriodicitePoint $periodicitePoint = PeriodicitePoint::JOUR;

    /** Stand seulement : le vendeur proposé d'office sur un nouveau bon de dotation. */
    #[ORM\ManyToOne(targetEntity: Vendeur::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Vendeur $vendeurHabituel = null;

    /**
     * Stand seulement. **Fixé par la dirigeante** (`Permission::REMUNERATION_FIXER`),
     * comme un prix : c'est ce que la boutique encaisse. Copié sur l'arrêté à sa
     * validation — un changement ensuite ne touche pas les points passés.
     */
    #[ORM\Column(length: 12, enumType: ModeRemuneration::class, options: ['default' => 'COMMISSION'])]
    private ModeRemuneration $modeRemuneration = ModeRemuneration::COMMISSION;

    /** Taux de commission en points de base (750 = 7,5 %). Dirigeante seule. */
    #[ORM\Column(options: ['default' => 0])]
    private int $tauxCommission = 0;

    /**
     * Écart d'espèces toléré au point sans justification, en centimes. Au-delà, un
     * commentaire est exigé. Réglé par la gérante. Zéro : tout écart se justifie,
     * comme à la clôture de caisse.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $seuilEcartAlerte = 0;

    public function __construct(string $code, string $libelle, TypeEmplacement $type)
    {
        $code = strtoupper(trim($code));
        if ('' === $code || '' === trim($libelle)) {
            throw new \InvalidArgumentException('Un emplacement exige un code et un libellé.');
        }

        $this->code = $code;
        $this->libelle = trim($libelle);
        $this->type = $type;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): self
    {
        $this->libelle = trim($libelle);

        return $this;
    }

    public function getType(): TypeEmplacement
    {
        return $this->type;
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

    public function estDepotPrincipal(): bool
    {
        return self::CODE_DEPOT_PRINCIPAL === $this->code;
    }

    public function estStand(): bool
    {
        return TypeEmplacement::STAND === $this->type;
    }

    public function getPeriodicitePoint(): PeriodicitePoint
    {
        return $this->periodicitePoint;
    }

    public function setPeriodicitePoint(PeriodicitePoint $periodicitePoint): self
    {
        $this->periodicitePoint = $periodicitePoint;

        return $this;
    }

    public function getVendeurHabituel(): ?Vendeur
    {
        return $this->vendeurHabituel;
    }

    public function setVendeurHabituel(?Vendeur $vendeurHabituel): self
    {
        $this->vendeurHabituel = $vendeurHabituel;

        return $this;
    }

    public function getModeRemuneration(): ModeRemuneration
    {
        return $this->modeRemuneration;
    }

    public function setModeRemuneration(ModeRemuneration $modeRemuneration): self
    {
        $this->modeRemuneration = $modeRemuneration;

        return $this;
    }

    public function getTauxCommission(): int
    {
        return $this->tauxCommission;
    }

    public function setTauxCommission(int $tauxCommission): self
    {
        if ($tauxCommission < 0 || $tauxCommission > 10000) {
            throw new \DomainException('Le taux de commission doit être compris entre 0 et 100 %.');
        }

        $this->tauxCommission = $tauxCommission;

        return $this;
    }

    public function getSeuilEcartAlerte(): int
    {
        return $this->seuilEcartAlerte;
    }

    public function setSeuilEcartAlerte(int $seuilEcartAlerte): self
    {
        $this->seuilEcartAlerte = max(0, $seuilEcartAlerte);

        return $this;
    }
}
