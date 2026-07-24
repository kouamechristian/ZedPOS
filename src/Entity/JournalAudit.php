<?php

namespace App\Entity;

use App\Entity\Trait\HorodatageCreation;
use App\Repository\JournalAuditRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trace d'audit : conserve l'état avant/après de chaque action sensible.
 */
#[ORM\Entity(repositoryClass: JournalAuditRepository::class)]
#[ORM\Index(name: 'idx_journal_audit_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_journal_audit_entite', columns: ['entite', 'entite_id'])]
class JournalAudit
{
    use HorodatageCreation;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Utilisateur $utilisateur = null;

    #[ORM\Column(length: 100)]
    private string $action;

    #[ORM\Column(length: 100)]
    private string $entite;

    #[ORM\Column(nullable: true)]
    private ?int $entiteId = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $avant = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $apres = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    /**
     * @param array<string, mixed>|null $avant
     * @param array<string, mixed>|null $apres
     */
    public function __construct(
        string $action,
        string $entite,
        ?int $entiteId = null,
        ?Utilisateur $utilisateur = null,
        ?array $avant = null,
        ?array $apres = null,
        ?string $ip = null,
    ) {
        $this->action = $action;
        $this->entite = $entite;
        $this->entiteId = $entiteId;
        $this->utilisateur = $utilisateur;
        $this->avant = $avant;
        $this->apres = $apres;
        $this->ip = $ip;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUtilisateur(): ?Utilisateur
    {
        return $this->utilisateur;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getEntite(): string
    {
        return $this->entite;
    }

    public function getEntiteId(): ?int
    {
        return $this->entiteId;
    }

    /** @return array<string, mixed>|null */
    public function getAvant(): ?array
    {
        return $this->avant;
    }

    /** @return array<string, mixed>|null */
    public function getApres(): ?array
    {
        return $this->apres;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }
}
