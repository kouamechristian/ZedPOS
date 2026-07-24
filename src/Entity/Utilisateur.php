<?php

namespace App\Entity;

use App\Entity\Trait\HorodatageCreation;
use App\Repository\UtilisateurRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UtilisateurRepository::class)]
#[ORM\Table(name: 'utilisateur')]
#[ORM\UniqueConstraint(name: 'uniq_utilisateur_email', columns: ['email'])]
class Utilisateur implements UserInterface, PasswordAuthenticatedUserInterface
{
    use HorodatageCreation;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    /** Code PIN de caisse, haché (jamais stocké en clair). */
    #[ORM\Column]
    private string $codePin;

    #[ORM\Column(length: 120)]
    private string $nom;

    #[ORM\Column(options: ['default' => true])]
    private bool $actif = true;

    public function __construct(string $email, string $nom)
    {
        $this->email = $email;
        $this->nom = $nom;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    /**
     * Identifiant unique de connexion.
     */
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): self
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * Le mot de passe stocké est le code PIN haché.
     */
    public function getPassword(): string
    {
        return $this->codePin;
    }

    public function getCodePin(): string
    {
        return $this->codePin;
    }

    /**
     * @param string $codePin Code PIN déjà haché
     */
    public function setCodePin(string $codePin): self
    {
        $this->codePin = $codePin;

        return $this;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): self
    {
        $this->nom = $nom;

        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): self
    {
        $this->actif = $actif;

        return $this;
    }

    public function eraseCredentials(): void
    {
        // Aucune donnée sensible temporaire à effacer.
    }
}
