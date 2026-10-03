<?php

namespace App\Entity\Magasin;

use App\Entity\Trait\HorodatageCreation;
use App\Entity\Utilisateur;
use App\Enum\Magasin\StatutInventaireMagasin;
use App\Repository\Magasin\MagasinInventaireRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Feuille d'inventaire du magasin — tout le magasin (emplacement nul) ou un seul
 * emplacement.
 *
 * **En cours → validée, ou abandonnée.** À l'ouverture, la feuille fige le
 * théorique de chaque ligne ; on y saisit le compté, autant de fois qu'il faut.
 * La validation est la seule étape qui touche au stock : l'**écart** de chaque
 * ligne comptée devient un mouvement AJUSTEMENT_INVENTAIRE. Une feuille validée
 * ou abandonnée ne se modifie plus (`garantirEnCours()`).
 *
 * Règles de validation, portées ici et levées **avant toute écriture** :
 * - une ligne non comptée est ignorée — une feuille rendue à moitié remplie ne
 *   met pas à zéro ce qu'on n'a pas eu le temps de relever ;
 * - au moins une ligne comptée ;
 * - un écart exige un commentaire, et la validation revient alors à la
 *   dirigeante (MAGASIN_VALIDER_ECART) : un inventaire qui corrige le stock est
 *   ce qu'on vient relire en cas de doute.
 */
#[ORM\Entity(repositoryClass: MagasinInventaireRepository::class)]
#[ORM\Table(name: 'magasin_inventaire')]
#[ORM\UniqueConstraint(name: 'uniq_magasin_inventaire_numero', columns: ['numero'])]
class MagasinInventaire
{
    use HorodatageCreation;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** INV-2026-0001 : séquence par année. */
    #[ORM\Column(length: 20)]
    private string $numero;

    /** Nul : tout le magasin. */
    #[ORM\ManyToOne(targetEntity: MagasinEmplacement::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?MagasinEmplacement $emplacement;

    #[ORM\Column(length: 20, enumType: StatutInventaireMagasin::class)]
    private StatutInventaireMagasin $statut = StatutInventaireMagasin::EN_COURS;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Utilisateur $ouvertPar;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $commentaire = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Utilisateur $validePar = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $valideAt = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Utilisateur $abandonnePar = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $abandonneAt = null;

    /** @var Collection<int, MagasinLigneInventaire> */
    #[ORM\OneToMany(mappedBy: 'inventaire', targetEntity: MagasinLigneInventaire::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lignes;

    public function __construct(string $numero, ?MagasinEmplacement $emplacement, Utilisateur $ouvertPar)
    {
        $this->numero = $numero;
        $this->emplacement = $emplacement;
        $this->ouvertPar = $ouvertPar;
        $this->createdAt = new \DateTimeImmutable();
        $this->lignes = new ArrayCollection();
    }

    /** @internal à l'ouverture seulement, par le service */
    public function ajouterLigne(MagasinProduit $produit, MagasinEmplacement $emplacement, int $qteTheorique): MagasinLigneInventaire
    {
        $this->garantirEnCours();
        $ligne = new MagasinLigneInventaire($this, $produit, $emplacement, $qteTheorique);
        $this->lignes->add($ligne);

        return $ligne;
    }

    /**
     * Enregistre le comptage. Une ligne absente du tableau garde ce qu'elle avait.
     *
     * @param array<int, array{quantite: ?int, enUniteAchat: bool}> $comptages par id de ligne
     *
     * @throws \DomainException
     */
    public function compter(array $comptages, ?string $commentaire): self
    {
        $this->garantirEnCours();
        foreach ($this->lignes as $ligne) {
            if (\array_key_exists((int) $ligne->getId(), $comptages)) {
                $c = $comptages[(int) $ligne->getId()];
                $ligne->compter($c['quantite'], $c['enUniteAchat']);
            }
        }
        $this->commentaire = self::texte($commentaire);

        return $this;
    }

    /**
     * @throws \DomainException
     */
    public function verifierValidable(bool $peutValiderUnEcart): void
    {
        $this->garantirEnCours();
        if ([] === $this->lignesComptees()) {
            throw new \DomainException('Aucune ligne n\'est comptée : rien à valider.');
        }
        if ($this->aDesEcarts()) {
            if (null === $this->commentaire) {
                throw new \DomainException('Un écart est constaté : expliquez-le dans le commentaire.');
            }
            if (!$peutValiderUnEcart) {
                throw new \DomainException('Un écart est constaté : la validation revient à la dirigeante. Le comptage est enregistré.');
            }
        }
    }

    public function valider(bool $peutValiderUnEcart, Utilisateur $par, \DateTimeImmutable $le): self
    {
        $this->verifierValidable($peutValiderUnEcart);
        $this->statut = StatutInventaireMagasin::VALIDE;
        $this->validePar = $par;
        $this->valideAt = $le;

        return $this;
    }

    public function abandonner(Utilisateur $par, \DateTimeImmutable $le): self
    {
        $this->garantirEnCours();
        $this->statut = StatutInventaireMagasin::ABANDONNE;
        $this->abandonnePar = $par;
        $this->abandonneAt = $le;

        return $this;
    }

    /** @return list<MagasinLigneInventaire> */
    public function lignesComptees(): array
    {
        return array_values($this->lignes->filter(static fn (MagasinLigneInventaire $l): bool => $l->estComptee())->toArray());
    }

    /** @return list<MagasinLigneInventaire> les lignes comptées dont le compté diffère du théorique */
    public function lignesEnEcart(): array
    {
        return array_values(array_filter($this->lignesComptees(), static fn (MagasinLigneInventaire $l): bool => 0 !== $l->getEcart()));
    }

    public function aDesEcarts(): bool
    {
        return [] !== $this->lignesEnEcart();
    }

    /** Somme des écarts valorisés, en centimes. */
    public function getValeurEcarts(): int
    {
        return array_sum(array_map(static fn (MagasinLigneInventaire $l): int => (int) $l->getValeurEcart(), $this->lignesComptees()));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumero(): string
    {
        return $this->numero;
    }

    public function getEmplacement(): ?MagasinEmplacement
    {
        return $this->emplacement;
    }

    public function getStatut(): StatutInventaireMagasin
    {
        return $this->statut;
    }

    public function estEnCours(): bool
    {
        return StatutInventaireMagasin::EN_COURS === $this->statut;
    }

    public function getOuvertPar(): Utilisateur
    {
        return $this->ouvertPar;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function getValidePar(): ?Utilisateur
    {
        return $this->validePar;
    }

    public function getValideAt(): ?\DateTimeImmutable
    {
        return $this->valideAt;
    }

    public function getAbandonnePar(): ?Utilisateur
    {
        return $this->abandonnePar;
    }

    public function getAbandonneAt(): ?\DateTimeImmutable
    {
        return $this->abandonneAt;
    }

    /** @return Collection<int, MagasinLigneInventaire> */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    /** « Tout le magasin » ou le libellé de l'emplacement. */
    public function getPortee(): string
    {
        return $this->emplacement?->getLibelle() ?? 'Tout le magasin';
    }

    private function garantirEnCours(): void
    {
        if (!$this->estEnCours()) {
            throw new \DomainException(\sprintf('L\'inventaire %s est %s : il ne se modifie plus.', $this->numero, mb_strtolower($this->statut->libelle())));
        }
    }

    private static function texte(?string $texte): ?string
    {
        $texte = null !== $texte ? trim($texte) : '';

        return '' !== $texte ? $texte : null;
    }
}
