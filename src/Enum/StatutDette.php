<?php

namespace App\Enum;

/**
 * OUVERTE → PARTIELLE → SOLDEE au fil des remboursements.
 *
 * ANNULEE : la dette d'un point que la gérante a annulé, **avant tout
 * remboursement**. Elle reste en base — le journal d'audit la cite — mais ne compte
 * plus dans le solde.
 */
enum StatutDette: string
{
    case OUVERTE = 'OUVERTE';
    case PARTIELLE = 'PARTIELLE';
    case SOLDEE = 'SOLDEE';
    case ANNULEE = 'ANNULEE';

    public function libelle(): string
    {
        return match ($this) {
            self::OUVERTE => 'Ouverte',
            self::PARTIELLE => 'Partiellement remboursée',
            self::SOLDEE => 'Soldée',
            self::ANNULEE => 'Annulée',
        };
    }

    /** Compte dans le solde dû par le vendeur. */
    public function estDue(): bool
    {
        return self::OUVERTE === $this || self::PARTIELLE === $this;
    }

    /** @return list<self> */
    public static function dues(): array
    {
        return [self::OUVERTE, self::PARTIELLE];
    }
}
