import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
/*
 * Formulaire de création d'utilisateur : n'affiche que le secret utile au rôle choisi.
 *
 * Chargé en « lazy » : il ne sert que sur un écran d'administration, inutile de
 * l'embarquer sur l'écran de caisse.
 *
 * Un caissier se connecte au code PIN sur le pavé numérique de la caisse, les
 * autres rôles avec un mot de passe. Montrer les deux champs en permanence
 * invitait à remplir le mauvais.
 *
 * Ce contrôleur ne fait que du confort d'affichage : la règle est tranchée côté
 * serveur par CreerUtilisateurType, qui valide le secret correspondant au rôle
 * réellement soumis. Sans JavaScript, les deux champs restent visibles et le
 * formulaire fonctionne exactement de la même façon.
 */
export default class extends Controller {
    static targets = ['role', 'motDePasse', 'codePin'];

    /* Rôle se connectant au code PIN — doit rester aligné sur RoleUtilisateur::utiliseCodePin(). */
    static values = { rolePin: { type: String, default: 'ROLE_CAISSIER' } };

    connect() {
        this.basculer();
    }

    basculer() {
        const pin = this.roleTarget.value === this.rolePinValue;

        this.afficher(this.codePinTarget, pin);
        this.afficher(this.motDePasseTarget, !pin);
    }

    /*
     * Le champ masqué est aussi vidé : sans cela, un mot de passe saisi puis
     * masqué en passant sur « Caissier » serait tout de même soumis.
     */
    afficher(conteneur, visible) {
        conteneur.hidden = !visible;

        if (!visible) {
            conteneur.querySelectorAll('input').forEach((input) => {
                input.value = '';
            });
        }
    }
}
