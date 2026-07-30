<?php

namespace App\Service;

/**
 * Représentation, indépendante du support (HTML ou ESC/POS), d'un ticket de caisse.
 * Tous les montants sont en centimes de FCFA ; les quantités en millièmes d'unité.
 */
final readonly class TicketData
{
    /**
     * @param list<array{nom: string, quantiteMillimes: int, prixUnitaire: int, montant: int, commentaire: ?string}> $lignes
     * @param list<array{tauxBp: int, base: int, montant: int}>                                                       $ventilationTva
     * @param list<array{libelle: string, montant: int}>                                                             $reglements
     */
    public function __construct(
        public string $raisonSociale,
        public string $adresse,
        public string $ncc,
        public string $telephone,
        public string $pied,
        public string $numero,
        public \DateTimeImmutable $dateHeure,
        public string $caissier,
        public array $lignes,
        public array $ventilationTva,
        public array $reglements,
        public int $totalHt,
        public int $totalTva,
        public int $totalTtc,
        public int $remise,
        public int $rendu,
        // Mentions légales facultatives, imprimées si elles sont renseignées.
        public string $rccm = '',
        public string $email = '',
        /**
         * Chemin public du logo, vide s'il n'y en a pas. Sans effet sur la sortie
         * ESC/POS, qui n'envoie que du texte : voir {@see ImpressionService}.
         */
        public string $logo = '',
        /**
         * Code-barres du numéro de ticket. Null si le numéro n'est pas encodable :
         * un ticket sans code-barres reste un ticket valable, alors qu'un ticket
         * qui refuse de s'imprimer bloque la caisse.
         */
        public ?CodeBarres $codeBarres = null,
    ) {
    }
}
