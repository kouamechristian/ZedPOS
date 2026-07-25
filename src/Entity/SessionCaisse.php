<?php

namespace App\Entity;

use App\Entity\Trait\HorodatageCreation;
use App\Enum\StatutSessionCaisse;
use App\Repository\SessionCaisseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une session de caisse : de l'ouverture (saisie du fond de caisse) à la clôture Z.
 *
 * Une seule session peut être OUVERTE par caissier à la fois (contrainte posée par
 * {@see \App\Service\SessionCaisseService::ouvrir()}). Une fois CLOTUREE, la session
 * est **définitivement figée** : plus aucune vente, annulation ou dépense ne peut
 * lui être rattachée — c'est le rôle de {@see self::garantirOuverte()}, appelée par
 * les entités qui s'y rattachent.
 */
#[ORM\Entity(repositoryClass: SessionCaisseRepository::class)]
class SessionCaisse
{
    use HorodatageCreation;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Utilisateur $utilisateur;

    /** Fond de caisse à l'ouverture, en centimes de FCFA. */
    #[ORM\Column]
    private int $fondCaisse;

    #[ORM\Column]
    private \DateTimeImmutable $ouvertureAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $clotureAt = null;

    #[ORM\Column(length: 20, enumType: StatutSessionCaisse::class)]
    private StatutSessionCaisse $statut;

    /**
     * Fond théorique figé à la clôture, en centimes de FCFA :
     * fond de caisse + espèces encaissées − dépenses − sorties.
     */
    #[ORM\Column(nullable: true)]
    private ?int $theorique = null;

    /** Montant physiquement compté par le caissier à la clôture, en centimes. */
    #[ORM\Column(nullable: true)]
    private ?int $montantCompte = null;

    /** Écart de caisse = compté − théorique (positif : excédent, négatif : manquant). */
    #[ORM\Column(nullable: true)]
    private ?int $ecart = null;

    /** Justification de la clôture, obligatoire dès qu'il y a un écart. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $commentaireCloture = null;

    /** @var Collection<int, Vente> */
    #[ORM\OneToMany(mappedBy: 'sessionCaisse', targetEntity: Vente::class)]
    private Collection $ventes;

    /** @var Collection<int, MouvementCaisse> */
    #[ORM\OneToMany(mappedBy: 'sessionCaisse', targetEntity: MouvementCaisse::class)]
    private Collection $mouvements;

    public function __construct(Utilisateur $utilisateur, int $fondCaisse)
    {
        if ($fondCaisse < 0) {
            throw new \DomainException('Le fond de caisse ne peut pas être négatif.');
        }

        $this->utilisateur = $utilisateur;
        $this->fondCaisse = $fondCaisse;
        $this->ouvertureAt = new \DateTimeImmutable();
        $this->statut = StatutSessionCaisse::OUVERTE;
        $this->ventes = new ArrayCollection();
        $this->mouvements = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUtilisateur(): Utilisateur
    {
        return $this->utilisateur;
    }

    public function getFondCaisse(): int
    {
        return $this->fondCaisse;
    }

    public function getOuvertureAt(): \DateTimeImmutable
    {
        return $this->ouvertureAt;
    }

    public function getClotureAt(): ?\DateTimeImmutable
    {
        return $this->clotureAt;
    }

    public function getStatut(): StatutSessionCaisse
    {
        return $this->statut;
    }

    public function estOuverte(): bool
    {
        return StatutSessionCaisse::OUVERTE === $this->statut;
    }

    /**
     * Garde-fou d'immuabilité : refuse toute écriture dans une session clôturée.
     *
     * @throws \DomainException si la session est déjà clôturée
     */
    public function garantirOuverte(): void
    {
        if (!$this->estOuverte()) {
            throw new \DomainException(\sprintf(
                'La session de caisse du %s est clôturée : elle ne peut plus être modifiée.',
                $this->ouvertureAt->format('d/m/Y'),
            ));
        }
    }

    /**
     * Clôture Z : fige le théorique calculé, le montant compté et l'écart.
     * Opération unique et irréversible.
     *
     * @param int $theorique     fond + espèces encaissées − dépenses − sorties, en centimes
     * @param int $montantCompte espèces physiquement comptées dans le tiroir, en centimes
     */
    public function cloturer(int $theorique, int $montantCompte, ?string $commentaire = null): self
    {
        $this->garantirOuverte();

        if ($montantCompte < 0) {
            throw new \DomainException('Le montant compté ne peut pas être négatif.');
        }

        $ecart = $montantCompte - $theorique;
        $commentaire = null !== $commentaire ? trim($commentaire) : null;

        if (0 !== $ecart && (null === $commentaire || '' === $commentaire)) {
            throw new \DomainException("Un commentaire est obligatoire lorsqu'il existe un écart de caisse.");
        }

        $this->statut = StatutSessionCaisse::CLOTUREE;
        $this->clotureAt = new \DateTimeImmutable();
        $this->theorique = $theorique;
        $this->montantCompte = $montantCompte;
        $this->ecart = $ecart;
        $this->commentaireCloture = '' !== $commentaire ? $commentaire : null;

        return $this;
    }

    public function getTheorique(): ?int
    {
        return $this->theorique;
    }

    public function getMontantCompte(): ?int
    {
        return $this->montantCompte;
    }

    public function getEcart(): ?int
    {
        return $this->ecart;
    }

    public function getCommentaireCloture(): ?string
    {
        return $this->commentaireCloture;
    }

    /** @return Collection<int, Vente> */
    public function getVentes(): Collection
    {
        return $this->ventes;
    }

    public function ajouterMouvement(MouvementCaisse $mouvement): self
    {
        if (!$this->mouvements->contains($mouvement)) {
            $this->mouvements->add($mouvement);
        }

        return $this;
    }

    /** @return Collection<int, MouvementCaisse> */
    public function getMouvements(): Collection
    {
        return $this->mouvements;
    }
}
