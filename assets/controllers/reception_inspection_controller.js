import { Controller } from '@hotwired/stimulus';
import { ecrireMillimes, lireMillimes } from '../magasin/quantites.js';

/**
 * Inspection d'une réception (étape 3) : accepté = compté − rejeté, affiché en
 * direct, en millièmes entiers. Le serveur refait le calcul.
 */
export default class extends Controller {
    static targets = ['ligne'];

    connect() {
        this.calculer();
    }

    calculer() {
        for (const ligne of this.ligneTargets) {
            const comptee = lireMillimes(ligne.dataset.comptee) ?? 0;
            const saisie = ligne.querySelector('input').value.trim();
            const rejetee = '' === saisie ? 0 : lireMillimes(saisie);
            const sortie = ligne.querySelector('[data-accepte]');
            const unite = ligne.dataset.unite;

            if (null === rejetee || rejetee > comptee) {
                sortie.textContent = '? ' + unite;
                sortie.classList.add('text-red-700');
                continue;
            }
            sortie.textContent = ecrireMillimes(comptee - rejetee) + ' ' + unite;
            sortie.classList.remove('text-red-700');
        }
    }
}
