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

    /**
     * Rôles qu'un utilisateur a le droit d'attribuer à un nouveau compte.
     *
     * **Nul ne distribue un accès au-dessus du sien.** Un gérant qui pourrait
     * créer une dirigeante s'octroierait ses droits en deux clics : fixer les
     * prix de vente, le pilotage, le journal d'audit. La hiérarchie des rôles ne
     * voudrait alors plus rien dire.
     *
     * Le comptable est également hors de portée du gérant : ouvrir l'accès au
     * cabinet extérieur relève de la relation contractuelle, pas de la gestion
     * courante du magasin.
     *
     * Source unique de la règle — le formulaire s'en sert pour ne **pas afficher**
     * les rôles interdits (un choix absent ne peut pas être soumis, même en
     * forgeant la requête), et les tests pour la figer.
     *
     * @return list<self>
     */
    public static function attribuablesPar(bool $dirigeante): array
    {
        return $dirigeante
            ? self::cases()
            : [self::GERANT, self::CAISSIER];
    }
}
