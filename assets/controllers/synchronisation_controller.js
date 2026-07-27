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

    /**
     * Pastille discrète : l'état de synchronisation doit être lisible d'un coup
     * d'œil sans jamais disputer l'attention au ticket ni au bouton Encaisser.
     * Seuls les états qui demandent une action prennent de la couleur.
     */
    decrire(etat) {
        if (etat.bloquees > 0) {
            return {
                classes: 'bg-red-50 text-red-700 ring-1 ring-red-200',
                libelle: `${etat.bloquees} vente${etat.bloquees > 1 ? 's' : ''} à vérifier`,
                detail: '',
            };
        }

        if (etat.synchronisation) {
            return { classes: 'bg-stone-100 text-stone-500', libelle: 'Synchronisation', detail: '' };
        }

        if (!etat.enLigne) {
            return {
                classes: 'bg-amber-50 text-amber-800 ring-1 ring-amber-200',
                libelle: 'Hors ligne',
                detail: etat.enAttente > 0 ? `· ${etat.enAttente} en attente` : '',
            };
        }

        if (etat.enAttente > 0) {
            return {
                classes: 'bg-amber-50 text-amber-800 ring-1 ring-amber-200',
                libelle: `${etat.enAttente} en attente`,
                detail: '',
            };
        }

        return { classes: 'bg-stone-100 text-stone-500', libelle: 'Synchronisé', detail: '' };
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
