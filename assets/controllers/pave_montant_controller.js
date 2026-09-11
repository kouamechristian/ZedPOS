import { Controller } from '@hotwired/stimulus';

/**
 * Pavé numérique tactile des écrans du cycle de caisse : fond d'ouverture,
 * dépense, montant compté à la clôture.
 *
 * Mêmes règles que le pavé du montant reçu de `/caisse` (`ticket_controller`),
 * que la caissière pratique déjà vingt fois par heure :
 * - disposition téléphone, touche « 000 » — presque tout se compte en milliers ;
 * - **pas de zéro en tête** : « 0 » puis « 5 » vaut 5.
 *
 * Le champ garde des **chiffres seuls** : c'est un `IntegerType`, le serveur n'a
 * donc rien de nouveau à lire. La lecture « 20 000 FCFA » est affichée à côté —
 * au Z, confondre 20000 et 200000 ferait un écart que personne n'a commis.
 */
const CHIFFRES_MAX = 9;

/**
 * « 05 » → « 5 », mais « 0 » reste « 0 » : un fond de caisse nul est une saisie
 * valable, et il doit pouvoir se taper.
 */
const sansZeroEnTete = (chiffres) => chiffres.replace(/^0+(?=\d)/, '');

export default class extends Controller {
    static targets = ['champ', 'lecture'];

    connect() {
        // Formulaire réaffiché en 422 : la lecture reprend la valeur déjà saisie,
        // sans la réécrire — le champ doit montrer ce qui a été refusé.
        this.lire();
    }

    appuyer(event) {
        const apres = sansZeroEnTete(`${this.chiffres()}${event.currentTarget.dataset.chiffre}`);

        // Une touche restée enfoncée, pas un montant de tiroir.
        if (apres.length > CHIFFRES_MAX) {
            return;
        }

        this.ecrire(apres);
    }

    reculer() {
        this.ecrire(this.chiffres().slice(0, -1));
    }

    effacer() {
        this.ecrire('');
    }

    /** Clavier physique : le champ est relu et nettoyé comme par le pavé. */
    saisir() {
        this.ecrire(this.chiffres());
    }

    chiffres() {
        return this.champTarget.value.replace(/\D/g, '');
    }

    /** Seul point d'écriture : champ et lecture restent toujours d'accord. */
    ecrire(chiffres) {
        const nettoyes = sansZeroEnTete(chiffres).slice(0, CHIFFRES_MAX);

        if (this.champTarget.value !== nettoyes) {
            this.champTarget.value = nettoyes;
        }

        this.lire();
    }

    /** « 20000 » → « 20 000 FCFA », espaces de milliers comme partout à l'écran. */
    lire() {
        if (!this.hasLectureTarget) {
            return;
        }

        // Valeur refusée (« -500 ») : ne rien lire plutôt qu'un « 500 FCFA » trompeur.
        const valeur = this.champTarget.value.trim();
        this.lectureTarget.textContent = /^\d+$/.test(valeur)
            ? `${sansZeroEnTete(valeur).replace(/\B(?=(\d{3})+(?!\d))/g, ' ')} FCFA`
            : '';
    }
}
