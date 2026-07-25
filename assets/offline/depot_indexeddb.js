/*
 * Dépôt IndexedDB de la caisse hors ligne.
 *
 * Deux magasins :
 *   - `catalogue`   : un unique enregistrement, le catalogue produits le plus récent ;
 *   - `file_ventes` : la file des ventes encaissées restant à transmettre au serveur,
 *                     indexée par l'uuid généré côté client.
 *
 * IndexedDB est le seul stockage durable qui survive à une fermeture d'onglet, une
 * coupure de courant ou un rechargement : c'est pourquoi toute vente y est écrite
 * AVANT le moindre appel réseau.
 *
 * Ce module implémente le contrat attendu par FileSynchronisation ; un dépôt en
 * mémoire équivalent existe pour les tests (depot_memoire.js).
 */

const NOM_BASE = 'zedpos';
const VERSION = 1;
const MAGASIN_CATALOGUE = 'catalogue';
const MAGASIN_FILE = 'file_ventes';
const CLE_CATALOGUE = 'courant';

export class DepotIndexedDb {
    constructor(nomBase = NOM_BASE) {
        this.nomBase = nomBase;
        this.basePromise = null;
    }

    static estDisponible() {
        return typeof indexedDB !== 'undefined';
    }

    base() {
        if (this.basePromise) {
            return this.basePromise;
        }

        this.basePromise = new Promise((resoudre, rejeter) => {
            const requete = indexedDB.open(this.nomBase, VERSION);

            requete.onupgradeneeded = () => {
                const base = requete.result;
                if (!base.objectStoreNames.contains(MAGASIN_CATALOGUE)) {
                    base.createObjectStore(MAGASIN_CATALOGUE);
                }
                if (!base.objectStoreNames.contains(MAGASIN_FILE)) {
                    const magasin = base.createObjectStore(MAGASIN_FILE, { keyPath: 'uuid' });
                    magasin.createIndex('statut', 'statut');
                }
            };

            requete.onsuccess = () => resoudre(requete.result);
            requete.onerror = () => rejeter(requete.error);
        });

        return this.basePromise;
    }

    async transaction(magasin, mode, operation) {
        const base = await this.base();

        return new Promise((resoudre, rejeter) => {
            const tx = base.transaction(magasin, mode);
            const requete = operation(tx.objectStore(magasin));

            // On attend la fin de la transaction, pas seulement celle de la requête :
            // c'est la validation de la transaction qui garantit la durabilité.
            tx.oncomplete = () => resoudre(requete ? requete.result : undefined);
            tx.onerror = () => rejeter(tx.error);
            tx.onabort = () => rejeter(tx.error);
        });
    }

    // ---------------------------------------------------------------- Catalogue

    async enregistrerCatalogue(catalogue) {
        await this.transaction(MAGASIN_CATALOGUE, 'readwrite', (magasin) => magasin.put(catalogue, CLE_CATALOGUE));
    }

    async lireCatalogue() {
        return (await this.transaction(MAGASIN_CATALOGUE, 'readonly', (magasin) => magasin.get(CLE_CATALOGUE))) ?? null;
    }

    // --------------------------------------------------------- File des ventes

    /** Écriture durable d'une vente à transmettre. Rejette si l'écriture échoue. */
    async ajouter(entree) {
        // `add` (et non `put`) : si l'uuid est déjà présent, on ne veut surtout pas
        // écraser une entrée en cours de traitement.
        await this.transaction(MAGASIN_FILE, 'readwrite', (magasin) => magasin.add(entree));
    }

    async mettreAJour(entree) {
        await this.transaction(MAGASIN_FILE, 'readwrite', (magasin) => magasin.put(entree));
    }

    async supprimer(uuid) {
        await this.transaction(MAGASIN_FILE, 'readwrite', (magasin) => magasin.delete(uuid));
    }

    async toutes() {
        return (await this.transaction(MAGASIN_FILE, 'readonly', (magasin) => magasin.getAll())) ?? [];
    }
}
