<?php

namespace App\Service;

/**
 * Erreur métier d'encaissement, porteuse d'un code HTTP à renvoyer au client.
 */
class EncaissementException extends \RuntimeException
{
    public function __construct(string $message, private readonly int $statut = 400)
    {
        parent::__construct($message);
    }

    public function statut(): int
    {
        return $this->statut;
    }
}
