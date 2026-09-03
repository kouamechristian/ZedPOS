import { Controller } from '@hotwired/stimulus';
import { pos } from '../js/pos-agent.js';

/*
 * Bouton « Réimprimer » de la liste des ventes du back-office.
 *
 * Un ticket sort mal, le client en redemande un, le gérant doit en joindre un à
 * sa caisse : le ticket ressort **à l'identique**, depuis la vente enregistrée —
 * jamais recomposé ici. La charge utile vient du serveur
 * (`/caisse/ticket/{uuid}/materiel`), qui la construit avec le même service que
 * le ticket d'origine.
 *
 * Le tiroir ne s'ouvre pas : le serveur force `openDrawer` à faux sur cette
 * route. Une réimpression ne fait pas entrer d'argent, et un bouton qui ouvre le
 * tiroir depuis un écran de gestion serait un moyen commode de l'ouvrir sans
 * vente.
 *
 * Repli sans agent : la page ticket 58 mm dans un nouvel onglet, où le gérant
 * imprime par le navigateur. Le bouton fait donc toujours quelque chose.
 */
export default class extends Controller {
    static values = { materielUrl: String, ticketUrl: String };

    async imprimer(event) {
        const bouton = event.currentTarget;
        const libelle = bouton.textContent;

        // Le retour doit être immédiat : l'agent met une seconde ou deux à rendre
        // la main, et sans repère la caissière appuie une seconde fois.
        bouton.disabled = true;
        bouton.textContent = 'Impression…';

        try {
            if (await pos.available() && await pos.print(await this.ticket())) {
                bouton.textContent = 'Imprimé';

                return;
            }

            // Pas d'agent, ou impression refusée : le gérant passe par le
            // navigateur, comme avant.
            window.open(this.ticketUrlValue, '_blank', 'noopener');
        } finally {
            setTimeout(() => {
                bouton.disabled = false;
                bouton.textContent = libelle;
            }, 1500);
        }
    }

    async ticket() {
        try {
            const reponse = await fetch(this.materielUrlValue, { credentials: 'same-origin' });

            return reponse.ok ? (await reponse.json()).ticket : null;
        } catch {
            return null;
        }
    }
}
