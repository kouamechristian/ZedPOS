<?php

namespace App\Entity;

use App\Entity\Trait\HorodatageCreation;
use App\Enum\GranulariteArrete;
use App\Enum\ModeRemuneration;
use App\Enum\StatutArrete;
use App\Enum\TraitementEcart;
use App\Repository\ArreteRepository;
use App\Service\Point\PeriodeArrete;
use App\Service\Point\ResultatPoint;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Le point fait avec le vendeur d'un stand sur une période : ce qui a été confié,
 * ce qui revient, ce qui est dû, ce qui a été remis.
 *
 * **La date de début n'est jamais saisie** : le service la fixe au lendemain du
 * dernier arrêté validé du stand (ou à sa première dotation) et la recalcule à
 * chaque enregistrement. Aucune journée n'est ainsi arrêtée deux fois ni oubliée.
 *
 * BROUILLON → VALIDE (montants figés, dotations et retours rattachés, **immuable**)
 * → ANNULE (seulement le dernier arrêté validé du stand, par une annulation tracée).
 *
 * Mode et taux de rémunération sont **copiés du stand à la validation** : la
 * dirigeante peut les changer ensuite sans réécrire les points passés.
 */
#[ORM\Entity(repositoryClass: ArreteRepository::class)]
#[ORM\Table(name: 'arrete')]
#[ORM\UniqueConstraint(name: 'uniq_arrete_numero', columns: ['numero'])]
#[ORM\Index(name: 'idx_arrete_stand_fin', columns: ['stand_id', 'date_fin'])]
class Arrete
{
    use HorodatageCreation;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** PTS-AAAAMMJJ-XXX, d'après le jour de création. */
    #[ORM\Column(length: 20)]
    private string $numero;

    #[ORM\ManyToOne(targetEntity: Emplacement::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Emplacement $stand;

    #[ORM\ManyToOne(targetEntity: Vendeur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Vendeur $vendeur;

    #[ORM\Column(length: 10, enumType: GranulariteArrete::class)]
    private GranulariteArrete $granularite;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dateDebut;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dateFin;

    #[ORM\Column(length: 20, enumType: StatutArrete::class)]
    private StatutArrete $statut = StatutArrete::BROUILLON;

    /** Figé à la validation ; en brouillon, reflète le stand. */
    #[ORM\Column(length: 12, enumType: ModeRemuneration::class)]
    private ModeRemuneration $modeRemuneration;

    /** Points de base, figé à la validation. */
    #[ORM\Column]
    private int $tauxCommission = 0;

    /** Centimes. Tous figés à la validation ; indicatifs en brouillon. */
    #[ORM\Column]
    private int $montantAttendu = 0;

    #[ORM\Column]
    private int $remunerationVendeur = 0;

    #[ORM\Column(name: 'net_a_remettre')]
    private int $netARemettre = 0;

    /** Espèces remises par le vendeur, en centimes. Nul tant qu'elles ne sont pas saisies. */
    #[ORM\Column(nullable: true)]
    private ?int $montantRemis = null;

    /** remis − net : positif = trop-perçu, négatif = manquant. */
    #[ORM\Column(nullable: true)]
    private ?int $ecart = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $commentaireEcart = null;

    /**
     * Sort d'un manquant (écart négatif), **choisi par la gérante** : dette du
     * vendeur ou perte justifiée. Nul s'il n'y a pas de manquant.
     */
    #[ORM\Column(length: 10, nullable: true, enumType: TraitementEcart::class)]
    private ?TraitementEcart $traitementEcart = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Utilisateur $createdBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateValidation = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $annuleAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motifAnnulation = null;

    /** @var Collection<int, BonDotation> */
    #[ORM\OneToMany(mappedBy: 'arrete', targetEntity: BonDotation::class)]
    #[ORM\OrderBy(['dateDotation' => 'ASC', 'id' => 'ASC'])]
    private Collection $bonsDotation;

    /** @var Collection<int, BonRetour> */
    #[ORM\OneToMany(mappedBy: 'arrete', targetEntity: BonRetour::class)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $bonsRetour;

    public function __construct(string $numero, Emplacement $stand, Vendeur $vendeur, \DateTimeImmutable $dateDebut, \DateTimeImmutable $dateFin, Utilisateur $createdBy)
    {
        if (!$stand->estStand()) {
            throw new \DomainException('Un arrêté porte sur un stand.');
        }

        $this->numero = $numero;
        $this->stand = $stand;
        $this->modeRemuneration = $stand->getModeRemuneration();
        $this->tauxCommission = $stand->getTauxCommission();
        $this->createdBy = $createdBy;
        $this->bonsDotation = new ArrayCollection();
        $this->bonsRetour = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->changerVendeur($vendeur);
        $this->definirPeriode($dateDebut, $dateFin);
    }

    // ------------------------------------------------------------- Brouillon

    /** Le début vient toujours du service ; la granularité se déduit des deux dates. */
    public function definirPeriode(\DateTimeImmutable $dateDebut, \DateTimeImmutable $dateFin): self
    {
        $this->garantirBrouillon();

        if ($dateFin->setTime(0, 0) < $dateDebut->setTime(0, 0)) {
            throw new \DomainException('La fin de la période précède son début.');
        }

        $this->dateDebut = $dateDebut->setTime(0, 0);
        $this->dateFin = $dateFin->setTime(0, 0);
        $this->granularite = PeriodeArrete::granularite($this->dateDebut, $this->dateFin);

        return $this;
    }

    public function changerVendeur(Vendeur $vendeur): self
    {
        $this->garantirBrouillon();
        if (!$vendeur->isActif()) {
            throw new \DomainException(\sprintf('Le vendeur « %s » est désactivé.', $vendeur->getNom()));
        }

        $this->vendeur = $vendeur;

        return $this;
    }

    /** @param ?int $montantRemis centimes */
    public function enregistrerSaisie(?int $montantRemis, ?string $commentaireEcart, ?TraitementEcart $traitementEcart = null): self
    {
        $this->garantirBrouillon();
        if (null !== $montantRemis && $montantRemis < 0) {
            throw new \DomainException('Les espèces remises ne peuvent pas être négatives.');
        }

        $commentaire = trim((string) $commentaireEcart);
        $this->montantRemis = $montantRemis;
        $this->commentaireEcart = '' !== $commentaire ? $commentaire : null;
        $this->traitementEcart = $traitementEcart;
        // Rémunération : le brouillon suit le stand jusqu'à la validation.
        $this->modeRemuneration = $this->stand->getModeRemuneration();
        $this->tauxCommission = $this->stand->getTauxCommission();

        return $this;
    }

    /** Montants du calcul courant, indicatifs tant que l'arrêté est un brouillon. */
    public function reporterCalcul(ResultatPoint $resultat): self
    {
        $this->garantirBrouillon();

        $this->montantAttendu = $resultat->montantAttendu;
        $this->remunerationVendeur = $resultat->remuneration;
        $this->netARemettre = $resultat->netARemettre;
        $this->ecart = $resultat->ecart;

        return $this;
    }

    // ------------------------------------------------------------ Validation

    /**
     * Contrôles de validation, sans rien modifier : espèces saisies, justification
     * au-delà du seuil d'écart du stand, sort du manquant choisi — et justifié s'il
     * passe en perte, quel que soit le seuil.
     *
     * @throws \DomainException
     */
    public function verifierValidable(ResultatPoint $resultat): void
    {
        $this->garantirBrouillon();

        if (null === $resultat->montantRemis) {
            throw new \DomainException('Saisissez les espèces remises par le vendeur, même zéro.');
        }

        $seuil = $this->stand->getSeuilEcartAlerte();
        if (abs((int) $resultat->ecart) > $seuil && null === $this->commentaireEcart) {
            throw new \DomainException(\sprintf(
                'Écart de %s FCFA, au-delà du seuil du stand (%s FCFA) : une justification est obligatoire.',
                number_format(intdiv((int) $resultat->ecart, 100), 0, ',', ' '),
                number_format(intdiv($seuil, 100), 0, ',', ' '),
            ));
        }

        if ((int) $resultat->ecart < 0) {
            if (null === $this->traitementEcart) {
                throw new \DomainException(\sprintf(
                    'Il manque %s FCFA : choisissez de les imputer en dette de %s ou de les passer en perte.',
                    number_format(intdiv(-(int) $resultat->ecart, 100), 0, ',', ' '),
                    $this->vendeur->getNom(),
                ));
            }
            if (TraitementEcart::PERTE === $this->traitementEcart && null === $this->commentaireEcart) {
                throw new \DomainException('Passer un manquant en perte exige une justification.');
            }
        }
    }

    public function valider(ResultatPoint $resultat, \DateTimeImmutable $le): self
    {
        $this->verifierValidable($resultat);

        $this->reporterCalcul($resultat);
        $this->montantRemis = $resultat->montantRemis;
        // Sans manquant, il n'y a rien à traiter : un choix resté d'une saisie
        // précédente ne doit pas voyager sur un point juste.
        if ((int) $resultat->ecart >= 0) {
            $this->traitementEcart = null;
        }
        $this->modeRemuneration = $this->stand->getModeRemuneration();
        $this->tauxCommission = $this->stand->getTauxCommission();
        $this->statut = StatutArrete::VALIDE;
        $this->dateValidation = $le;

        return $this;
    }

    // ------------------------------------------------------------ Annulation

    /**
     * Ce qui ne dépend que de l'arrêté. « Seulement le dernier du stand » demande la
     * base : c'est le service qui le vérifie.
     *
     * @throws \DomainException
     */
    public function verifierAnnulable(?string $motif): void
    {
        if (!$this->estValide()) {
            throw new \DomainException(\sprintf('L\'arrêté %s n\'est pas validé : il n\'y a rien à annuler.', $this->numero));
        }
        if ('' === trim((string) $motif)) {
            throw new \DomainException('Le motif d\'annulation est obligatoire.');
        }
    }

    public function annuler(string $motif, \DateTimeImmutable $le): self
    {
        $this->verifierAnnulable($motif);

        $this->statut = StatutArrete::ANNULE;
        $this->annuleAt = $le;
        $this->motifAnnulation = trim($motif);

        return $this;
    }

    public function garantirBrouillon(): void
    {
        if (StatutArrete::BROUILLON !== $this->statut) {
            throw new \DomainException(\sprintf(
                'L\'arrêté %s est %s : il ne se modifie plus.',
                $this->numero,
                mb_strtolower($this->statut->libelle()),
            ));
        }
    }

    // --------------------------------------------------------------- Lecture

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

    public function getGranularite(): GranulariteArrete
    {
        return $this->granularite;
    }

    public function getDateDebut(): \DateTimeImmutable
    {
        return $this->dateDebut;
    }

    public function getDateFin(): \DateTimeImmutable
    {
        return $this->dateFin;
    }

    public function getStatut(): StatutArrete
    {
        return $this->statut;
    }

    public function estBrouillon(): bool
    {
        return StatutArrete::BROUILLON === $this->statut;
    }

    public function estValide(): bool
    {
        return StatutArrete::VALIDE === $this->statut;
    }

    public function estAnnule(): bool
    {
        return StatutArrete::ANNULE === $this->statut;
    }

    public function getModeRemuneration(): ModeRemuneration
    {
        return $this->modeRemuneration;
    }

    public function getTauxCommission(): int
    {
        return $this->tauxCommission;
    }

    public function getMontantAttendu(): int
    {
        return $this->montantAttendu;
    }

    public function getRemunerationVendeur(): int
    {
        return $this->remunerationVendeur;
    }

    public function getNetARemettre(): int
    {
        return $this->netARemettre;
    }

    public function getMontantRemis(): ?int
    {
        return $this->montantRemis;
    }

    public function getEcart(): ?int
    {
        return $this->ecart;
    }

    public function getCommentaireEcart(): ?string
    {
        return $this->commentaireEcart;
    }

    public function getTraitementEcart(): ?TraitementEcart
    {
        return $this->traitementEcart;
    }

    public function getCreatedBy(): Utilisateur
    {
        return $this->createdBy;
    }

    public function getDateValidation(): ?\DateTimeImmutable
    {
        return $this->dateValidation;
    }

    public function getAnnuleAt(): ?\DateTimeImmutable
    {
        return $this->annuleAt;
    }

    public function getMotifAnnulation(): ?string
    {
        return $this->motifAnnulation;
    }

    /** @internal Tenue par {@see BonDotation::rattacherArrete()} et {@see BonDotation::detacherArrete()}. */
    public function suivreBonDotation(BonDotation $bon, bool $rattache): void
    {
        if (!$rattache) {
            $this->bonsDotation->removeElement($bon);
        } elseif (!$this->bonsDotation->contains($bon)) {
            $this->bonsDotation->add($bon);
        }
    }

    /** @internal Tenue par {@see BonRetour::rattacherArrete()}. */
    public function suivreBonRetour(BonRetour $bon): void
    {
        if (!$this->bonsRetour->contains($bon)) {
            $this->bonsRetour->add($bon);
        }
    }

    /** @return Collection<int, BonDotation> */
    public function getBonsDotation(): Collection
    {
        return $this->bonsDotation;
    }

    /** @return Collection<int, BonRetour> */
    public function getBonsRetour(): Collection
    {
        return $this->bonsRetour;
    }
}
