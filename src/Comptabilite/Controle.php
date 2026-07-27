<?php

namespace App\Comptabilite;

/**
 * Un rapprochement entre une valeur lue dans l'application et la valeur
 * correspondante dans les écritures produites.
 *
 * Un export comptable se relit : avant d'envoyer un fichier au cabinet, on
 * vérifie que le chiffre d'affaires des écritures est bien celui affiché sur le
 * pilotage. Ces contrôles sont affichés à l'écran d'export et repris au pied de
 * la balance — un document de lecture, contrairement aux fichiers d'écritures
 * qui doivent rester strictement tabulaires pour s'importer sans retouche.
 */
final class Controle
{
    public function __construct(
        public readonly string $libelle,
        /** Valeur de référence, lue dans l'application, en centimes de FCFA. */
        public readonly int $attendu,
        /** Valeur reconstituée depuis les écritures, en centimes de FCFA. */
        public readonly int $obtenu,
        /** Explication affichée lorsque le contrôle échoue. */
        public readonly string $explication = '',
    ) {
    }

    public function estBon(): bool
    {
        return $this->attendu === $this->obtenu;
    }

    /** Différence obtenu − attendu, en centimes de FCFA. */
    public function ecart(): int
    {
        return $this->obtenu - $this->attendu;
    }
}
