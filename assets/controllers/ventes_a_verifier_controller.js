import { Controller } from '@hotwired/stimulus';
import { caisseHorsLigne } from '../offline/caisse_hors_ligne.js';
import { interpreterReponse } from '../offline/interpreter_reponse.js';

/*
 * Écran « Ventes à vérifier » : les ventes que le serveur a refusées.
 *
 * Elles n'existent que dans la file locale. Deux gestes, et aucun ne perd rien :
 *
 *  - Réessayer : la vente retourne dans la file et repart aussitôt ;
 *  - Retirer   : la vente est d'abord déclarée au serveur (journal d'audit,
 *                surlignée), et n'est effacée de la tablette que si le serveur
 *                l'a confirmé. Réseau coupé : elle reste, rien n'est perdu.
 */
export default class extends Controller {
    static targets = ['liste', 'vide', 'message'];
    static values = { declaration: String };

    connect() {
        this.horsLigne = caisseHorsLigne();
        this.desabonner = this.horsLigne.file.surChangement(() => this.afficher());
        this.horsLigne.demarrer();
        this.afficher();
    }

    disconnect() {
        this.desabonner?.();
    }

    async afficher() {
        const entrees = await this.horsLigne.file.bloquees();

        this.videTarget.classList.toggle('hidden', entrees.length > 0);
        this.listeTarget.innerHTML = entrees.map((entree) => this.carte(entree)).join('');
    }

    carte(entree) {
        const ticket = entree.ticket ?? {};
        const lignes = (ticket.lines ?? []).map((l) => `${this.esc(l.qty)} × ${this.esc(l.label)}`).join(' · ');
        const total = ticket.total ?? null;
        const quand = this.quand(entree);

        return `
            <article class="rounded-2xl bg-white p-5 text-stone-800 shadow-xl">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-sm text-stone-500">${this.esc(quand)}</p>
                        <p class="mt-1 text-sm">${lignes || 'Détail non disponible'}</p>
                    </div>
                    ${null !== total ? `<p class="text-xl font-black tabular-nums">${this.fcfa(total)}</p>` : ''}
                </div>
                <p class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm font-medium text-red-800">
                    ${this.esc(entree.erreur ?? 'Refusée par le serveur')}
                </p>
                <div class="mt-4 flex flex-wrap gap-3">
                    <button type="button" data-uuid="${this.esc(entree.uuid)}" data-action="ventes-a-verifier#reessayer"
                            class="rounded-xl bg-stone-900 px-4 py-2 text-sm font-bold text-white">Réessayer</button>
                    <button type="button" data-uuid="${this.esc(entree.uuid)}" data-action="ventes-a-verifier#retirer"
                            class="rounded-xl border border-red-300 bg-white px-4 py-2 text-sm font-bold text-red-700">Retirer</button>
                </div>
            </article>
        `;
    }

    async reessayer(event) {
        const uuid = event.currentTarget.dataset.uuid;

        await this.horsLigne.file.reessayer(uuid);
        await this.horsLigne.synchroniser();

        const encore = (await this.horsLigne.file.bloquees()).some((e) => e.uuid === uuid);
        this.dire(
            encore
                ? 'Toujours refusée — voyez le motif affiché, ou retirez la vente.'
                : 'Vente enregistrée par le serveur.',
            encore,
        );
        await this.afficher();
    }

    async retirer(event) {
        const uuid = event.currentTarget.dataset.uuid;

        if (!window.confirm('Retirer cette vente de la tablette ?\n\nElle sera consignée au journal d\'audit : le gérant pourra la retrouver et la ressaisir.')) {
            return;
        }

        const retiree = await this.horsLigne.file.retirerBloquee(uuid, (entree) => this.declarer(entree));

        this.dire(
            retiree
                ? 'Vente consignée au journal d\'audit et retirée de la tablette.'
                : 'Retrait impossible : le serveur n\'a pas confirmé (réseau coupé ?). La vente reste sur la tablette.',
            !retiree,
        );
        await this.afficher();
    }

    /** Consigne la vente côté serveur. Vrai seulement si le serveur a répondu « ok ». */
    async declarer(entree) {
        if (!navigator.onLine) {
            return false;
        }

        const ticket = entree.ticket ?? {};

        try {
            const reponse = await fetch(this.declarationValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                redirect: 'manual',
                body: JSON.stringify({
                    uuid: entree.uuid,
                    erreur: entree.erreur,
                    totalFcfa: ticket.total ?? 0,
                    reglementFcfa: ticket.paid ?? 0,
                    venduA: entree.charge?.venduA ?? null,
                    lignes: (ticket.lines ?? []).map((l) => ({
                        nom: l.label,
                        quantite: Number.parseInt(l.qty, 10) || 0,
                        montant: l.price,
                    })),
                }),
            });

            const lu = await interpreterReponse(reponse);

            return 200 === lu.statut;
        } catch {
            return false;
        }
    }

    quand(entree) {
        const date = new Date(entree.charge?.venduA ?? entree.creeA);

        return Number.isNaN(date.getTime())
            ? ''
            : date.toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' });
    }

    fcfa(montant) {
        return `${Number(montant).toLocaleString('fr-FR').replace(/[  ]/g, ' ')} FCFA`;
    }

    dire(texte, erreur) {
        this.messageTarget.textContent = texte;
        this.messageTarget.className = `mt-4 rounded-xl px-4 py-3 text-sm font-medium ${
            erreur ? 'bg-red-50 border border-red-200 text-red-800' : 'bg-green-50 border border-green-200 text-green-800'
        }`;
    }

    esc(valeur) {
        const div = document.createElement('div');
        div.textContent = String(valeur ?? '');

        return div.innerHTML;
    }
}
