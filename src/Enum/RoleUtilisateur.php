<?php

namespace App\Enum;

/**
 * Rôles applicatifs de ZedPOS.
 *
 * Hiérarchie (voir security.yaml) : DIRIGEANTE > GERANT > CAISSIER.
 * COMPTABLE est un rôle autonome (accès comptabilité, connexion classique).
 */
enum RoleUtilisateur: string
{
    case DIRIGEANTE = 'ROLE_DIRIGEANTE';
    case GERANT = 'ROLE_GERANT';
    case COMPTABLE = 'ROLE_COMPTABLE';
    case CAISSIER = 'ROLE_CAISSIER';

    /**
     * Ce rôle se connecte-t-il au pavé numérique (code PIN) plutôt qu'avec un mot de passe ?
     */
    public function utiliseCodePin(): bool
    {
        return self::CAISSIER === $this;
    }

    public function libelle(): string
    {
        return match ($this) {
            self::DIRIGEANTE => 'Dirigeante',
            self::GERANT => 'Gérant',
            self::COMPTABLE => 'Comptable',
            self::CAISSIER => 'Caissier',
        };
    }
}
