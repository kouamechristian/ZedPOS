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
    case UTILISATEUR_MODIFIE = 'UTILISATEUR_MODIFIE';
    case UTILISATEUR_ACTIVE = 'UTILISATEUR_ACTIVE';
    case UTILISATEUR_DESACTIVE = 'UTILISATEUR_DESACTIVE';
    // Changement de **son propre** mot de passe ou code PIN. Distinct de
    // UTILISATEUR_MODIFIE, qui trace l'intervention d'un gérant sur le compte
    // d'un autre : ici personne n'a rien redistribué.
    case SECRET_MODIFIE = 'SECRET_MODIFIE';

    // Stands et revendeurs. Pas de double validation dans ce module : ces entrées
    // sont la seule sécurité, d'où les montants avant/après sur chacune.
    case DOTATION_VALIDEE = 'DOTATION_VALIDEE';
    case DOTATION_ANNULEE = 'DOTATION_ANNULEE';
    case ARRETE_VALIDE = 'ARRETE_VALIDE';
    case ARRETE_ANNULE = 'ARRETE_ANNULE';
    // Un point validé avec écart produit deux entrées, comme une clôture de caisse :
    // on filtre les écarts seuls.
    case ECART_POINT = 'ECART_POINT';
    // Dettes des vendeurs : ouverture (manquant imputé au point, avance, autre),
    // remboursement, annulation avec le point qui l'avait ouverte.
    case DETTE_CREEE = 'DETTE_CREEE';
    case DETTE_REMBOURSEE = 'DETTE_REMBOURSEE';
    case DETTE_ANNULEE = 'DETTE_ANNULEE';

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
            self::UTILISATEUR_MODIFIE => 'Modification d\'utilisateur',
            self::UTILISATEUR_ACTIVE => 'Activation d\'utilisateur',
            self::UTILISATEUR_DESACTIVE => 'Désactivation d\'utilisateur',
            self::SECRET_MODIFIE => 'Changement de mot de passe ou de code PIN',
            self::DOTATION_VALIDEE => 'Validation de dotation',
            self::DOTATION_ANNULEE => 'Annulation de dotation',
            self::ARRETE_VALIDE => 'Validation d\'un point de stand',
            self::ARRETE_ANNULE => 'Annulation d\'un point de stand',
            self::ECART_POINT => 'Écart d\'espèces au point',
            self::DETTE_CREEE => 'Dette de vendeur ouverte',
            self::DETTE_REMBOURSEE => 'Remboursement de dette de vendeur',
            self::DETTE_ANNULEE => 'Annulation de dette de vendeur',
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
            // Un rôle changé ou un identifiant réinitialisé redistribue un accès :
            // c'est exactement ce qu'on vient relire dans un journal d'audit.
            self::UTILISATEUR_MODIFIE,
            // Une dotation annulée remet de la marchandise au dépôt sur la seule
            // parole de la gérante.
            self::DOTATION_ANNULEE,
            // Défaire un point rouvre ce qu'un vendeur a déjà payé.
            self::ARRETE_ANNULE,
            self::ECART_POINT,
            // Effacer ce qu'un vendeur doit, même par le biais d'un point annulé.
            self::DETTE_ANNULEE,
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
            self::UTILISATEUR_CREE, self::UTILISATEUR_MODIFIE,
            self::UTILISATEUR_ACTIVE, self::UTILISATEUR_DESACTIVE,
            self::SECRET_MODIFIE => 'Comptes',
            self::DOTATION_VALIDEE, self::DOTATION_ANNULEE,
            self::ARRETE_VALIDE, self::ARRETE_ANNULE, self::ECART_POINT,
            self::DETTE_CREEE, self::DETTE_REMBOURSEE, self::DETTE_ANNULEE => 'Stands et revendeurs',
        };
    }
}
