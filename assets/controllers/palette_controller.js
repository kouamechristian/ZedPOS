import { Controller } from '@hotwired/stimulus';

/*
 * Palette de navigation — Ctrl+K (⌘K sur Mac), ou le champ « Aller à… » de la
 * barre du haut.
 *
 * Les écrans proposés sont rendus par le serveur, à partir de la même liste que
 * la barre latérale : ce qu'on ne peut pas ouvrir n'y figure pas. Ce contrôleur
 * ne fait que filtrer et suivre le clavier ; le choix est un simple lien, que
 * Turbo intercepte comme n'importe quel autre.
 *
 * La recherche ignore accents et majuscules : « cloture » trouve « Clôtures ».
 */
export default class extends Controller {
    static targets = ['dialogue', 'champ', 'element', 'vide'];

    connect() {
        this.index = 0;
        this.visibles = [...this.elementTargets];

        this.raccourci = (event) => {
            if ((event.ctrlKey || event.metaKey) && 'k' === event.key.toLowerCase()) {
                event.preventDefault();
                this.basculer();
            }
        };
        this.fermerAvantCache = () => this.fermer();

        document.addEventListener('keydown', this.raccourci);
        document.addEventListener('turbo:before-cache', this.fermerAvantCache);
        document.addEventListener('turbo:before-visit', this.fermerAvantCache);
    }

    disconnect() {
        document.removeEventListener('keydown', this.raccourci);
        document.removeEventListener('turbo:before-cache', this.fermerAvantCache);
        document.removeEventListener('turbo:before-visit', this.fermerAvantCache);
    }

    basculer() {
        this.dialogueTarget.open ? this.fermer() : this.ouvrir();
    }

    ouvrir() {
        if (this.dialogueTarget.open) {
            return;
        }

        this.champTarget.value = '';
        this.filtrer();
        this.dialogueTarget.showModal();
        this.champTarget.focus();
    }

    fermer() {
        if (this.hasDialogueTarget && this.dialogueTarget.open) {
            this.dialogueTarget.close();
        }
    }

    /** Un clic sur le voile (la zone hors du panneau) referme la palette. */
    fermerSurFond(event) {
        if (event.target === this.dialogueTarget) {
            this.fermer();
        }
    }

    filtrer() {
        const terme = this.normaliser(this.champTarget.value);

        this.visibles = this.elementTargets.filter((element) => {
            const correspond = this.normaliser(element.dataset.recherche ?? element.textContent).includes(terme);
            element.hidden = !correspond;

            return correspond;
        });

        this.videTarget.hidden = this.visibles.length > 0;
        this.selectionner(0);
    }

    naviguer(event) {
        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                this.selectionner(this.index + 1);
                break;
            case 'ArrowUp':
                event.preventDefault();
                this.selectionner(this.index - 1);
                break;
            case 'Enter':
                event.preventDefault();
                this.visibles[this.index]?.click();
                break;
            default:
        }
    }

    survoler(event) {
        const index = this.visibles.indexOf(event.currentTarget);
        if (index >= 0) {
            this.selectionner(index);
        }
    }

    selectionner(index) {
        this.elementTargets.forEach((element) => element.setAttribute('aria-selected', 'false'));

        if (0 === this.visibles.length) {
            return;
        }

        this.index = (index + this.visibles.length) % this.visibles.length;
        const choisi = this.visibles[this.index];
        choisi.setAttribute('aria-selected', 'true');
        choisi.scrollIntoView({ block: 'nearest' });
    }

    normaliser(texte) {
        return String(texte).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
    }
}
