import { Controller } from '@hotwired/stimulus';

/*
 * Message flash affiché en toast : il glisse depuis la droite et s'efface seul.
 *
 * Le survol suspend le compte à rebours — un message qu'on est en train de lire
 * ne doit pas disparaître sous le curseur. Une erreur reste plus longtemps
 * qu'une confirmation (voir `duree` dans les gabarits).
 *
 * Retiré avant la mise en cache de Turbo : sans cela, revenir en arrière
 * réafficherait une confirmation déjà lue.
 */
export default class extends Controller {
    static values = { duree: { type: Number, default: 5000 } };

    connect() {
        this.retirerAvantCache = () => this.element.remove();
        document.addEventListener('turbo:before-cache', this.retirerAvantCache);
        this.programmer(this.dureeValue);
    }

    disconnect() {
        clearTimeout(this.minuterie);
        document.removeEventListener('turbo:before-cache', this.retirerAvantCache);
    }

    suspendre() {
        clearTimeout(this.minuterie);
    }

    reprendre() {
        this.programmer(2500);
    }

    programmer(duree) {
        clearTimeout(this.minuterie);
        if (duree > 0) {
            this.minuterie = setTimeout(() => this.fermer(), duree);
        }
    }

    fermer() {
        clearTimeout(this.minuterie);
        this.element.setAttribute('data-sortie', '');
        this.element.addEventListener('animationend', () => this.element.remove(), { once: true });
        // Filet de sécurité : sans animation (mouvement réduit), `animationend`
        // peut ne jamais venir.
        setTimeout(() => this.element.remove(), 400);
    }
}
