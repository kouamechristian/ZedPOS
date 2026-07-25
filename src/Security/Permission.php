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

    private function __construct()
    {
    }
}
