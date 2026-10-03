<?php

namespace App\Entity\Magasin;

use App\Entity\Trait\HorodatageCreation;
use App\Entity\Utilisateur;
use App\Enum\Magasin\DestinationSortieMagasin;
use App\Enum\Magasin\MotifSortieMagasin;
use App\Enum\Magasin\StatutSortieMagasin;
use App\Repository\Magasin\MagasinSortieRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un bon de sortie du magasin : ce qui part vers l'atelier, la cuisine, la
 * boutique, et pourquoi.
 *
 * **Brouillon → validée → annulée.** Le brouillon se corrige librement et ne
 * touche pas au stock. La validation fait sortir les quantités (mouvements
 * SORTIE) et fige le bon : il ne se modifie plus. Une sortie validée erronée
 * s'**annule**, et le service contre-passe ses mouvements — l'historique reste.
 *
 * « Demandé par » est un texte libre : le boulanger qui vient chercher ses sacs
 * n'a pas forcément de compte. « Servi par » est l'utilisateur qui valide — celui
 * dont la signature engage la sortie.
 */
#[ORM\Entity(repositoryClass: MagasinSortieRepository::class)]
#[ORM\Table(name: 'magasin_sortie')]
#[ORM\UniqueConstraint(name: 'uniq_magasin_sortie_numero', columns: ['numero'])]
class MagasinSortie
{
    use HorodatageCreation;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** SOR-2026-0001 : séquence par année. */
    #[ORM\Column(length: 20)]
    private string $numero;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dateSortie;

    #[ORM\Column(length: 20, enumType: DestinationSortieMagasin::class)]
    private DestinationSortieMagasin $destination;

    #[ORM\Column(length: 20, enumType: MotifSortieMagasin::class)]
    private MotifSortieMagasin $motif;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $demandePar = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $commentaire = null;

    #[ORM\Column(length: 20, enumType: StatutSortieMagasin::class)]
    private StatutSortieMagasin $statut = StatutSortieMagasin::BROUILLON;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Utilisateur $creePar;

    /** Servi par : l'utilisateur qui a validé la sortie. */
    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Utilisateur $serviePar = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $valideeAt = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Utilisateur $annuleePar = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $annuleeAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motifAnnulation = null;

    /** Le statut avant l'annulation : seule une sortie validée a contre-passé du stock. */
    #[ORM\Column(length: 20, nullable: true, enumType: StatutSortieMagasin::class)]
    private ?StatutSortieMagasin $statutAvantAnnulation = null;

    /** @var Collection<int, MagasinLigneSortie> */
    #[ORM\OneToMany(mappedBy: 'sortie', targetEntity: MagasinLigneSortie::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lignes;

    public function __construct(string $numero, \DateTimeImmutable $dateSortie, DestinationSortieMagasin $destination, MotifSortieMagasin $motif, Utilisateur $creePar)
    {
        $this->numero = $numero;
        $this->dateSortie = $dateSortie;
        $this->destination = $destination;
        $this->motif = $motif;
        $this->creePar = $creePar;
        $this->createdAt = new \DateTimeImmutable();
        $this->lignes = new ArrayCollection();
    }

    // ---------------------------------------------------------------- Écritures

    /**
     * Le contenu du brouillon, remplacé d'un bloc : en-tête et lignes.
     *
     * @param list<array{produit: MagasinProduit, emplacement: MagasinEmplacement, quantite: int, enUniteAchat: bool}> $lignes
     *        quantité en millièmes de l'unité choisie
     *
     * @throws \DomainException
     */
    public function definir(\DateTimeImmutable $date, DestinationSortieMagasin $destination, MotifSortieMagasin $motif, ?string $demandePar, ?string $commentaire, array $lignes): self
    {
        $this->garantirBrouillon();
        if ([] === $lignes) {
            throw new \DomainException('La sortie est vide : ajoutez au moins un produit.');
        }

        $vus = [];
        $nouvelles = [];
        foreach ($lignes as $saisie) {
            $cle = $saisie['produit']->getId().'-'.$saisie['emplacement']->getId();
            if (isset($vus[$cle])) {
                throw new \DomainException(\sprintf('« %s » figure deux fois pour « %s » : regroupez les lignes.', $saisie['produit']->getNom(), $saisie['emplacement']->getLibelle()));
            }
            $vus[$cle] = true;
            $nouvelles[] = new MagasinLigneSortie($this, $saisie['produit'], $saisie['emplacement'], $saisie['quantite'], $saisie['enUniteAchat']);
        }

        $this->dateSortie = $date;
        $this->destination = $destination;
        $this->motif = $motif;
        $this->demandePar = self::texte($demandePar);
        $this->commentaire = self::texte($commentaire);
        $this->lignes->clear();
        foreach ($nouvelles as $ligne) {
            $this->lignes->add($ligne);
        }

        return $this;
    }

    /**
     * Fige le bon. Le service fait sortir le stock dans la même transaction.
     *
     * @param array<int, int> $couts coût moyen par id de produit, centimes par unité de stock
     *
     * @throws \DomainException
     */
    public function valider(array $couts, Utilisateur $par, \DateTimeImmutable $le): self
    {
        $this->garantirBrouillon();
        if ($this->lignes->isEmpty()) {
            throw new \DomainException('La sortie est vide : rien à valider.');
        }
        foreach ($this->lignes as $ligne) {
            $ligne->figerCout($couts[(int) $ligne->getProduit()->getId()] ?? $ligne->getProduit()->getCoutMoyen());
        }

        $this->statut = StatutSortieMagasin::VALIDEE;
        $this->serviePar = $par;
        $this->valideeAt = $le;

        return $this;
    }

    /** @throws \DomainException */
    public function verifierAnnulable(?string $motif): void
    {
        if ($this->estAnnulee()) {
            throw new \DomainException(\sprintf('La sortie %s est déjà annulée.', $this->numero));
        }
        if (null === self::texte($motif)) {
            throw new \DomainException('Le motif d\'annulation est obligatoire.');
        }
    }

    public function annuler(?string $motif, Utilisateur $par, \DateTimeImmutable $le): self
    {
        $this->verifierAnnulable($motif);

        $this->statutAvantAnnulation = $this->statut;
        $this->statut = StatutSortieMagasin::ANNULEE;
        $this->motifAnnulation = self::texte($motif);
        $this->annuleePar = $par;
        $this->annuleeAt = $le;

        return $this;
    }

    // ---------------------------------------------------------------- Lecture

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumero(): string
    {
        return $this->numero;
    }

    public function getDateSortie(): \DateTimeImmutable
    {
        return $this->dateSortie;
    }

    public function getDestination(): DestinationSortieMagasin
    {
        return $this->destination;
    }

    public function getMotif(): MotifSortieMagasin
    {
        return $this->motif;
    }

    public function getDemandePar(): ?string
    {
        return $this->demandePar;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function getStatut(): StatutSortieMagasin
    {
        return $this->statut;
    }

    public function estBrouillon(): bool
    {
        return StatutSortieMagasin::BROUILLON === $this->statut;
    }

    public function estValidee(): bool
    {
        return StatutSortieMagasin::VALIDEE === $this->statut;
    }

    public function estAnnulee(): bool
    {
        return StatutSortieMagasin::ANNULEE === $this->statut;
    }

    /** Validée, même si annulée depuis : son stock est sorti puis est revenu. */
    public function aEteValidee(): bool
    {
        return null !== $this->valideeAt;
    }

    public function getCreePar(): Utilisateur
    {
        return $this->creePar;
    }

    public function getServiePar(): ?Utilisateur
    {
        return $this->serviePar;
    }

    public function getValideeAt(): ?\DateTimeImmutable
    {
        return $this->valideeAt;
    }

    public function getAnnuleePar(): ?Utilisateur
    {
        return $this->annuleePar;
    }

    public function getAnnuleeAt(): ?\DateTimeImmutable
    {
        return $this->annuleeAt;
    }

    public function getMotifAnnulation(): ?string
    {
        return $this->motifAnnulation;
    }

    public function getStatutAvantAnnulation(): ?StatutSortieMagasin
    {
        return $this->statutAvantAnnulation;
    }

    /** @return Collection<int, MagasinLigneSortie> */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    /** Valeur totale au coût figé, en centimes ; nulle avant la validation. */
    public function getValeur(): ?int
    {
        $total = null;
        foreach ($this->lignes as $ligne) {
            if (null !== $ligne->getValeur()) {
                $total = ($total ?? 0) + $ligne->getValeur();
            }
        }

        return $total;
    }

    private function garantirBrouillon(): void
    {
        if (!$this->estBrouillon()) {
            throw new \DomainException(\sprintf('La sortie %s est %s : elle ne se modifie plus.', $this->numero, mb_strtolower($this->statut->libelle())));
        }
    }

    private static function texte(?string $texte): ?string
    {
        $texte = null !== $texte ? trim($texte) : '';

        return '' !== $texte ? $texte : null;
    }
}
