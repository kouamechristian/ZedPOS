<?php

namespace App\Entity;

use App\Entity\Trait\HorodatageCreation;
use App\Enum\CategorieDepense;
use App\Enum\TypeMouvementCaisse;
use App\Repository\MouvementCaisseRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une sortie d'espèces du tiroir pendant une session de caisse : dépense réglée
 * en liquide ou prélèvement (remise au coffre).
 *
 * Entité immuable : elle entre dans le calcul du fond théorique, elle ne doit pas
 * pouvoir être retouchée après coup. Elle n'est rattachable qu'à une session ouverte.
 */
#[ORM\Entity(repositoryClass: MouvementCaisseRepository::class)]
#[ORM\Table(name: 'mouvement_caisse')]
#[ORM\Index(name: 'idx_mouvement_caisse_session', columns: ['session_caisse_id'])]
class MouvementCaisse
{
    use HorodatageCreation;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SessionCaisse::class, inversedBy: 'mouvements')]
    #[ORM\JoinColumn(nullable: false)]
    private SessionCaisse $sessionCaisse;

    #[ORM\Column(length: 20, enumType: TypeMouvementCaisse::class)]
    private TypeMouvementCaisse $type;

    /** Catégorie de dépense ; nulle pour une simple sortie de caisse. */
    #[ORM\Column(length: 30, nullable: true, enumType: CategorieDepense::class)]
    private ?CategorieDepense $categorie;

    /** Montant sorti du tiroir, en centimes de FCFA (toujours positif). */
    #[ORM\Column]
    private int $montant;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $commentaire;

    /** Auteur de la saisie (le caissier en poste, en principe). */
    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Utilisateur $utilisateur;

    public function __construct(
        SessionCaisse $sessionCaisse,
        Utilisateur $utilisateur,
        TypeMouvementCaisse $type,
        int $montant,
        ?CategorieDepense $categorie = null,
        ?string $commentaire = null,
    ) {
        // Une journée clôturée n'accepte plus aucune écriture.
        $sessionCaisse->garantirOuverte();

        if ($montant <= 0) {
            throw new \DomainException('Le montant du mouvement de caisse doit être strictement positif.');
        }
        if (TypeMouvementCaisse::DEPENSE === $type && null === $categorie) {
            throw new \DomainException('Une dépense de caisse doit porter une catégorie.');
        }

        $this->sessionCaisse = $sessionCaisse;
        $this->utilisateur = $utilisateur;
        $this->type = $type;
        $this->montant = $montant;
        $this->categorie = TypeMouvementCaisse::DEPENSE === $type ? $categorie : null;
        $this->commentaire = $commentaire;
        $this->createdAt = new \DateTimeImmutable();

        $sessionCaisse->ajouterMouvement($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSessionCaisse(): SessionCaisse
    {
        return $this->sessionCaisse;
    }

    public function getType(): TypeMouvementCaisse
    {
        return $this->type;
    }

    public function getCategorie(): ?CategorieDepense
    {
        return $this->categorie;
    }

    public function getMontant(): int
    {
        return $this->montant;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function getUtilisateur(): Utilisateur
    {
        return $this->utilisateur;
    }

    /** Libellé lisible : catégorie pour une dépense, type pour une sortie. */
    public function getLibelle(): string
    {
        return $this->categorie?->libelle() ?? $this->type->libelle();
    }
}
