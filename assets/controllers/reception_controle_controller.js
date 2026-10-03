import { Controller } from '@hotwired/stimulus';
import { lireMillimes } from '../magasin/quantites.js';

/**
 * Contrôle d'une réception (étape 2) : surligne en direct une ligne dont le
 * compté diffère de l'annoncé et annonce que le commentaire devient obligatoire.
 * Affichage seulement — la règle est tranchée par l'entité.
 */
export default class extends Controller {
    static targets = ['ligne', 'avis'];

    connect() {
        this.verifier();
    }

    verifier() {
        let ecart = false;
        for (const ligne of this.ligneTargets) {
            const comptee = lireMillimes(ligne.querySelector('input').value);
            const different = comptee !== null && comptee !== lireMillimes(ligne.dataset.annoncee);
            ligne.classList.toggle('bg-amber-50', different);
            ligne.toggleAttribute('data-ecart', different);
            ecart ||= different;
        }
        if (this.hasAvisTarget) {
            this.avisTarget.hidden = !ecart;
        }
    }

    /** La livraison est conforme : chaque compté reprend l'annoncé. */
    toutConforme() {
        for (const ligne of this.ligneTargets) {
            ligne.querySelector('input').value = ligne.dataset.annoncee;
        }
        this.verifier();
    }
}
