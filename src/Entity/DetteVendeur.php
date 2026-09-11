<?php

namespace App\Entity;

use App\Entity\Trait\HorodatageCreation;
use App\Enum\ModeReglement;
use App\Enum\StatutDette;
use App\Enum\TypeDette;
use App\Repository\DetteVendeurRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ce qu'un vendeur doit à la boutique.
 *
 * **Jamais créée en silence.** Un manquant au point ne devient une dette que si la
 * gérante l'a choisi à la validation ({@see \App\Enum\TraitementEcart::DETTE}) ;
 * une avance ou une autre dette se saisit à la main, commentaire obligatoire.
 *
 * Le montant ne bouge plus ; seul `montantRembourse` avance, par des
 * {@see RemboursementDette}. Une dette ne se supprime pas : celle d'un point annulé
 * passe ANNULEE, et seulement si rien n'a encore été remboursé dessus.
 *
 * Le champ « tournée » du cahier des charges est l'**arrêté** : la tournée n'existe
 * plus, c'est le point validé qui constate le manquant.
 */
#[ORM\Entity(repositoryClass: DetteVendeurRepository::class)]
#[ORM\Table(name: 'dette_vendeur')]
#[ORM\Index(name: 'idx_dette_vendeur_statut', columns: ['vendeur_id', 'statut'])]
class DetteVendeur
{
    use HorodatageCreation;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Vendeur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Vendeur $vendeur;

    /** Le point qui a constaté le manquant ; nul pour une avance ou une autre dette. */
    #[ORM\ManyToOne(targetEntity: Arrete::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Arrete $arrete;

    /** Centimes, strictement positif, fixé à la création. */
    #[ORM\Column]
    private int $montant;

    #[ORM\Column(length: 15, enumType: TypeDette::class)]
    private TypeDette $type;

    #[ORM\Column(length: 12, enumType: StatutDette::class)]
    private StatutDette $statut = StatutDette::OUVERTE;

    /** Centimes, entre 0 et le montant. */
    #[ORM\Column]
    private int $montantRembourse = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $commentaire;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Utilisateur $createdBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $annuleAt = null;

    /** @var Collection<int, RemboursementDette> */
    #[ORM\OneToMany(mappedBy: 'dette', targetEntity: RemboursementDette::class, cascade: ['persist'])]
    #[ORM\OrderBy(['date' => 'ASC', 'id' => 'ASC'])]
    private Collection $remboursements;

    /** @param int $montant centimes */
    public function __construct(Vendeur $vendeur, TypeDette $type, int $montant, ?string $commentaire, Utilisateur $createdBy, ?Arrete $arrete = null)
    {
        if ($montant <= 0) {
            throw new \DomainException('Le montant d\'une dette doit être strictement positif.');
        }

        $commentaire = trim((string) $commentaire);
        $commentaire = '' !== $commentaire ? $commentaire : null;

        if (TypeDette::ECART_CAISSE === $type) {
            if (null === $arrete || $arrete->getVendeur() !== $vendeur) {
                throw new \DomainException('Un manquant au point se rattache au point du vendeur qui l\'a constaté.');
            }
        } else {
            if (null !== $arrete) {
                throw new \DomainException('Seul un manquant au point se rattache à un point.');
            }
            if (null === $commentaire) {
                throw new \DomainException(\sprintf('Précisez l\'objet de la dette (%s) : le commentaire est obligatoire.', mb_strtolower($type->libelle())));
            }
        }

        $this->vendeur = $vendeur;
        $this->type = $type;
        $this->montant = $montant;
        $this->commentaire = $commentaire;
        $this->createdBy = $createdBy;
        $this->arrete = $arrete;
        $this->remboursements = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * Encaisse une partie ou la totalité du reste dû.
     *
     * @param int $montant centimes
     *
     * @throws \DomainException dette non due, montant nul ou au-delà du reste, moyen à crédit
     */
    public function encaisser(int $montant, ModeReglement $moyen, \DateTimeImmutable $date, Utilisateur $encaissePar): RemboursementDette
    {
        if (!$this->statut->estDue()) {
            throw new \DomainException(\sprintf('Cette dette est %s : elle ne se rembourse plus.', mb_strtolower($this->statut->libelle())));
        }
        if ($montant <= 0) {
            throw new \DomainException('Le remboursement doit être strictement positif.');
        }
        if ($montant > $this->reste()) {
            throw new \DomainException(\sprintf('Le remboursement dépasse le reste dû (%s FCFA).', number_format(intdiv($this->reste(), 100), 0, ',', ' ')));
        }

        $remboursement = new RemboursementDette($this, $montant, $date, $encaissePar, $moyen);
        $this->remboursements->add($remboursement);
        $this->montantRembourse += $montant;
        $this->statut = $this->montantRembourse === $this->montant ? StatutDette::SOLDEE : StatutDette::PARTIELLE;

        return $remboursement;
    }

    /**
     * La dette d'un point annulé disparaît du solde — pas de la base.
     *
     * @throws \DomainException déjà remboursée en partie, déjà soldée ou annulée
     */
    public function annuler(\DateTimeImmutable $le): void
    {
        if (StatutDette::OUVERTE !== $this->statut || $this->montantRembourse > 0) {
            throw new \DomainException(\sprintf(
                'La dette de %s FCFA a déjà reçu %s FCFA de remboursement : elle ne s\'annule plus.',
                number_format(intdiv($this->montant, 100), 0, ',', ' '),
                number_format(intdiv($this->montantRembourse, 100), 0, ',', ' '),
            ));
        }

        $this->statut = StatutDette::ANNULEE;
        $this->annuleAt = $le;
    }

    public function reste(): int
    {
        return $this->statut->estDue() ? $this->montant - $this->montantRembourse : 0;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVendeur(): Vendeur
    {
        return $this->vendeur;
    }

    public function getArrete(): ?Arrete
    {
        return $this->arrete;
    }

    public function getMontant(): int
    {
        return $this->montant;
    }

    public function getType(): TypeDette
    {
        return $this->type;
    }

    public function getStatut(): StatutDette
    {
        return $this->statut;
    }

    public function estDue(): bool
    {
        return $this->statut->estDue();
    }

    public function getMontantRembourse(): int
    {
        return $this->montantRembourse;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function getCreatedBy(): Utilisateur
    {
        return $this->createdBy;
    }

    public function getAnnuleAt(): ?\DateTimeImmutable
    {
        return $this->annuleAt;
    }

    /** @return Collection<int, RemboursementDette> */
    public function getRemboursements(): Collection
    {
        return $this->remboursements;
    }
}
