<?php

namespace App\Service;

use App\Entity\BonDotation;

/**
 * Validation d'un point refusée : des dotations de la période sont encore en
 * brouillon. La marchandise est peut-être partie sans que le stock le sache — il
 * faut valider ou annuler chacune avant d'arrêter.
 */
final class DotationsEnBrouillonException extends \DomainException
{
    /** @param list<BonDotation> $bons */
    public function __construct(public readonly array $bons)
    {
        parent::__construct(\sprintf(
            'Dotation(s) encore en brouillon sur la période : %s. Validez-les ou annulez-les avant d\'arrêter le point.',
            implode(', ', array_map(static fn (BonDotation $b): string => $b->getNumero(), $bons)),
        ));
    }
}
