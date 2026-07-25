/*
 * Dépôt en mémoire : même contrat que DepotIndexedDb, sans navigateur.
 *
 * Sert aux tests automatisés (node --test) et de repli si IndexedDB est
 * indisponible — dans ce cas la file ne survit pas au rechargement de la page,
 * ce que l'appelant doit signaler à l'utilisateur.
 */
export class DepotMemoire {
    constructor() {
        this.catalogue = null;
        this.entrees = new Map();
    }

    async enregistrerCatalogue(catalogue) {
        this.catalogue = catalogue;
    }

    async lireCatalogue() {
        return this.catalogue;
    }

    async ajouter(entree) {
        if (this.entrees.has(entree.uuid)) {
            throw new Error(`Vente déjà enfilée : ${entree.uuid}`);
        }
        this.entrees.set(entree.uuid, { ...entree });
    }

    async mettreAJour(entree) {
        this.entrees.set(entree.uuid, { ...entree });
    }

    async supprimer(uuid) {
        this.entrees.delete(uuid);
    }

    async toutes() {
        return [...this.entrees.values()].map((entree) => ({ ...entree }));
    }
}
