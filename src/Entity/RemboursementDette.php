<?php

namespace App\Entity;

use App\Entity\Trait\HorodatageCreation;
use App\Enum\ModeReglement;
use App\Repository\RemboursementDetteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Argent rendu par un vendeur sur une dette. Immuable : aucun setter.
 *
 * Naît de {@see DetteVendeur::encaisser()}, seul endroit qui tient le reste dû à
 * jour. Un versement réparti sur plusieurs dettes (les plus anciennes d'abord)
 * donne un remboursement par dette touchée.
 */
#[ORM\Entity(repositoryClass: RemboursementDetteRepository::class)]
#[ORM\Table(name: 'remboursement_dette')]
class RemboursementDette
{
    use HorodatageCreation;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DetteVendeur::class, inversedBy: 'remboursements')]
    #[ORM\JoinColumn(nullable: false)]
    private DetteVendeur $dette;

    /** Centimes. */
    #[ORM\Column]
    private int $montant;

    #[ORM\Column(name: 'date_remboursement', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Utilisateur $encaissePar;

    #[ORM\Column(length: 20, enumType: ModeReglement::class)]
    private ModeReglement $moyen;

    /** @internal passer par {@see DetteVendeur::encaisser()} */
    public function __construct(DetteVendeur $dette, int $montant, \DateTimeImmutable $date, Utilisateur $encaissePar, ModeReglement $moyen)
    {
        if (ModeReglement::CREDIT === $moyen) {
            throw new \DomainException('On ne rembourse pas une dette à crédit.');
        }

        $this->dette = $dette;
        $this->montant = $montant;
        $this->date = $date->setTime(0, 0);
        $this->encaissePar = $encaissePar;
        $this->moyen = $moyen;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDette(): DetteVendeur
    {
        return $this->dette;
    }

    public function getMontant(): int
    {
        return $this->montant;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getEncaissePar(): Utilisateur
    {
        return $this->encaissePar;
    }

    public function getMoyen(): ModeReglement
    {
        return $this->moyen;
    }
}
