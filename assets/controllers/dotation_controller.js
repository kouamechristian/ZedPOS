import { Controller } from '@hotwired/stimulus';
import {
    ajouterChiffre,
    formaterFcfa,
    formaterQuantite,
    lireUnites,
    retirerChiffre,
    stockRestant,
    totaux,
} from '../dotation/calculs.js';
import { commission } from '../point/calculs.js';

/*
 * Écran de saisie d'un bon de dotation, au doigt.
 *
 * Toucher une vignette la rend active ; le pavé écrit sa quantité. Chaque vignette
 * porte son champ caché `quantites[id]` : le formulaire part tel quel, sans
 * JavaScript pour le reconstituer, et une page réaffichée en 422 reprend ce qui
 * avait été tapé.
 *
 * Le stock dépôt restant et les totaux se recalculent à chaque appui, **à l'écran
 * seulement** : le serveur revérifie le stock sous verrou et recalcule les montants
 * à la validation.
 */
export default class extends Controller {
    static targets = ['tuile', 'saisie', 'badge', 'reste', 'actif', 'afficheur', 'famille',
        'totalProduits', 'totalVente', 'totalCession', 'message', 'manque', 'alerteDette'];

    static values = { reprise: Object, repriseLibelle: String, suggestion: Object, mode: String, taux: Number };

    connect() {
        this.indexActif = -1;
        this.clavier = (event) => this.toucheClavier(event);
        window.addEventListener('keydown', this.clavier);
        this.recalculer();
    }

    disconnect() {
        window.removeEventListener('keydown', this.clavier);
    }

    selectionner(event) {
        this.activer(this.tuileTargets.indexOf(event.currentTarget));
    }

    /** Clavier ou Entrée sur une vignette : même effet qu'un appui. */
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

    /** « Reprendre la dernière dotation » : remplace toute la saisie. */
    reprendre() {
        this.appliquer(this.repriseValue, `Quantités reprises de ${this.repriseLibelleValue}. Ajustez avant de valider.`);
    }

    /** « Suggestion » : moyenne du vendu sur les mêmes jours de semaine, calculée par le serveur. */
    suggerer() {
        this.appliquer(this.suggestionValue, 'Quantités suggérées d\'après les ventes des mêmes jours de semaine. Ajustez avant de valider.');
    }

    /** Remplace toute la saisie par des quantités en unités, indexées par id d'article. */
    appliquer(quantites, message) {
        this.saisieTargets.forEach((saisie) => {
            const unites = quantites[saisie.dataset.article];
            saisie.value = unites ? String(unites) : '';
        });

        this.annoncer(message);
        this.recalculer();
    }

    /** Alerte de dette : celle du vendeur choisi, s'il en a une. N'empêche rien. */
    changerVendeur(event) {
        const vendeur = event.currentTarget.value;
        this.alerteDetteTargets.forEach((alerte) => {
            alerte.hidden = alerte.dataset.vendeur !== vendeur;
        });
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

    /** Pavé physique du terminal : chiffres et retour arrière, hors des champs. */
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

    recalculer() {
        let manques = 0;

        const lignes = this.tuileTargets.map((tuile, i) => {
            const unites = lireUnites(this.saisieTargets[i].value);
            const stock = '' === tuile.dataset.stock ? null : Number.parseInt(tuile.dataset.stock, 10);
            const reste = stockRestant(stock, unites);

            this.badgeTargets[i].textContent = unites > 0 ? String(unites) : '';
            tuile.toggleAttribute('data-saisie', unites > 0);

            if (null === reste) {
                this.resteTargets[i].textContent = 'Dépôt : non suivi';
            } else {
                this.resteTargets[i].textContent = `Dépôt : ${formaterQuantite(reste)}`;
            }

            const manque = null !== reste && reste < 0;
            tuile.toggleAttribute('data-manque', manque);
            manques += manque ? 1 : 0;

            return {
                unites,
                prixVente: Number.parseInt(tuile.dataset.prixVente, 10),
                prixCession: Number.parseInt(tuile.dataset.prixCession, 10),
            };
        });

        const total = totaux(lignes);
        this.totalProduitsTarget.textContent = `${total.produits} produit${total.produits > 1 ? 's' : ''} · ${total.unites} unité${total.unites > 1 ? 's' : ''}`;
        this.totalVenteTarget.textContent = formaterFcfa(total.vente);
        // Ce que le vendeur remettrait s'il vendait tout : cession à la marge, vente
        // moins la commission sinon — même règle que BonDotation::netSiToutVendu().
        const net = 'MARGE' === this.modeValue ? total.cession : total.vente - commission(total.vente, this.tauxValue);
        this.totalCessionTarget.textContent = formaterFcfa(net);

        if (this.hasManqueTarget) {
            this.manqueTarget.hidden = 0 === manques;
        }

        this.afficherActif();
    }

    afficherActif() {
        if (!this.hasActifTarget) {
            return;
        }

        if (this.indexActif < 0) {
            this.actifTarget.textContent = 'Touchez un produit';
            this.afficheurTarget.textContent = '—';

            return;
        }

        this.actifTarget.textContent = this.tuileTargets[this.indexActif].dataset.nom;
        this.afficheurTarget.textContent = String(lireUnites(this.saisieTargets[this.indexActif].value));
    }

    annoncer(texte) {
        if (this.hasMessageTarget) {
            this.messageTarget.textContent = texte;
        }
    }
}
