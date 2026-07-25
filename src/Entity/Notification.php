<?php

namespace App\Entity;

use App\Entity\Trait\HorodatageCreation;
use App\Repository\NotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Alerte destinée à un rôle, consultable dans l'espace de pilotage.
 *
 * Sert aujourd'hui à prévenir la dirigeante des annulations de ventes. La lecture
 * (`luA`) est la seule mutation possible : le contenu est figé à la création, comme
 * pour le journal d'audit.
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notification')]
#[ORM\Index(name: 'idx_notification_destinataire', columns: ['role_destinataire', 'lu_a'])]
class Notification
{
    use HorodatageCreation;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Rôle destinataire, ex. `ROLE_DIRIGEANTE`. */
    #[ORM\Column(length: 50)]
    private string $roleDestinataire;

    #[ORM\Column(length: 50)]
    private string $type;

    #[ORM\Column(length: 255)]
    private string $titre;

    #[ORM\Column(type: Types::TEXT)]
    private string $message;

    /** Lien de consultation (détail du ticket concerné, par exemple). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lien = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $luA = null;

    public function __construct(
        string $roleDestinataire,
        string $type,
        string $titre,
        string $message,
        ?string $lien = null,
    ) {
        $this->roleDestinataire = $roleDestinataire;
        $this->type = $type;
        $this->titre = $titre;
        $this->message = $message;
        $this->lien = $lien;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRoleDestinataire(): string
    {
        return $this->roleDestinataire;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTitre(): string
    {
        return $this->titre;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getLien(): ?string
    {
        return $this->lien;
    }

    public function getLuA(): ?\DateTimeImmutable
    {
        return $this->luA;
    }

    public function estLue(): bool
    {
        return null !== $this->luA;
    }

    public function marquerLue(): self
    {
        $this->luA ??= new \DateTimeImmutable();

        return $this;
    }
}
