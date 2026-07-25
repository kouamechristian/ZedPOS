<?php

namespace App\Enum;

/**
 * Actions sensibles tracées dans le journal d'audit.
 *
 * La valeur est persistée telle quelle dans `journal_audit.action` (colonne
 * texte) : les libellés existants restent lisibles même si l'énumération évolue.
 */
enum ActionAudit: string
{
    // Sécurité
    case CONNEXION = 'CONNEXION';
    case DECONNEXION = 'DECONNEXION';
    case ECHEC_CONNEXION = 'ECHEC_CONNEXION';

    // Ventes
    case VENTE_ANNULEE = 'VENTE_ANNULEE';
    case REMISE_ACCORDEE = 'REMISE_ACCORDEE';

    // Catalogue et stock
    case PRIX_MODIFIE = 'PRIX_MODIFIE';
    case PERTE_SAISIE = 'PERTE_SAISIE';
    case INVENTAIRE_VALIDE = 'INVENTAIRE_VALIDE';

    // Caisse
    case CAISSE_CLOTUREE = 'CAISSE_CLOTUREE';
    case ECART_CAISSE = 'ECART_CAISSE';

    // Comptes
    case UTILISATEUR_CREE = 'UTILISATEUR_CREE';
    case UTILISATEUR_ACTIVE = 'UTILISATEUR_ACTIVE';
    case UTILISATEUR_DESACTIVE = 'UTILISATEUR_DESACTIVE';

    public function libelle(): string
    {
        return match ($this) {
            self::CONNEXION => 'Connexion',
            self::DECONNEXION => 'Déconnexion',
            self::ECHEC_CONNEXION => 'Échec de connexion',
            self::VENTE_ANNULEE => 'Annulation de vente',
            self::REMISE_ACCORDEE => 'Remise accordée',
            self::PRIX_MODIFIE => 'Modification de prix',
            self::PERTE_SAISIE => 'Saisie de perte',
            self::INVENTAIRE_VALIDE => "Validation d'inventaire",
            self::CAISSE_CLOTUREE => 'Clôture de caisse',
            self::ECART_CAISSE => 'Écart de caisse',
            self::UTILISATEUR_CREE => 'Création d\'utilisateur',
            self::UTILISATEUR_ACTIVE => 'Activation d\'utilisateur',
            self::UTILISATEUR_DESACTIVE => 'Désactivation d\'utilisateur',
        };
    }

    /**
     * Actions à mettre en évidence dans la consultation : elles signalent soit une
     * anomalie, soit un geste commercial ou comptable à surveiller.
     */
    public function estSensible(): bool
    {
        return \in_array($this, [
            self::ECHEC_CONNEXION,
            self::VENTE_ANNULEE,
            self::REMISE_ACCORDEE,
            self::ECART_CAISSE,
            self::UTILISATEUR_DESACTIVE,
        ], true);
    }

    /** Regroupement utilisé pour organiser le filtre de la page de consultation. */
    public function famille(): string
    {
        return match ($this) {
            self::CONNEXION, self::DECONNEXION, self::ECHEC_CONNEXION => 'Sécurité',
            self::VENTE_ANNULEE, self::REMISE_ACCORDEE => 'Ventes',
            self::PRIX_MODIFIE, self::PERTE_SAISIE, self::INVENTAIRE_VALIDE => 'Catalogue et stock',
            self::CAISSE_CLOTUREE, self::ECART_CAISSE => 'Caisse',
            self::UTILISATEUR_CREE, self::UTILISATEUR_ACTIVE, self::UTILISATEUR_DESACTIVE => 'Comptes',
        };
    }
}
