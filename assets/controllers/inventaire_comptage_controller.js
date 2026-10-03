import { Controller } from '@hotwired/stimulus';
import { ecrireMillimes, lireMillimes } from '../magasin/quantites.js';

/**
 * Feuille d'inventaire à l'écran : l'écart de chaque ligne se recalcule à la
 * frappe, en millièmes entiers et en unité de stock. Affichage seulement — le
 * serveur recalcule tout à l'enregistrement.
 */
export default class extends Controller {
    static targets = ['ligne'];

    calculer(evenement) {
        const ligne = evenement.target.closest('[data-inventaire-comptage-target="ligne"]');
        const cellule = ligne.querySelector('[data-ecart-cellule]');
        const texte = ligne.querySelector('input[name$="[quantite]"]').value.trim();

        if ('' === texte) {
            cellule.textContent = '—';
            ligne.classList.remove('bg-amber-50');

            return;
        }
        const saisie = lireMillimes(texte);
        if (null === saisie) {
            cellule.textContent = '?';

            return;
        }

        const enAchat = 'achat' === (ligne.querySelector('select[name$="[unite]"]')?.value ?? 'stock');
        const contenance = enAchat ? Number(ligne.dataset.contenance) : 1000;
        // saisie × contenance / 1000, arrondi au plus proche, sans flottant.
        const compte = Math.trunc((saisie * contenance * 2 + 1000) / 2000);
        const ecart = compte - Number(ligne.dataset.theorique);

        cellule.textContent = 0 === ecart ? '0' : (ecart > 0 ? '+' : '−') + ecrireMillimes(Math.abs(ecart)) + ' ' + ligne.dataset.uniteStock;
        ligne.classList.toggle('bg-amber-50', 0 !== ecart);
    }
}
