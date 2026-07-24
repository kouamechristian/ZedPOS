<?php

namespace App\Entity\Trait;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Fournit un champ `createdAt` immuable à toutes les entités du domaine.
 *
 * La valeur doit être initialisée dans le constructeur de l'entité :
 *     $this->createdAt = new \DateTimeImmutable();
 */
trait HorodatageCreation
{
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
