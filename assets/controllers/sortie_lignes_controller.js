import { Controller } from '@hotwired/stimulus';

/**
 * Lignes d'une sortie du magasin : ajout, retrait, unités du produit choisi
 * (« sac » ou « kg ») et disponible de l'emplacement, sans réseau. Le serveur
 * relit tout et refuse une sortie au-delà du stock.
 */
export default class extends Controller {
    static targets = ['corps', 'modele', 'ligne'];
    static values = { infos: Object, reserve: String };

    connect() {
        this.prochain = this.ligneTargets.length;
    }

    ajouter() {
        const html = this.modeleTarget.innerHTML.replaceAll('__i__', String(this.prochain++));
        this.corpsTarget.insertAdjacentHTML('beforeend', html);
        this.corpsTarget.lastElementChild?.querySelector('select')?.focus();
    }

    retirer(evenement) {
        const ligne = evenement.target.closest('[data-sortie-lignes-target="ligne"]');
        if (this.ligneTargets.length <= 1) {
            ligne.querySelectorAll('input').forEach((champ) => { champ.value = ''; });
            ligne.querySelector('select').value = '';
            this.majLigne(ligne);

            return;
        }
        ligne.remove();
    }

    actualiser(evenement) {
        this.majLigne(evenement.target.closest('[data-sortie-lignes-target="ligne"]'));
    }

    majLigne(ligne) {
        const info = this.infosValue[ligne.querySelector('select').value];
        const unite = ligne.querySelector('[data-unite]');
        const [achat, stock] = unite.options;
        const disponible = ligne.querySelector('[data-disponible]');

        if (!info) {
            achat.hidden = false;
            achat.textContent = 'unité d\'achat';
            stock.textContent = 'unité de stock';
            disponible.textContent = '';

            return;
        }

        achat.hidden = null === info.achat;
        achat.textContent = info.achat ?? '';
        stock.textContent = info.stock;
        if (null === info.achat) {
            unite.value = 'stock';
        }

        const emplacement = ligne.querySelector('select[name$="[emplacement]"]')?.value ?? this.reserveValue;
        disponible.textContent = 'Disponible : ' + (info.disponible[emplacement] ?? '—');
    }
}
