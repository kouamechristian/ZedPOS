import { Controller } from '@hotwired/stimulus';

/*
 * Compteur animé : le chiffre monte de zéro jusqu'à sa valeur à l'arrivée sur
 * l'écran.
 *
 * **La valeur finale est déjà dans la page**, rendue par le serveur : ce
 * contrôleur ne fait qu'un effet d'affichage, et sans JavaScript — ou quand le
 * système demande moins d'animations — le chiffre s'affiche simplement tel quel.
 * À la fin, le texte d'origine est remis au caractère près.
 *
 * `cible` est un **entier** (FCFA entiers, nombre de tickets…), et l'animation
 * reste en arithmétique entière : un montant ne passe jamais par un flottant, même
 * le temps d'une image. L'accélération (ease-out cubique) se calcule en entiers :
 * cible − cible × reste³ / étapes³.
 */
const ETAPES = 36;

export default class extends Controller {
    static values = { cible: Number };

    connect() {
        const cible = this.cibleValue;

        if (!Number.isSafeInteger(cible) || 0 === cible
            || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        this.texteFinal = this.element.textContent;
        this.etape = 0;
        this.restaurer = () => this.terminer();
        document.addEventListener('turbo:before-cache', this.restaurer);

        this.element.textContent = this.formater(0);
        this.image = requestAnimationFrame(() => this.avancer(cible));
    }

    disconnect() {
        this.terminer();
    }

    avancer(cible) {
        this.etape += 1;
        const reste = ETAPES - this.etape;

        if (reste <= 0) {
            this.terminer();

            return;
        }

        const valeur = cible - Math.trunc((cible * reste * reste * reste) / (ETAPES * ETAPES * ETAPES));
        this.element.textContent = this.formater(valeur);
        this.image = requestAnimationFrame(() => this.avancer(cible));
    }

    terminer() {
        cancelAnimationFrame(this.image);
        if (undefined !== this.texteFinal) {
            this.element.textContent = this.texteFinal;
        }
        document.removeEventListener('turbo:before-cache', this.restaurer);
    }

    /** Espaces de milliers, comme `number_format(0, ',', ' ')` côté Twig. */
    formater(valeur) {
        const signe = valeur < 0 ? '-' : '';

        return signe + String(Math.abs(valeur)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    }
}
