import { Controller } from '@hotwired/stimulus';

/**
 * Pavé tactile de l'écran « Changer mon code PIN » : trois champs de quatre
 * chiffres (actuel, nouveau, confirmation) remplis au doigt.
 *
 * - le pavé écrit dans le **champ actif**, souligné en ambre ; toucher un champ
 *   le rend actif ;
 * - au quatrième chiffre, la saisie **passe au champ suivant** : trois codes à
 *   la suite, sans viser chaque case ;
 * - ⌫ sur un champ vide revient au précédent, comme on s'y attend ;
 * - **pas de soumission automatique**, contrairement au pavé de connexion : on
 *   relit avant d'engager son identifiant.
 */
const CHIFFRES = 4;

export default class extends Controller {
    static targets = ['champ'];

    connect() {
        const vide = this.champTargets.findIndex((champ) => champ.value.length < CHIFFRES);
        this.marquer(vide === -1 ? 0 : vide);
    }

    appuyer(event) {
        const champ = this.champTargets[this.actif];
        if (champ.value.length >= CHIFFRES) {
            return;
        }

        champ.value += event.currentTarget.dataset.chiffre;
        this.avancerSiComplet();
    }

    reculer() {
        if ('' === this.champTargets[this.actif].value && this.actif > 0) {
            this.marquer(this.actif - 1);
        }

        const champ = this.champTargets[this.actif];
        champ.value = champ.value.slice(0, -1);
    }

    effacer() {
        this.champTargets.forEach((champ) => { champ.value = ''; });
        this.marquer(0);
    }

    /** Toucher un champ le rend actif. */
    activer(event) {
        this.marquer(this.champTargets.indexOf(event.currentTarget));
    }

    /** Clavier physique : chiffres seuls, quatre au plus. */
    saisir(event) {
        const champ = event.currentTarget;
        champ.value = champ.value.replace(/\D/g, '').slice(0, CHIFFRES);
        this.marquer(this.champTargets.indexOf(champ));
        this.avancerSiComplet();
    }

    avancerSiComplet() {
        if (this.champTargets[this.actif].value.length === CHIFFRES && this.actif < this.champTargets.length - 1) {
            this.marquer(this.actif + 1);
            // Le focus suit : sans lui, un clavier physique continuerait d'écrire
            // dans la case pleine, et rien ne se passerait.
            this.champTargets[this.actif].focus({ preventScroll: true });
        }
    }

    marquer(index) {
        this.actif = Math.max(0, index);
        this.champTargets.forEach((champ, i) => {
            champ.dataset.actif = String(i === this.actif);
        });
    }
}
