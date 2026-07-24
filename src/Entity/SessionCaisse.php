<?php

namespace App\Entity;

use App\Entity\Trait\HorodatageCreation;
use App\Enum\StatutSessionCaisse;
use App\Repository\SessionCaisseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une session de caisse : de l'ouverture (fond de caisse) à la clôture.
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

    /** @var Collection<int, Vente> */
    #[ORM\OneToMany(mappedBy: 'sessionCaisse', targetEntity: Vente::class)]
    private Collection $ventes;

    public function __construct(Utilisateur $utilisateur, int $fondCaisse)
    {
        $this->utilisateur = $utilisateur;
        $this->fondCaisse = $fondCaisse;
        $this->ouvertureAt = new \DateTimeImmutable();
        $this->statut = StatutSessionCaisse::OUVERTE;
        $this->ventes = new ArrayCollection();
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

    /**
     * Clôture la session. Opération unique et irréversible.
     */
    public function cloturer(): self
    {
        if (StatutSessionCaisse::CLOTUREE === $this->statut) {
            throw new \LogicException('Cette session de caisse est déjà clôturée.');
        }

        $this->statut = StatutSessionCaisse::CLOTUREE;
        $this->clotureAt = new \DateTimeImmutable();

        return $this;
    }

    /** @return Collection<int, Vente> */
    public function getVentes(): Collection
    {
        return $this->ventes;
    }
}
