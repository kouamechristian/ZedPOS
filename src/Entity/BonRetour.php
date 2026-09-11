<?php

namespace App\Entity;

use App\Entity\Trait\HorodatageCreation;
use App\Enum\MotifRetour;
use App\Enum\StatutBonRetour;
use App\Repository\BonRetourRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ce qui revient d'un stand au point : invendus rapportés au dépôt, casse, périmé.
 *
 * Né de la saisie du point. Tant que l'arrêté est un brouillon, le bon l'est aussi
 * et **ne touche pas au stock** ; il est écrit — RETOUR pour les invendus, PERTE
 * pour le reste — à la validation de l'arrêté, dans la même transaction.
 */
#[ORM\Entity(repositoryClass: BonRetourRepository::class)]
#[ORM\Table(name: 'bon_retour')]
#[ORM\UniqueConstraint(name: 'uniq_bon_retour_numero', columns: ['numero'])]
#[ORM\Index(name: 'idx_bon_retour_date', columns: ['date_retour'])]
class BonRetour
{
    use HorodatageCreation;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** RET-AAAAMMJJ-XXX, d'après la date de retour. */
    #[ORM\Column(length: 20)]
    private string $numero;

    #[ORM\ManyToOne(targetEntity: Emplacement::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Emplacement $stand;

    #[ORM\ManyToOne(targetEntity: Vendeur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Vendeur $vendeur;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dateRetour;

    #[ORM\Column(length: 20, enumType: StatutBonRetour::class)]
    private StatutBonRetour $statut = StatutBonRetour::BROUILLON;

    #[ORM\ManyToOne(targetEntity: Arrete::class, inversedBy: 'bonsRetour')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Arrete $arrete = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Utilisateur $createdBy;

    /** @var Collection<int, LigneRetour> */
    #[ORM\OneToMany(mappedBy: 'bon', targetEntity: LigneRetour::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lignes;

    public function __construct(string $numero, Emplacement $stand, Vendeur $vendeur, \DateTimeImmutable $dateRetour, Utilisateur $createdBy)
    {
        $this->numero = $numero;
        $this->stand = $stand;
        $this->vendeur = $vendeur;
        $this->dateRetour = $dateRetour->setTime(0, 0);
        $this->createdBy = $createdBy;
        $this->lignes = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * Remplace les lignes. Mises à jour **en place** par produit × motif : Doctrine
     * insère avant de supprimer, et la contrainte d'unicité tomberait sur une ligne
     * qui ne change que de quantité.
     *
     * @param list<array{0: Article, 1: int, 2: MotifRetour}> $lignes produit, millièmes, motif
     */
    public function definirLignes(array $lignes, Vendeur $vendeur, \DateTimeImmutable $dateRetour): self
    {
        $this->garantirBrouillon();
        $this->vendeur = $vendeur;
        $this->dateRetour = $dateRetour->setTime(0, 0);

        $voulues = [];
        foreach ($lignes as [$produit, $quantite, $motif]) {
            if ($quantite > 0) {
                $voulues[$produit->getId().'|'.$motif->value] = [$produit, $quantite, $motif];
            }
        }

        foreach ($this->lignes->toArray() as $ligne) {
            $cle = $ligne->getProduit()->getId().'|'.$ligne->getMotif()->value;
            if (isset($voulues[$cle])) {
                $ligne->modifierQuantite($voulues[$cle][1]);
                unset($voulues[$cle]);
            } else {
                $this->lignes->removeElement($ligne);
            }
        }

        foreach ($voulues as [$produit, $quantite, $motif]) {
            $this->lignes->add(new LigneRetour($this, $produit, $quantite, $motif));
        }

        return $this;
    }

    /** @internal Réservé à l'arrêté : le brouillon suit son arrêté. */
    public function rattacherArrete(Arrete $arrete): self
    {
        if ($arrete->getStand() !== $this->stand) {
            throw new \DomainException('Le retour et l\'arrêté ne portent pas sur le même stand.');
        }

        $this->arrete = $arrete;
        $arrete->suivreBonRetour($this);

        return $this;
    }

    public function valider(): self
    {
        $this->garantirBrouillon();
        $this->statut = StatutBonRetour::VALIDE;

        return $this;
    }

    public function annuler(): self
    {
        if (StatutBonRetour::VALIDE !== $this->statut) {
            throw new \DomainException(\sprintf('Le bon de retour %s n\'est pas validé.', $this->numero));
        }

        $this->statut = StatutBonRetour::ANNULE;

        return $this;
    }

    public function garantirBrouillon(): void
    {
        if (StatutBonRetour::BROUILLON !== $this->statut) {
            throw new \DomainException(\sprintf('Le bon de retour %s est écrit : il ne se modifie plus.', $this->numero));
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

    public function getStand(): Emplacement
    {
        return $this->stand;
    }

    public function getVendeur(): Vendeur
    {
        return $this->vendeur;
    }

    public function getDateRetour(): \DateTimeImmutable
    {
        return $this->dateRetour;
    }

    public function getStatut(): StatutBonRetour
    {
        return $this->statut;
    }

    public function getArrete(): ?Arrete
    {
        return $this->arrete;
    }

    public function getCreatedBy(): Utilisateur
    {
        return $this->createdBy;
    }

    /** @return Collection<int, LigneRetour> */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }
}
