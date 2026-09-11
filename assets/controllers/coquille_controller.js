import { Controller } from '@hotwired/stimulus';

/*
 * Coquille du back-office : barre latérale repliable sur ordinateur, tiroir sur
 * téléphone et tablette.
 *
 * L'état « replié » est gardé dans un **cookie**, et c'est le serveur qui le rend
 * (`data-replie` posé par `admin/base.html.twig`). Rangé en localStorage et
 * appliqué au chargement du contrôleur, il ferait déplier puis replier la barre
 * à chaque navigation : Turbo remplace le <body>, et le contrôleur ne se connecte
 * qu'après le premier affichage.
 *
 * Le tiroir, lui, se referme tout seul dès qu'une navigation part : sur téléphone
 * on l'ouvre pour aller quelque part, pas pour le garder ouvert.
 */
const COOKIE = 'zp_barre_repliee';

export default class extends Controller {
    connect() {
        this.fermerSurNavigation = () => this.fermer();
        this.fermerSurEchap = (event) => {
            if ('Escape' === event.key) {
                this.fermer();
            }
        };

        document.addEventListener('turbo:before-visit', this.fermerSurNavigation);
        document.addEventListener('turbo:before-cache', this.fermerSurNavigation);
        document.addEventListener('keydown', this.fermerSurEchap);
    }

    disconnect() {
        document.removeEventListener('turbo:before-visit', this.fermerSurNavigation);
        document.removeEventListener('turbo:before-cache', this.fermerSurNavigation);
        document.removeEventListener('keydown', this.fermerSurEchap);
    }

    /** Ordinateur : barre pleine ↔ icônes seules. */
    replier() {
        const replie = !this.element.hasAttribute('data-replie');

        this.element.toggleAttribute('data-replie', replie);
        document.cookie = replie
            ? `${COOKIE}=1; path=/; max-age=31536000; SameSite=Lax`
            : `${COOKIE}=; path=/; max-age=0; SameSite=Lax`;
    }

    /** Téléphone et tablette : le tiroir. */
    ouvrir() {
        this.element.setAttribute('data-ouvert', '');
    }

    fermer() {
        this.element.removeAttribute('data-ouvert');
    }
}
