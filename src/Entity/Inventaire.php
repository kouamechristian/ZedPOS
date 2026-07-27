<?php

namespace App\Entity;

use App\Entity\Trait\HorodatageCreation;
use App\Enum\StatutInventaire;
use App\Repository\InventaireRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Feuille de comptage : l'état physique du stock, relevé à un instant donné.
 *
 * Ouverte, elle fige la quantité **théorique** de chaque article et matière — ce
 * qui sera imprimé sur la feuille. Validée, elle applique les écarts constatés en
 * `MouvementStock` de type `INVENTAIRE` : le stock corrigé et l'historique des
 * mouvements restent ainsi cohérents, ce qui n'était pas le cas quand on
 * modifiait `stockActuel` à la main.
 *
 * **Une feuille validée ne se modifie plus** ({@see self::garantirEnCours()}),
 * même règle qu'une session de caisse clôturée : elle a produit des écritures de
 * stock, revenir dessus les rendrait fausses.
 */
#[ORM\Entity(repositoryClass: InventaireRepository::class)]
#[ORM\Table(name: 'inventaire')]
#[ORM\Index(name: 'idx_inventaire_created_at', columns: ['created_at'])]
class Inventaire
{
    use HorodatageCreation;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: StatutInventaire::class)]
    private StatutInventaire $statut = StatutInventaire::EN_COURS;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Utilisateur $auteur;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Utilisateur $validePar = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $valideAt = null;

    /** Obligatoire à la validation dès qu'un écart est constaté. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $commentaire = null;

    /** @var Collection<int, LigneInventaire> */
    #[ORM\OneToMany(mappedBy: 'inventaire', targetEntity: LigneInventaire::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $lignes;

    public function __construct(Utilisateur $auteur)
    {
        $this->auteur = $auteur;
        $this->lignes = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStatut(): StatutInventaire
    {
        return $this->statut;
    }

    public function estEnCours(): bool
    {
        return StatutInventaire::EN_COURS === $this->statut;
    }

    public function getAuteur(): Utilisateur
    {
        return $this->auteur;
    }

    public function getValidePar(): ?Utilisateur
    {
        return $this->validePar;
    }

    public function getValideAt(): ?\DateTimeImmutable
    {
        return $this->valideAt;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    /** @return Collection<int, LigneInventaire> */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    public function ajouterLigne(LigneInventaire $ligne): void
    {
        $this->garantirEnCours();
        $this->lignes->add($ligne);
    }

    /**
     * Lignes effectivement comptées. Les autres sont **ignorées** à la validation :
     * une feuille rendue à moitié remplie ne doit pas mettre à zéro ce qui n'a pas
     * été compté — ce serait la pire façon de « corriger » un stock.
     *
     * @return list<LigneInventaire>
     */
    public function lignesComptees(): array
    {
        return array_values(array_filter(
            $this->lignes->toArray(),
            static fn (LigneInventaire $ligne): bool => $ligne->estComptee(),
        ));
    }

    /**
     * Lignes comptées dont le comptage diffère du théorique — celles qui vont
     * produire un mouvement de stock.
     *
     * @return list<LigneInventaire>
     */
    public function lignesAvecEcart(): array
    {
        return array_values(array_filter(
            $this->lignesComptees(),
            static fn (LigneInventaire $ligne): bool => 0 !== $ligne->ecart(),
        ));
    }

    /**
     * Somme des écarts valorisés, en centimes. Négative quand il manque de la
     * marchandise — c'est le chiffre que le gérant regarde en premier.
     */
    public function ecartValorise(): int
    {
        $total = 0;
        foreach ($this->lignesComptees() as $ligne) {
            $total += $ligne->ecartValorise();
        }

        return $total;
    }

    /**
     * Valide la feuille. L'application des écarts au stock appartient à
     * {@see \App\Service\InventaireService} ; ce qui est ici est ce qui ne doit
     * dépendre d'aucun appelant.
     *
     * @throws \DomainException si la feuille est déjà validée, ou si un écart est
     *                          constaté sans explication
     */
    public function valider(Utilisateur $validePar, ?string $commentaire = null): void
    {
        $this->garantirEnCours();

        if ([] === $this->lignesComptees()) {
            throw new \DomainException('Aucune quantité n\'a été comptée : il n\'y a rien à valider.');
        }

        $commentaire = trim((string) $commentaire);

        // Même règle que la clôture de caisse : un écart sans explication n'a
        // aucune valeur trois mois plus tard, quand on cherche ce qui s'est passé.
        if ('' === $commentaire && [] !== $this->lignesAvecEcart()) {
            throw new \DomainException('Un écart est constaté : un commentaire est obligatoire.');
        }

        $this->statut = StatutInventaire::VALIDE;
        $this->validePar = $validePar;
        $this->valideAt = new \DateTimeImmutable();
        $this->commentaire = '' !== $commentaire ? $commentaire : null;
    }

    /**
     * Barrière d'immuabilité, appelée par tout ce qui écrit sur la feuille ou ses
     * lignes. Applicative : elle ne couvre pas un UPDATE SQL direct.
     */
    public function garantirEnCours(): void
    {
        if (!$this->estEnCours()) {
            throw new \DomainException(\sprintf(
                'L\'inventaire du %s est validé : il ne peut plus être modifié.',
                $this->createdAt->format('d/m/Y'),
            ));
        }
    }
}
