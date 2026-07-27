<?php

namespace App\Security;

/**
 * Vocabulaire des habilitations fines de ZedPOS.
 *
 * Les rôles (`ROLE_*`) disent **qui est** l'utilisateur ; ces permissions disent
 * **ce qu'il a le droit de faire**. Les contrôleurs et les gabarits ne doivent
 * jamais tester un rôle pour une décision métier : ils testent une permission,
 * arbitrée par les Voters de `App\Security\Voter`.
 *
 * Matrice d'habilitations :
 *
 * |                              | Caissier | Gérant | Dirigeante | Comptable |
 * |------------------------------|----------|--------|------------|-----------|
 * | Voir coût de revient / marge | non      | oui    | oui        | oui (L)   |
 * | Voir le CA global            | non      | oui    | oui        | oui (L)   |
 * | Voir toutes les ventes       | non      | oui    | oui        | oui (L)   |
 * | Voir SES ventes              | oui      | oui    | oui        | oui (L)   |
 * | Modifier un prix de vente    | non      | non    | **oui**    | non       |
 * | Modifier un article          | non      | oui    | oui        | non       |
 * | Annuler une vente encaissée  | non      | **oui**| oui        | non       |
 * | Exporter la comptabilité     | non      | oui    | oui        | oui (L)   |
 * | Gérer les comptes            | non      | oui    | oui        | non       |
 * | Agir sur un compte dirigeante| non      | **non**| oui        | non       |
 * | Attribuer le rôle dirigeante | non      | **non**| oui        | non       |
 *
 * (L) = lecture seule : le comptable ne se voit accorder aucune permission d'écriture.
 */
final class Permission
{
    // --- Articles -----------------------------------------------------------

    /** Consulter le coût de revient et la marge d'un article. */
    public const ARTICLE_VOIR_COUT = 'ARTICLE_VOIR_COUT';

    /** Fixer ou changer le prix de vente. Réservé à la dirigeante. */
    public const ARTICLE_MODIFIER_PRIX = 'ARTICLE_MODIFIER_PRIX';

    /** Modifier les autres attributs d'un article (nom, famille, couleur…). */
    public const ARTICLE_MODIFIER = 'ARTICLE_MODIFIER';

    // --- Ventes -------------------------------------------------------------

    /** Consulter le détail d'une vente (ticket, lignes, caissier). */
    public const VENTE_VOIR = 'VENTE_VOIR';

    /** Annuler une vente déjà encaissée. Notifie la dirigeante. */
    public const VENTE_ANNULER = 'VENTE_ANNULER';

    // --- Données agrégées ---------------------------------------------------

    /** Chiffre d'affaires consolidé, panier moyen, tendances. */
    public const VOIR_CA_GLOBAL = 'VOIR_CA_GLOBAL';

    /** Liste des ventes de tous les caissiers. */
    public const VOIR_TOUTES_VENTES = 'VOIR_TOUTES_VENTES';

    /**
     * Consulter l'espace comptable et produire les exports SYSCOHADA d'une période.
     *
     * Lecture : un export ne modifie rien. Il sort en revanche de l'application
     * l'intégralité du chiffre d'affaires, des charges et des écarts de caisse.
     * Il en était pour cette raison réservé au comptable et à la dirigeante ; la
     * restriction a été levée et le **gérant y accède désormais**, écran et
     * fichiers compris. Le caissier reste exclu.
     */
    public const EXPORTER_COMPTABILITE = 'EXPORTER_COMPTABILITE';

    // --- Comptes utilisateurs -----------------------------------------------

    /**
     * Créer un compte, l'activer, le désactiver.
     *
     * Deux questions selon le sujet :
     * - sujet `null` — « puis-je gérer des comptes ? », pour ouvrir l'écran et
     *   afficher le bouton de création ;
     * - sujet `Utilisateur` — « puis-je agir sur **ce** compte ? ». Un gérant n'a
     *   pas la main sur un compte dirigeante : il pourrait sinon le désactiver et
     *   priver l'établissement de son seul accès au pilotage.
     *
     * Le rôle attribuable est plafonné au sien, règle portée par
     * {@see \App\Enum\RoleUtilisateur::attribuablesPar()}.
     */
    public const UTILISATEUR_GERER = 'UTILISATEUR_GERER';

    private function __construct()
    {
    }
}
