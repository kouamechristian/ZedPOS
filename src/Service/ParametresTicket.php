<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Informations de la boutique imprimées sur le ticket (paramétrables via .env).
 */
final readonly class ParametresTicket
{
    public function __construct(
        #[Autowire('%env(TICKET_RAISON_SOCIALE)%')] public string $raisonSociale,
        #[Autowire('%env(TICKET_ADRESSE)%')] public string $adresse,
        #[Autowire('%env(TICKET_NCC)%')] public string $ncc,
        #[Autowire('%env(TICKET_TELEPHONE)%')] public string $telephone,
        #[Autowire('%env(TICKET_PIED)%')] public string $pied,
    ) {
    }
}
