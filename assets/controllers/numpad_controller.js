import { Controller } from '@hotwired/stimulus';

/*
 * Pavé numérique tactile pour la connexion caisse par code PIN.
 * Accumule 4 chiffres puis soumet automatiquement le formulaire.
 */
export default class extends Controller {
    static targets = ['input', 'form', 'dots'];

    connect() {
        this.code = '';
        this.render();
    }

    appuyer(event) {
        if (this.code.length >= 4) {
            return;
        }
        this.code += event.currentTarget.dataset.chiffre;
        this.render();

        if (this.code.length === 4) {
            // Laisse le dernier point s'afficher avant de soumettre.
            window.setTimeout(() => this.soumettre(), 150);
        }
    }

    reculer() {
        this.code = this.code.slice(0, -1);
        this.render();
    }

    effacer() {
        this.code = '';
        this.render();
    }

    soumettre() {
        this.inputTarget.value = this.code;
        this.formTarget.submit();
    }

    render() {
        this.inputTarget.value = this.code;
        const dots = this.dotsTarget.querySelectorAll('[data-dot]');
        dots.forEach((dot, index) => {
            const rempli = index < this.code.length;
            dot.classList.toggle('bg-white', rempli);
            dot.classList.toggle('border-white', rempli);
        });
    }
}
