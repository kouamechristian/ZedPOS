<?php

namespace App\Service;

/**
 * Chiffre d'une session de caisse — celle qui est ouverte, à défaut la dernière
 * clôturée. C'est ce que les tableaux de bord affichent en tête : le chiffre
 * d'affaires se lit par caisse, pas par jour civil.
 *
 * Montants en centimes de FCFA.
 */
final readonly class ChiffreCaisse
{
    public function __construct(
        public int $sessionId,
        public string $caissier,
        public bool $ouverte,
        public \DateTimeImmutable $ouvertureAt,
        public ?\DateTimeImmutable $clotureAt,
        public int $fondCaisse,
        public int $ca,
        public int $tickets,
    ) {
    }

    public function panierMoyen(): int
    {
        return $this->tickets > 0 ? intdiv($this->ca, $this->tickets) : 0;
    }
}
