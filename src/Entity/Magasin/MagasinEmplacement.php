<?php

namespace App\Entity\Magasin;

use App\Entity\Trait\HorodatageCreation;
use App\Repository\Magasin\MagasinEmplacementRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une zone de rangement du magasin : réserve sèche, chambre froide, étagère A.
 *
 * Sans rapport avec `Emplacement` (dépôt, stands) : le magasin a ses propres
 * zones. Le **code** est l'identité et ne change pas après la création — c'est
 * par lui qu'on retrouve la réserve par défaut ({@see self::CODE_RESERVE}).
 */
#[ORM\Entity(repositoryClass: MagasinEmplacementRepository::class)]
#[ORM\Table(name: 'magasin_emplacement')]
#[ORM\UniqueConstraint(name: 'uniq_magasin_emplacement_code', columns: ['code'])]
class MagasinEmplacement
{
    use HorodatageCreation;

    /** La réserve créée par la migration : là où tout va tant qu'il n'y a qu'une zone. */
    public const CODE_RESERVE = 'RESERVE';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $code;

    #[ORM\Column(length: 100)]
    private string $libelle;

    #[ORM\Column(options: ['default' => true])]
    private bool $actif = true;

    public function __construct(string $code, string $libelle)
    {
        $this->code = mb_strtoupper(trim($code));
        $this->libelle = trim($libelle);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): self
    {
        $this->libelle = trim($libelle);

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

    public function estReserveParDefaut(): bool
    {
        return self::CODE_RESERVE === $this->code;
    }

    public function __toString(): string
    {
        return $this->libelle;
    }
}
