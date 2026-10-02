import { Controller } from '@hotwired/stimulus';
import { ajouterChiffre, lireUnites, retirerChiffre } from '../dotation/calculs.js';

/*
 * Déclaration de production ou de mise en vitrine, au doigt.
 *
 * Même geste que l'écran de dotation : toucher une vignette la rend active, le
 * pavé écrit sa quantité. Chaque vignette porte son champ caché `quantites[id]` :
 * le formulaire part tel quel, et une page réaffichée en 422 reprend la saisie.
 *
 * Chaque saisie **s'ajoute** à la fiche de production de la caisse. En vitrine,
 * chaque vignette porte ce qui est au fournil (`data-fournil`, en millièmes),
 * repris d'un appui par « Tout ce qui est au fournil » — un repère, pas un
 * plafond.
 *
 * Aucun prix n'est manipulé ici : l'atelier et la vitrine ne voient que des
 * quantités.
 */
export default class extends Controller {
    static targets = ['tuile', 'saisie', 'badge', 'actif', 'afficheur', 'famille', 'total', 'message'];

    connect() {
        // Aucun article d'atelier : l'écran n'a ni vignette ni pavé.
        if (!this.hasTotalTarget) {
            return;
        }

        this.indexActif = -1;
        this.clavier = (event) => this.toucheClavier(event);
        window.addEventListener('keydown', this.clavier);
        this.recalculer();
    }

    disconnect() {
        if (this.clavier) {
            window.removeEventListener('keydown', this.clavier);
        }
    }

    selectionner(event) {
        this.activer(this.tuileTargets.indexOf(event.currentTarget));
    }

    selectionnerAuClavier(event) {
        if ('Enter' === event.key || ' ' === event.key) {
            event.preventDefault();
            this.selectionner(event);
        }
    }

    appuyer(event) {
        this.ecrire((saisie) => ajouterChiffre(saisie, event.currentTarget.dataset.chiffre));
    }

    reculer() {
        this.ecrire(retirerChiffre);
    }

    effacer() {
        this.ecrire(() => '');
    }

    /** Vitrine : met d'un appui tout ce qui est au fournil pour l'article actif. */
    toutLeReste() {
        if (this.indexActif < 0) {
            this.annoncer('Touchez d\'abord un produit.');

            return;
        }

        const fournil = this.fournilDe(this.tuileTargets[this.indexActif]);
        if (null !== fournil) {
            this.ecrire(() => (fournil > 0 ? String(Math.trunc(fournil / 1000)) : ''));
        }
    }

    filtrer(event) {
        const famille = event.currentTarget.dataset.famille;

        this.familleTargets.forEach((bouton) => bouton.setAttribute('aria-pressed', String(bouton === event.currentTarget)));
        this.tuileTargets.forEach((tuile) => {
            tuile.hidden = '' !== famille && tuile.dataset.famille !== famille;
        });
    }

    // ------------------------------------------------------------------------

    activer(index) {
        this.indexActif = index;

        this.tuileTargets.forEach((tuile, i) => {
            const active = i === index;
            tuile.toggleAttribute('data-active', active);
            tuile.setAttribute('aria-pressed', String(active));
        });

        this.afficherActif();
    }

    ecrire(transformation) {
        if (this.indexActif < 0) {
            this.annoncer('Touchez d\'abord un produit.');

            return;
        }

        const saisie = this.saisieTargets[this.indexActif];
        saisie.value = transformation(saisie.value);
        this.recalculer();
    }

    toucheClavier(event) {
        if (event.target.closest('input, select, textarea') || event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }

        if (/^\d$/.test(event.key)) {
            event.preventDefault();
            this.ecrire((saisie) => ajouterChiffre(saisie, event.key));
        } else if ('Backspace' === event.key) {
            event.preventDefault();
            this.reculer();
        }
    }

    fournilDe(tuile) {
        return '' === (tuile.dataset.fournil ?? '') ? null : Number.parseInt(tuile.dataset.fournil, 10);
    }

    recalculer() {
        let produits = 0;
        let unitesTotal = 0;

        this.tuileTargets.forEach((tuile, i) => {
            const unites = lireUnites(this.saisieTargets[i].value);

            this.badgeTargets[i].textContent = unites > 0 ? String(unites) : '';
            tuile.toggleAttribute('data-saisie', unites > 0);

            if (unites > 0) {
                produits += 1;
                unitesTotal += unites;
            }
        });

        this.totalTarget.textContent = 0 === produits
            ? 'Rien de saisi'
            : `${produits} produit${produits > 1 ? 's' : ''} · ${unitesTotal} unité${unitesTotal > 1 ? 's' : ''}`;

        this.afficherActif();
    }

    afficherActif() {
        if (this.indexActif < 0) {
            this.actifTarget.textContent = 'Touchez un produit';
            this.afficheurTarget.textContent = '—';

            return;
        }

        this.actifTarget.textContent = this.tuileTargets[this.indexActif].dataset.nom;
        this.afficheurTarget.textContent = String(lireUnites(this.saisieTargets[this.indexActif].value));
    }

    annoncer(texte) {
        this.messageTarget.textContent = texte;
    }
}
