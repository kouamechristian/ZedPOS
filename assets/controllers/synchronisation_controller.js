import { Controller } from '@hotwired/stimulus';
import { caisseHorsLigne } from '../offline/caisse_hors_ligne.js';

/*
 * Bandeau permanent d'état de synchronisation, en haut de l'écran de caisse.
 *
 * Trois états principaux :
 *   « Synchronisé »                        tout est transmis ;
 *   « Hors ligne — N ventes en attente »   connexion coupée ;
 *   « Synchronisation… »                   vidage de la file en cours.
 *
 * Un quatrième état, « N vente(s) à vérifier », signale les entrées refusées
 * définitivement par le serveur : elles ne sont jamais supprimées.
 */
export default class extends Controller {
    static targets = ['libelle', 'detail'];

    connect() {
        this.horsLigne = caisseHorsLigne();

        this.desabonner = this.horsLigne.file.surChangement((etat) => this.rendre(etat));
        this.horsLigne.demarrer();

        // Enregistre le Service Worker et lui demande de mettre la coquille en cache.
        this.installerServiceWorker();

        this.horsLigne.file.notifier();
    }

    disconnect() {
        this.desabonner?.();
    }

    /** Vidage manuel : utile quand le caissier voit revenir le réseau avant nous. */
    synchroniser() {
        this.horsLigne.synchroniser();
    }

    rendre(etat) {
        const { classes, libelle, detail } = this.decrire(etat);

        this.element.className = `${this.element.dataset.baseClasses} ${classes}`;
        this.libelleTarget.textContent = libelle;
        this.detailTarget.textContent = detail;
    }

    decrire(etat) {
        if (etat.bloquees > 0) {
            return {
                classes: 'bg-red-600 text-white',
                libelle: `${etat.bloquees} vente${etat.bloquees > 1 ? 's' : ''} à vérifier`,
                detail: 'Refusée(s) par le serveur — prévenez le gérant, rien n\'est perdu.',
            };
        }

        if (etat.synchronisation) {
            return {
                classes: 'bg-blue-600 text-white',
                libelle: 'Synchronisation…',
                detail: etat.enAttente > 0 ? `${etat.enAttente} restante(s)` : '',
            };
        }

        if (!etat.enLigne) {
            return {
                classes: 'bg-amber-500 text-amber-950',
                libelle: etat.enAttente > 0
                    ? `Hors ligne — ${etat.enAttente} vente${etat.enAttente > 1 ? 's' : ''} en attente`
                    : 'Hors ligne',
                detail: 'Les encaissements continuent, ils partiront au retour du réseau.',
            };
        }

        if (etat.enAttente > 0) {
            return {
                classes: 'bg-amber-500 text-amber-950',
                libelle: `${etat.enAttente} vente${etat.enAttente > 1 ? 's' : ''} en attente`,
                detail: 'Transmission en cours…',
            };
        }

        return { classes: 'bg-green-600 text-white', libelle: 'Synchronisé', detail: '' };
    }

    async installerServiceWorker() {
        if (!('serviceWorker' in navigator)) {
            return;
        }

        try {
            const enregistrement = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
            await navigator.serviceWorker.ready;

            // La page est chargée et le réseau répond : c'est le bon moment pour
            // mettre la coquille de caisse en cache.
            if (navigator.onLine) {
                (enregistrement.active ?? navigator.serviceWorker.controller)
                    ?.postMessage({ type: 'PRECHARGER_CAISSE' });
            }
        } catch {
            // Pas de Service Worker (contexte non sécurisé, navigateur ancien) :
            // la file de synchronisation fonctionne quand même, seul le cache de
            // la page manque.
        }
    }
}
