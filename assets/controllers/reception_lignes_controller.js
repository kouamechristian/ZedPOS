import { Controller } from '@hotwired/stimulus';

/**
 * Lignes d'une réception (étape 1) : ajout, retrait, rappel de l'unité d'achat
 * du produit choisi. Tout se passe dans la page, sans réseau ; le serveur relit
 * et vérifie la saisie.
 */
export default class extends Controller {
    static targets = ['corps', 'modele', 'ligne'];
    static values = { unites: Object };

    connect() {
        this.prochain = this.ligneTargets.length;
    }

    ajouter() {
        const html = this.modeleTarget.innerHTML.replaceAll('__i__', String(this.prochain++));
        this.corpsTarget.insertAdjacentHTML('beforeend', html);
        this.corpsTarget.lastElementChild?.querySelector('select')?.focus();
    }

    retirer(evenement) {
        const ligne = evenement.target.closest('tr');
        if (this.ligneTargets.length <= 1) {
            // Garder une ligne vide plutôt qu'un tableau sans champ.
            ligne.querySelectorAll('input, select').forEach((champ) => { champ.value = ''; });
            ligne.querySelector('[data-unite]').textContent = '';

            return;
        }
        ligne.remove();
    }

    changerProduit(evenement) {
        const ligne = evenement.target.closest('tr');
        ligne.querySelector('[data-unite]').textContent = this.unitesValue[evenement.target.value] ?? '';
        ligne.querySelector('input')?.focus();
    }
}
