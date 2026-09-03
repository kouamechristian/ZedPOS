import { Controller } from '@hotwired/stimulus';
import { pos } from '../js/pos-agent.js';

/*
 * Indicateur « Matériel connecté / non connecté » de l'écran de caisse.
 *
 * L'agent local (afficheur client, imprimante thermique, tiroir) n'est pas une
 * dépendance : la caisse fonctionne sans lui, avec l'impression du navigateur.
 * Mais son absence ne doit pas se découvrir au moment où le ticket ne sort pas,
 * devant la file du matin — d'où cette pastille, à côté de celle de
 * synchronisation et sur le même gabarit visuel.
 *
 * Elle ne réclame rien et ne bloque rien : elle informe. Un clic force une
 * nouvelle sonde, pour la caissière qui vient de rebrancher le câble.
 */
export default class extends Controller {
    static targets = ['libelle'];

    connect() {
        this.sonder();
    }

    /** Nouvelle sonde à la demande — l'agent a pu être relancé entre-temps. */
    async sonder() {
        this.rendre(null);
        this.rendre(await pos.verifier());
    }

    /**
     * Trois états, et un seul prend de la couleur.
     *
     * L'absence de matériel est signalée en **stone**, pas en rouge ni en ambre :
     * ce n'est pas une anomalie, c'est la configuration ordinaire de la plupart
     * des postes. Une alerte permanente sur un état normal finit par ne plus rien
     * vouloir dire, et disputerait l'attention au bandeau de synchronisation
     * juste à côté, lui qui signale de vraies ventes en attente.
     */
    rendre(present) {
        const { classes, libelle, aide } = this.decrire(present);

        this.element.className = `${this.element.dataset.baseClasses} ${classes}`;
        this.libelleTarget.textContent = libelle;
        this.element.title = aide;
    }

    decrire(present) {
        if (null === present) {
            return {
                classes: 'bg-stone-100 text-stone-400',
                libelle: 'Matériel',
                aide: 'Recherche de l\'agent matériel local…',
            };
        }

        if (present) {
            return {
                classes: 'bg-green-50 text-green-800 ring-1 ring-green-200',
                libelle: 'Matériel connecté',
                aide: 'Afficheur client, imprimante thermique et tiroir-caisse pilotés par l\'agent local.',
            };
        }

        return {
            classes: 'bg-stone-100 text-stone-500',
            libelle: 'Matériel non connecté',
            aide: 'Aucun agent local : les tickets s\'impriment par le navigateur. Touchez pour réessayer.',
        };
    }
}
