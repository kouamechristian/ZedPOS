<?php

namespace App\Entity;

use App\Repository\ParametreRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Paramètre de l'établissement, en clé/valeur.
 *
 * Stockage volontairement générique : le catalogue des clés, leurs libellés et
 * leurs valeurs par défaut vivent dans {@see \App\Enum\CleParametre}. Ajouter un
 * paramètre ne demande donc aucune migration.
 */
#[ORM\Entity(repositoryClass: ParametreRepository::class)]
#[ORM\Table(name: 'parametre')]
#[ORM\UniqueConstraint(name: 'uniq_parametre_cle', columns: ['cle'])]
class Parametre
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $cle;

    #[ORM\Column(type: Types::TEXT)]
    private string $valeur;

    #[ORM\Column]
    private \DateTimeImmutable $modifieA;

    public function __construct(string $cle, string $valeur = '')
    {
        $this->cle = $cle;
        $this->valeur = $valeur;
        $this->modifieA = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCle(): string
    {
        return $this->cle;
    }

    public function getValeur(): string
    {
        return $this->valeur;
    }

    public function definir(string $valeur): self
    {
        if ($valeur !== $this->valeur) {
            $this->valeur = $valeur;
            $this->modifieA = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getModifieA(): \DateTimeImmutable
    {
        return $this->modifieA;
    }
}
