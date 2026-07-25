/*
 * Tests de la file de synchronisation des ventes hors ligne.
 *
 * Lancement : `node --test tests/js/` (aucune dépendance npm).
 *
 * Scénario central : 20 ventes encaissées réseau coupé, puis reconnexion.
 * Invariants vérifiés — aucune vente perdue, aucune vente dupliquée.
 */
import { strict as assert } from 'node:assert';
import { describe, it } from 'node:test';

import { DepotMemoire } from '../../assets/offline/depot_memoire.js';
import { BLOQUEE, EN_ATTENTE, FileSynchronisation } from '../../assets/offline/file_synchronisation.js';

/**
 * Serveur simulé, idempotent sur l'uuid — reproduit le comportement de
 * POST /api/vente : 201 à la création, 200 au rejeu, jamais de doublon.
 */
function serveurIdempotent() {
    const recues = new Map();

    return {
        recues,
        enLigne: true,
        /** Nombre total d'appels réseau reçus, rejeux compris. */
        appels: 0,
        reponsePersonnalisee: null,

        async envoyer(charge) {
            this.appels += 1;

            if (!this.enLigne) {
                throw new TypeError('Failed to fetch');
            }
            if (this.reponsePersonnalisee) {
                return this.reponsePersonnalisee;
            }

            if (recues.has(charge.uuid)) {
                return { statut: 200, corps: { ok: true, uuid: charge.uuid, numero: recues.get(charge.uuid) } };
            }

            const numero = `V-${String(recues.size + 1).padStart(5, '0')}`;
            recues.set(charge.uuid, numero);

            return { statut: 201, corps: { ok: true, uuid: charge.uuid, numero } };
        },
    };
}

/** File de test : horloge et temporisation contrôlées, aucune attente réelle. */
function creerFile(serveur, options = {}) {
    const horloge = { valeur: 0 };
    const depot = new DepotMemoire();

    const file = new FileSynchronisation({
        depot,
        envoyer: (charge) => serveur.envoyer(charge),
        maintenant: () => horloge.valeur,
        // Avancer l'horloge au lieu d'attendre : les tests restent instantanés.
        attendre: async (ms) => { horloge.valeur += ms; },
        estEnLigne: () => serveur.enLigne,
        ...options,
    });

    return { file, depot, horloge };
}

function ticket(index) {
    return {
        mode: 'BOULANGERIE',
        lignes: [{ articleId: 1, quantite: index + 1, commentaire: '' }],
        reglements: [{ mode: 'ESPECES', montant: 15000 * (index + 1) }],
    };
}

describe('FileSynchronisation — 20 ventes hors ligne puis reconnexion', () => {
    it('transmet exactement une fois chaque vente, sans perte ni doublon', async () => {
        const serveur = serveurIdempotent();
        const { file, depot } = creerFile(serveur);

        // --- Réseau coupé : 20 encaissements ---------------------------------
        serveur.enLigne = false;
        const uuids = [];

        for (let i = 0; i < 20; i += 1) {
            const uuid = `uuid-${i}`;
            uuids.push(uuid);
            await file.enfiler(uuid, { uuid, ...ticket(i) });
        }

        // Tout est durablement enregistré, rien n'est parti.
        assert.equal((await depot.toutes()).length, 20, 'les 20 ventes sont enregistrées localement');
        assert.equal(serveur.recues.size, 0, 'aucune vente reçue par le serveur hors ligne');

        // Un vidage hors ligne ne doit rien perdre.
        await file.vider();
        assert.equal((await depot.toutes()).length, 20, 'un vidage hors ligne ne supprime rien');

        // --- Reconnexion ------------------------------------------------------
        serveur.enLigne = true;
        const resultat = await file.viderJusquAVide();

        assert.equal(resultat.restantes, 0, 'la file est vide après reconnexion');
        assert.equal(resultat.bloquees, 0);
        assert.equal((await depot.toutes()).length, 0);

        // Le serveur a reçu les 20 ventes, chacune une seule fois.
        assert.equal(serveur.recues.size, 20, 'les 20 ventes sont arrivées');
        assert.deepEqual([...serveur.recues.keys()].sort(), [...uuids].sort(), 'aucun uuid manquant');
    });

    it("ne duplique rien même si la réponse du serveur se perd avant d'arriver", async () => {
        const serveur = serveurIdempotent();
        const { file, depot } = creerFile(serveur);

        serveur.enLigne = false;
        for (let i = 0; i < 20; i += 1) {
            await file.enfiler(`uuid-${i}`, { uuid: `uuid-${i}`, ...ticket(i) });
        }

        // Le serveur enregistre, mais la réponse n'atteint jamais la caisse :
        // c'est le cas le plus dangereux — le client croit avoir échoué.
        serveur.enLigne = true;
        let coupures = 0;
        const envoiOriginal = serveur.envoyer.bind(serveur);
        serveur.envoyer = async (charge) => {
            const reponse = await envoiOriginal(charge);
            if (coupures < 20) {
                coupures += 1;
                throw new TypeError('Failed to fetch'); // coupure après enregistrement
            }

            return reponse;
        };

        await file.viderJusquAVide();

        // Le serveur a bien tout reçu, et les rejeux n'ont créé aucun doublon.
        assert.equal(serveur.recues.size, 20, 'exactement 20 ventes côté serveur');
        assert.equal((await depot.toutes()).length, 0, 'la file finit vide');
        assert.ok(serveur.appels > 20, 'des rejeux ont bien eu lieu');
    });

    it('conserve les ventes tant que le serveur ne les a pas confirmées', async () => {
        const serveur = serveurIdempotent();
        const { file, depot } = creerFile(serveur);

        await file.enfiler('uuid-a', { uuid: 'uuid-a', ...ticket(0) });

        // 503 : incident serveur, réessayable.
        serveur.reponsePersonnalisee = { statut: 503, corps: { erreur: 'Service indisponible' } };
        await file.vider();

        const [entree] = await depot.toutes();
        assert.equal(entree.statut, EN_ATTENTE, 'toujours en attente');
        assert.equal(entree.tentatives, 1);

        // Rétablissement.
        serveur.reponsePersonnalisee = null;
        await file.viderJusquAVide();
        assert.equal((await depot.toutes()).length, 0);
        assert.equal(serveur.recues.size, 1);
    });

    it('réessaie après une session expirée (401) sans rien perdre', async () => {
        const serveur = serveurIdempotent();
        const { file, depot } = creerFile(serveur);

        await file.enfiler('uuid-a', { uuid: 'uuid-a', ...ticket(0) });

        serveur.reponsePersonnalisee = { statut: 401, corps: null };
        await file.vider();

        const [entree] = await depot.toutes();
        assert.equal(entree.statut, EN_ATTENTE, 'une session expirée est temporaire, pas définitive');

        serveur.reponsePersonnalisee = null;
        await file.viderJusquAVide();
        assert.equal(serveur.recues.size, 1);
    });

    it('marque « bloquée » sans jamais supprimer sur refus définitif', async () => {
        const serveur = serveurIdempotent();
        const { file, depot } = creerFile(serveur);

        await file.enfiler('uuid-a', { uuid: 'uuid-a', ...ticket(0) });

        serveur.reponsePersonnalisee = { statut: 400, corps: { erreur: 'Article indisponible.' } };
        const resultat = await file.viderJusquAVide();

        assert.equal(resultat.bloquees, 1);
        const [entree] = await depot.toutes();
        assert.equal(entree.statut, BLOQUEE, 'conservée pour intervention humaine');
        assert.equal(entree.erreur, 'Article indisponible.');
        assert.equal((await depot.toutes()).length, 1, 'une vente refusée n’est jamais supprimée');
    });
});

describe('FileSynchronisation — relance exponentielle', () => {
    it('double le délai à chaque tentative, jusqu’à un plafond', async () => {
        const serveur = serveurIdempotent();
        const { file } = creerFile(serveur, { delaiBase: 1000, delaiMax: 16000 });

        const delais = [1, 2, 3, 4, 5, 6, 7].map((n) => file.delai(n));

        // Gigue de ±12,5 % : on vérifie l'ordre de grandeur, pas la valeur exacte.
        const attendus = [1000, 2000, 4000, 8000, 16000, 16000, 16000];
        delais.forEach((delai, index) => {
            const cible = attendus[index];
            assert.ok(
                delai >= cible * 0.87 && delai <= cible * 1.13,
                `tentative ${index + 1} : ${delai} ms attendu autour de ${cible} ms`,
            );
        });
    });

    it('respecte le délai avant de réessayer une entrée', async () => {
        const serveur = serveurIdempotent();
        const { file, depot, horloge } = creerFile(serveur, { delaiBase: 5000, delaiMax: 5000 });

        await file.enfiler('uuid-a', { uuid: 'uuid-a', ...ticket(0) });

        serveur.enLigne = false;
        await file.vider();
        const appelsApresEchec = serveur.appels;

        // Trop tôt : aucune nouvelle tentative.
        serveur.enLigne = true;
        await file.vider();
        assert.equal(serveur.appels, appelsApresEchec, 'le délai de relance est respecté');

        // Une fois le délai écoulé, la vente part.
        horloge.valeur += 6000;
        await file.vider();
        assert.equal(serveur.recues.size, 1);
        assert.equal((await depot.toutes()).length, 0);
    });
});

describe('FileSynchronisation — état pour le bandeau', () => {
    it('reflète le nombre de ventes en attente et le mode hors ligne', async () => {
        const serveur = serveurIdempotent();
        const { file } = creerFile(serveur);
        const etats = [];
        file.surChangement((etat) => etats.push(etat));

        serveur.enLigne = false;
        await file.enfiler('uuid-a', { uuid: 'uuid-a', ...ticket(0) });
        await file.enfiler('uuid-b', { uuid: 'uuid-b', ...ticket(1) });

        const dernier = etats.at(-1);
        assert.equal(dernier.enAttente, 2);
        assert.equal(dernier.enLigne, false);
        assert.equal(dernier.bloquees, 0);

        serveur.enLigne = true;
        await file.viderJusquAVide();

        const final = await file.notifier();
        assert.equal(final.enAttente, 0);
        assert.equal(final.enLigne, true);
    });

    it('refuse deux entrées portant le même uuid', async () => {
        const serveur = serveurIdempotent();
        const { file, depot } = creerFile(serveur);

        await file.enfiler('uuid-a', { uuid: 'uuid-a', ...ticket(0) });
        await assert.rejects(() => file.enfiler('uuid-a', { uuid: 'uuid-a', ...ticket(0) }));

        assert.equal((await depot.toutes()).length, 1);
    });
});
