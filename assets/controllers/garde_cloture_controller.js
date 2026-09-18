import { Controller } from '@hotwired/stimulus';
import { caisseHorsLigne } from '../offline/caisse_hors_ligne.js';

/*
 * Garde-fou de la clôture Z : on ne ferme pas la caisse tant que des ventes
 * encaissées sur cette tablette n'ont pas atteint le serveur.
 *
 * Le théorique du Z est calculé **côté serveur**, à partir des ventes qu'il
 * connaît. Une vente encore dans la file locale est de l'argent dans le tiroir
 * que le Z ne voit pas : excédent ce soir, puis — une fois la vente enfin envoyée,
 * dans la session suivante — manquant demain.
 *
 *  - ventes **en attente** : la clôture est suspendue. Un envoi est lancé d'office
 *    et le bouton se libère dès que la file est vide ;
 *  - ventes **refusées** par le serveur (bloquées) : elles ne partiront jamais
 *    seules. La clôture reste possible, mais seulement après que la caissière a
 *    reconnu que ces ventes ne sont pas comptées ;
 *  - ventes d'**une autre caissière** : simple information, elles ne concernent
 *    pas cette caisse.
 *
 * Un confort et un filet, pas une barrière : le serveur ne peut pas voir la file
 * locale. Si IndexedDB est illisible, on laisse clôturer plutôt que d'enfermer la
 * caissière hors de sa propre clôture.
 */
export default class extends Controller {
    static targets = ['bouton', 'alerte', 'confirmation', 'case'];
    static values = { ventesAVerifier: String };

    connect() {
        this.horsLigne = caisseHorsLigne();
        this.etat = null;

        // Le temps de connaître l'état de la file, on ne laisse rien partir.
        this.boutonTarget.disabled = true;

        this.desabonner = this.horsLigne.file.surChangement((etat) => this.rendre(etat));
        this.horsLigne.demarrer();

        this.horsLigne.file.notifier().catch(() => this.liberer());
        // Envoi immédiat : la plupart du temps, la file se vide en quelques secondes.
        this.horsLigne.synchroniser().catch(() => {});
    }

    disconnect() {
        this.desabonner?.();
    }

    /** Dernier rempart : la touche Entrée soumet le formulaire sans passer par le bouton. */
    verifier(event) {
        if (this.boutonTarget.disabled) {
            event.preventDefault();
        }
    }

    basculer() {
        this.majBouton();
    }

    rendre(etat) {
        this.etat = etat;

        const attente = etat.enAttente;
        const bloquees = etat.bloquees;
        const messages = [];

        if (attente > 0) {
            messages.push({
                ton: 'ambre',
                texte: etat.enLigne
                    ? `${attente} vente${attente > 1 ? 's' : ''} pas encore transmise${attente > 1 ? 's' : ''} au serveur — envoi en cours. La clôture se libère dès qu'elles sont enregistrées.`
                    : `Hors ligne : ${attente} vente${attente > 1 ? 's' : ''} pas encore transmise${attente > 1 ? 's' : ''}. Rétablissez la connexion avant de clôturer, sinon leur argent manquera au théorique.`,
            });
        }

        if (bloquees > 0) {
            messages.push({
                ton: 'rouge',
                texte: `${bloquees} vente${bloquees > 1 ? 's' : ''} refusée${bloquees > 1 ? 's' : ''} par le serveur : ${bloquees > 1 ? 'elles ne sont' : 'elle n\'est'} pas dans la caisse. Traitez-${bloquees > 1 ? 'les' : 'la'} avant de clôturer.`,
                lien: this.ventesAVerifierValue,
            });
        }

        if (etat.autres > 0) {
            messages.push({
                ton: 'neutre',
                texte: `${etat.autres} vente${etat.autres > 1 ? 's' : ''} d'une autre caissière attend${etat.autres > 1 ? 'ent' : ''} sur cette tablette : ${etat.autres > 1 ? 'elles ne sont' : 'elle n\'est'} pas comptée${etat.autres > 1 ? 's' : ''} dans cette caisse.`,
            });
        }

        this.alerteTarget.innerHTML = messages.map(({ ton, texte, lien }) => `
            <div class="mb-3 rounded-xl px-4 py-3 text-sm font-medium ${this.classes(ton)}" role="alert">${this.esc(texte)}${
                lien ? ` <a href="${this.esc(lien)}" class="font-bold underline" data-turbo="false">Voir les ventes à vérifier</a>` : ''
            }</div>
        `).join('');

        this.confirmationTarget.classList.toggle('hidden', 0 === bloquees);
        if (0 === bloquees) {
            this.caseTarget.checked = false;
        }

        this.majBouton();
    }

    majBouton() {
        if (null === this.etat) {
            return;
        }

        const enAttente = this.etat.enAttente > 0;
        const bloqueesNonReconnues = this.etat.bloquees > 0 && !this.caseTarget.checked;

        this.boutonTarget.disabled = enAttente || bloqueesNonReconnues;
    }

    /** Lecture de l'état impossible : on rend la main plutôt que d'enfermer la caissière. */
    liberer() {
        this.boutonTarget.disabled = false;
    }

    classes(ton) {
        return {
            ambre: 'bg-amber-50 border border-amber-200 text-amber-800',
            rouge: 'bg-red-50 border border-red-200 text-red-800',
            neutre: 'bg-stone-100 border border-stone-200 text-stone-700',
        }[ton];
    }

    esc(texte) {
        const div = document.createElement('div');
        div.textContent = texte;

        return div.innerHTML;
    }
}
