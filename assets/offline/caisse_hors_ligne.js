/*
 * Point d'entrée unique de la caisse hors ligne, partagé par le contrôleur de
 * caisse (qui enfile les ventes) et le bandeau d'état (qui les observe).
 *
 * Assemble : dépôt durable + file de synchronisation + déclencheurs (retour de
 * connexion, réveil périodique) + rafraîchissement du catalogue.
 */
import { DepotIndexedDb } from './depot_indexeddb.js';
import { DepotMemoire } from './depot_memoire.js';
import { FileSynchronisation } from './file_synchronisation.js';
import { interpreterReponse } from './interpreter_reponse.js';

/** Réveil de sécurité : rattrape les cas où l'événement `online` ne se déclenche pas. */
const PERIODE_REVEIL = 15000;

export const URL_VENTE = '/api/vente';
export const URL_CATALOGUE = '/caisse/catalogue.json';

/**
 * Envoie une vente au serveur idempotent. Ne rejette QUE sur une panne réseau :
 * une réponse HTTP, même en erreur, est une information exploitable par la file.
 */
async function envoyerVente(charge) {
    const reponse = await fetch(URL_VENTE, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(charge),
        // Indispensable : la requête peut être rejouée longtemps après, il faut le
        // cookie de session. `same-origin` est le défaut, on l'explicite.
        credentials: 'same-origin',
        // Sans cela, une session expirée (302 vers /login) est suivie par fetch et
        // revient en 200 avec la page de connexion : la file y verrait un succès
        // et supprimerait la vente. Voir interpreter_reponse.js.
        redirect: 'manual',
    });

    return interpreterReponse(reponse);
}

/**
 * Identifiant de la caissière connectée, posé par le serveur sur `<body>`.
 * Lu à chaque appel : Turbo remplace le `<body>` à chaque navigation, et la file,
 * elle, vit aussi longtemps que l'onglet.
 */
function utilisateurCourant() {
    return document.body?.dataset?.utilisateur ?? null;
}

let instance = null;

export function caisseHorsLigne() {
    if (instance) {
        return instance;
    }

    const depot = DepotIndexedDb.estDisponible() ? new DepotIndexedDb() : new DepotMemoire();

    const file = new FileSynchronisation({
        depot,
        envoyer: envoyerVente,
        estEnLigne: () => navigator.onLine,
        proprietaire: utilisateurCourant,
    });

    instance = {
        depot,
        file,
        durable: depot instanceof DepotIndexedDb,

        /** Récupère le catalogue et le range en IndexedDB. Silencieux hors ligne. */
        async rafraichirCatalogue() {
            try {
                const reponse = await fetch(URL_CATALOGUE, { credentials: 'same-origin' });
                if (!reponse.ok) {
                    return null;
                }

                const catalogue = await reponse.json();
                await depot.enregistrerCatalogue(catalogue);

                return catalogue;
            } catch {
                return null;
            }
        },

        async catalogueMemorise() {
            try {
                return await depot.lireCatalogue();
            } catch {
                return null;
            }
        },

        /** Vide la file puis, si tout est passé, remet le catalogue à jour. */
        async synchroniser() {
            if (!navigator.onLine) {
                return file.notifier();
            }

            const resultat = await file.viderJusquAVide();
            if (resultat.restantes === 0) {
                await this.rafraichirCatalogue();
            }

            return file.notifier();
        },

        /** Récupère le ticket local enregistré pour un uuid donné. */
        async ticket(uuid) {
            const entrees = await depot.toutes();
            const entree = entrees.find((item) => item.uuid === uuid);

            return entree?.ticket ?? null;
        },

        /** Branche les déclencheurs de reprise. À appeler une seule fois. */
        demarrer() {
            if (this.demarree) {
                return;
            }
            this.demarree = true;

            // Le retour de la connexion est le déclencheur principal…
            window.addEventListener('online', () => this.synchroniser());
            window.addEventListener('offline', () => file.notifier());

            // …le réveil périodique et le retour au premier plan sont des filets.
            setInterval(() => {
                if (navigator.onLine) {
                    this.synchroniser();
                }
            }, PERIODE_REVEIL);

            document.addEventListener('visibilitychange', () => {
                if (!document.hidden && navigator.onLine) {
                    this.synchroniser();
                }
            });

            this.synchroniser();
        },
    };

    return instance;
}
