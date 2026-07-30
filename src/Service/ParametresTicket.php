<?php

namespace App\Service;

/**
 * Informations de la boutique imprimées sur les tickets et les rapports Z.
 *
 * Simple valeur immuable : elle est construite par
 * {@see ParametresBoutique::pourTicket()}, déclarée comme fabrique dans
 * `config/services.yaml`. Les consommateurs (TicketBuilder, ImpressionService,
 * RapportQuotidienTexte) continuent d'injecter ce type sans rien savoir du
 * stockage.
 *
 * La saisie se fait dans le back-office (`/admin/parametres`), plus dans `.env`.
 */
final readonly class ParametresTicket
{
    public function __construct(
        public string $raisonSociale,
        public string $adresse,
        public string $ncc,
        public string $telephone,
        public string $pied,
        public string $rccm = '',
        public string $email = '',
        /**
         * Chemin public du logo (`/uploads/boutique/…`), vide s'il n'y en a pas.
         * Un chemin déjà composé, et non un nom de fichier : les consommateurs
         * l'écrivent dans un `src` sans rien savoir du stockage.
         */
        public string $logo = '',
    ) {
    }
}
