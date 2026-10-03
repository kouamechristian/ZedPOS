<?php

namespace App\Entity\Magasin;

use App\Entity\Fournisseur;
use App\Entity\Trait\HorodatageCreation;
use App\Entity\Utilisateur;
use App\Enum\Magasin\MotifRejetMagasin;
use App\Enum\Magasin\StatutReceptionMagasin;
use App\Repository\Magasin\MagasinReceptionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une livraison fournisseur, suivie à travers les quatre premières étapes du
 * plan de traitement du magasin :
 *
 *     1. Réception   — ce qu'annonce le bon de livraison ;
 *     2. Contrôle    — ce qui est compté, comparé à l'annonce ;
 *     3. Inspection  — accepté / rejeté, avec un motif ;
 *     4. Stockage    — rangement de l'accepté : **seule étape qui fait entrer du stock**.
 *
 * Les règles d'enchaînement vivent ici, et chaque étape note qui l'a faite et
 * quand. On ne saute pas d'étape ; une étape se reprend tant que la suivante n'a
 * pas eu lieu, jamais après. Une réception stockée ne se modifie plus : elle
 * s'annule, et le service contre-passe ses mouvements.
 */
#[ORM\Entity(repositoryClass: MagasinReceptionRepository::class)]
#[ORM\Table(name: 'magasin_reception')]
#[ORM\UniqueConstraint(name: 'uniq_magasin_reception_numero', columns: ['numero'])]
class MagasinReception
{
    use HorodatageCreation;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** REC-2026-0001 : séquence par année. */
    #[ORM\Column(length: 20)]
    private string $numero;

    #[ORM\ManyToOne(targetEntity: Fournisseur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Fournisseur $fournisseur;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $bonLivraison = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dateReception;

    #[ORM\Column(length: 20, enumType: StatutReceptionMagasin::class)]
    private StatutReceptionMagasin $statut = StatutReceptionMagasin::RECUE;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $commentaire = null;

    /** Exigé dès que le compté diffère de l'annoncé sur une ligne. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $commentaireControle = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Utilisateur $recuePar;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Utilisateur $controleePar = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $controleeAt = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Utilisateur $inspecteePar = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inspecteeAt = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Utilisateur $stockeePar = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $stockeeAt = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Utilisateur $annuleePar = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $annuleeAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motifAnnulation = null;

    /** Le statut juste avant l'annulation : une réception annulée après stockage a contre-passé du stock. */
    #[ORM\Column(length: 20, nullable: true, enumType: StatutReceptionMagasin::class)]
    private ?StatutReceptionMagasin $statutAvantAnnulation = null;

    /** @var Collection<int, MagasinLigneReception> */
    #[ORM\OneToMany(mappedBy: 'reception', targetEntity: MagasinLigneReception::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lignes;

    public function __construct(string $numero, Fournisseur $fournisseur, \DateTimeImmutable $dateReception, Utilisateur $recuePar)
    {
        $this->numero = $numero;
        $this->fournisseur = $fournisseur;
        $this->dateReception = $dateReception;
        $this->recuePar = $recuePar;
        $this->createdAt = new \DateTimeImmutable();
        $this->lignes = new ArrayCollection();
    }

    // ------------------------------------------------------------------ Étapes

    /**
     * Étape 1 — l'annonce, corrigeable tant que le contrôle n'a pas eu lieu.
     *
     * @param list<array{produit: MagasinProduit, annoncee: int, prix?: ?int}> $lignes annoncé en millièmes d'unité de
     *        saisie ; une ligne **sans clé `prix`** garde le prix qu'elle avait — la gérante, qui
     *        ne voit pas les prix, corrige une annonce sans effacer celui de la dirigeante
     *
     * @throws \DomainException
     */
    public function annoncer(Fournisseur $fournisseur, \DateTimeImmutable $date, ?string $bonLivraison, ?string $commentaire, array $lignes): self
    {
        $this->garantirStatut(StatutReceptionMagasin::RECUE, 'L\'annonce ne se corrige plus une fois le contrôle fait.');

        if ([] === $lignes) {
            throw new \DomainException('La réception est vide : ajoutez au moins un produit.');
        }
        $vus = [];
        foreach ($lignes as $ligne) {
            $id = $ligne['produit']->getId();
            if (isset($vus[$id])) {
                throw new \DomainException(\sprintf('« %s » figure deux fois : regroupez les lignes.', $ligne['produit']->getNom()));
            }
            if ($ligne['annoncee'] <= 0) {
                throw new \DomainException(\sprintf('« %s » : la quantité annoncée doit être positive.', $ligne['produit']->getNom()));
            }
            $vus[$id] = true;
        }

        $this->fournisseur = $fournisseur;
        $this->dateReception = $date;
        $this->bonLivraison = self::texte($bonLivraison);
        $this->commentaire = self::texte($commentaire);

        // Une ligne existante garde son identité (et ses unités figées) si son
        // produit reste : on corrige l'annonce, on ne recrée pas la ligne.
        $existantes = [];
        foreach ($this->lignes as $ligne) {
            $existantes[$ligne->getProduit()->getId()] = $ligne;
        }
        foreach ($existantes as $id => $ligne) {
            if (!isset($vus[$id])) {
                $this->lignes->removeElement($ligne);
            }
        }
        foreach ($lignes as $saisie) {
            $ligne = $existantes[$saisie['produit']->getId()] ?? null;
            if (null !== $ligne) {
                $ligne->corrigerAnnonce($saisie['annoncee']);
                if (\array_key_exists('prix', $saisie)) {
                    $ligne->fixerPrix($saisie['prix']);
                }
            } else {
                $this->lignes->add(new MagasinLigneReception($this, $saisie['produit'], $saisie['annoncee'], $saisie['prix'] ?? null));
            }
        }

        return $this;
    }

    /**
     * Étape 2 — le comptage. Un écart à l'annonce exige un commentaire. Se reprend
     * tant que l'inspection n'a pas eu lieu.
     *
     * @param array<int, int> $comptees par id de ligne, en millièmes d'unité de saisie
     *
     * @throws \DomainException
     */
    public function controler(array $comptees, ?string $commentaire, Utilisateur $par, \DateTimeImmutable $le): self
    {
        $this->garantirStatut([StatutReceptionMagasin::RECUE, StatutReceptionMagasin::CONTROLEE], 'Le contrôle ne se refait plus une fois l\'inspection faite.');

        foreach ($this->lignes as $ligne) {
            $quantite = $comptees[$ligne->getId()] ?? null;
            if (null === $quantite) {
                throw new \DomainException(\sprintf('« %s » : saisissez la quantité comptée.', $ligne->getProduit()->getNom()));
            }
        }

        $ecart = false;
        foreach ($this->lignes as $ligne) {
            $ligne->compter($comptees[$ligne->getId()]);
            $ecart = $ecart || $ligne->aUnEcartDeComptage();
        }

        $commentaire = self::texte($commentaire);
        if ($ecart && null === $commentaire) {
            throw new \DomainException('Le compté diffère du bon de livraison : expliquez l\'écart dans le commentaire du contrôle.');
        }

        $this->commentaireControle = $commentaire;
        $this->statut = StatutReceptionMagasin::CONTROLEE;
        $this->controleePar = $par;
        $this->controleeAt = $le;

        return $this;
    }

    /**
     * Étape 3 — l'inspection : accepté + rejeté = compté, rejet motivé. Se reprend
     * tant que le stockage n'a pas eu lieu.
     *
     * @param array<int, array{acceptee: int, rejetee: int, motif: ?MotifRejetMagasin}> $decisions par id de ligne
     *
     * @throws \DomainException
     */
    public function inspecter(array $decisions, Utilisateur $par, \DateTimeImmutable $le): self
    {
        $this->garantirStatut([StatutReceptionMagasin::CONTROLEE, StatutReceptionMagasin::INSPECTEE], 'L\'inspection n\'a lieu qu\'après le contrôle, et pas après le stockage.');

        foreach ($this->lignes as $ligne) {
            $decision = $decisions[$ligne->getId()] ?? throw new \DomainException(\sprintf('« %s » : indiquez ce qui est accepté.', $ligne->getProduit()->getNom()));
            $ligne->inspecter($decision['acceptee'], $decision['rejetee'], $decision['motif']);
        }

        $this->statut = StatutReceptionMagasin::INSPECTEE;
        $this->inspecteePar = $par;
        $this->inspecteeAt = $le;

        return $this;
    }

    /**
     * Étape 4 — le rangement. Toute ligne qui a de l'accepté reçoit un emplacement
     * actif. C'est le service qui, dans la même transaction, fait entrer le stock.
     *
     * @param array<int, MagasinEmplacement> $emplacements par id de ligne
     *
     * @throws \DomainException
     */
    public function stocker(array $emplacements, Utilisateur $par, \DateTimeImmutable $le): self
    {
        $this->garantirStatut(StatutReceptionMagasin::INSPECTEE, 'Le stockage n\'a lieu qu\'après l\'inspection.');

        foreach ($this->lignes as $ligne) {
            if ((int) $ligne->getQteAcceptee() <= 0) {
                $ligne->ranger(null);
                continue;
            }
            $emplacement = $emplacements[$ligne->getId()] ?? throw new \DomainException(\sprintf('« %s » : choisissez où la ranger.', $ligne->getProduit()->getNom()));
            if (!$emplacement->isActif()) {
                throw new \DomainException(\sprintf('L\'emplacement « %s » est désactivé.', $emplacement->getLibelle()));
            }
            $ligne->ranger($emplacement);
        }

        $this->statut = StatutReceptionMagasin::STOCKEE;
        $this->stockeePar = $par;
        $this->stockeeAt = $le;

        return $this;
    }

    /**
     * Prix d'achat par ligne, jusqu'au stockage compris (c'est là que le coût moyen
     * est recalculé). Dirigeante seule — contrôlé par le service.
     *
     * @param array<int, ?int> $prix par id de ligne, centimes par unité de saisie
     *
     * @throws \DomainException
     */
    public function fixerPrix(array $prix): self
    {
        if ($this->estStockee() || $this->estAnnulee()) {
            throw new \DomainException('Les prix se fixent avant le stockage.');
        }
        foreach ($this->lignes as $ligne) {
            if (\array_key_exists((int) $ligne->getId(), $prix)) {
                $ligne->fixerPrix($prix[(int) $ligne->getId()]);
            }
        }

        return $this;
    }

    /** @throws \DomainException */
    public function verifierAnnulable(?string $motif): void
    {
        if ($this->estAnnulee()) {
            throw new \DomainException(\sprintf('La réception %s est déjà annulée.', $this->numero));
        }
        if (null === self::texte($motif)) {
            throw new \DomainException('Le motif d\'annulation est obligatoire.');
        }
    }

    public function annuler(?string $motif, Utilisateur $par, \DateTimeImmutable $le): self
    {
        $this->verifierAnnulable($motif);

        $this->statutAvantAnnulation = $this->statut;
        $this->statut = StatutReceptionMagasin::ANNULEE;
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

    public function getFournisseur(): Fournisseur
    {
        return $this->fournisseur;
    }

    public function getBonLivraison(): ?string
    {
        return $this->bonLivraison;
    }

    public function getDateReception(): \DateTimeImmutable
    {
        return $this->dateReception;
    }

    public function getStatut(): StatutReceptionMagasin
    {
        return $this->statut;
    }

    public function estStockee(): bool
    {
        return StatutReceptionMagasin::STOCKEE === $this->statut;
    }

    public function estAnnulee(): bool
    {
        return StatutReceptionMagasin::ANNULEE === $this->statut;
    }

    /** Annulée après le stockage : elle avait fait entrer du stock, contre-passé depuis. */
    public function aEteStockee(): bool
    {
        return $this->estStockee() || StatutReceptionMagasin::STOCKEE === $this->statutAvantAnnulation;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function getCommentaireControle(): ?string
    {
        return $this->commentaireControle;
    }

    public function getRecuePar(): Utilisateur
    {
        return $this->recuePar;
    }

    public function getControleePar(): ?Utilisateur
    {
        return $this->controleePar;
    }

    public function getControleeAt(): ?\DateTimeImmutable
    {
        return $this->controleeAt;
    }

    public function getInspecteePar(): ?Utilisateur
    {
        return $this->inspecteePar;
    }

    public function getInspecteeAt(): ?\DateTimeImmutable
    {
        return $this->inspecteeAt;
    }

    public function getStockeePar(): ?Utilisateur
    {
        return $this->stockeePar;
    }

    public function getStockeeAt(): ?\DateTimeImmutable
    {
        return $this->stockeeAt;
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

    public function getStatutAvantAnnulation(): ?StatutReceptionMagasin
    {
        return $this->statutAvantAnnulation;
    }

    /** @return Collection<int, MagasinLigneReception> */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    public function ligne(int $id): ?MagasinLigneReception
    {
        foreach ($this->lignes as $ligne) {
            if ($ligne->getId() === $id) {
                return $ligne;
            }
        }

        return null;
    }

    public function aDesRejets(): bool
    {
        foreach ($this->lignes as $ligne) {
            if ((int) $ligne->getQteRejetee() > 0) {
                return true;
            }
        }

        return false;
    }

    public function aDesEcartsDeComptage(): bool
    {
        foreach ($this->lignes as $ligne) {
            if ($ligne->aUnEcartDeComptage()) {
                return true;
            }
        }

        return false;
    }

    /** @throws \DomainException si la réception n'est pas à l'une des étapes permises */
    private function garantirStatut(StatutReceptionMagasin|array $permis, string $message): void
    {
        $permis = \is_array($permis) ? $permis : [$permis];
        if (!\in_array($this->statut, $permis, true)) {
            throw new \DomainException(\sprintf('Réception %s %s : %s', $this->numero, mb_strtolower($this->statut->libelle()), $message));
        }
    }

    private static function texte(?string $valeur): ?string
    {
        $valeur = null !== $valeur ? trim($valeur) : null;

        return '' !== $valeur ? $valeur : null;
    }
}
