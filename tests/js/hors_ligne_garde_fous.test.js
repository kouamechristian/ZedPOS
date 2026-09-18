/*
 * Garde-fous du hors ligne contre l'excédent de caisse :
 *
 *  - une session expirée ne doit jamais faire passer une vente pour transmise ;
 *  - une vente ne repart que sous le compte qui l'a encaissée.
 *
 * Lancement : `node --test tests/js/`.
 */
import { strict as assert } from 'node:assert';
import { describe, it } from 'node:test';

import { DepotMemoire } from '../../assets/offline/depot_memoire.js';
import { BLOQUEE, EN_ATTENTE, FileSynchronisation } from '../../assets/offline/file_synchronisation.js';
import { interpreterReponse } from '../../assets/offline/interpreter_reponse.js';

/** Fausse réponse `fetch`. */
function reponse({ status = 200, type = 'basic', redirected = false, corps }) {
    return {
        status,
        type,
        redirected,
        json: async () => {
            if (undefined === corps) {
                throw new SyntaxError('Unexpected token < in JSON');
            }

            return corps;
        },
    };
}

describe('interpreterReponse — la session expirée n’est jamais un succès', () => {
    it('redirection non suivie (opaqueredirect) → 401, la vente reste en file', async () => {
        const lu = await interpreterReponse(reponse({ status: 0, type: 'opaqueredirect' }));

        assert.equal(lu.statut, 401);
    });

    it('page de connexion servie en 200 après redirection → jamais un succès', async () => {
        const lu = await interpreterReponse(reponse({ status: 200, redirected: true }));

        assert.equal(lu.statut, 401);
    });

    it('200 en HTML (portail captif, proxy) → temporaire, jamais un succès', async () => {
        const lu = await interpreterReponse(reponse({ status: 200 }));

        assert.equal(lu.statut, 502);
    });

    it('200 en JSON sans accusé « ok » → temporaire', async () => {
        const lu = await interpreterReponse(reponse({ status: 200, corps: { message: 'x' } }));

        assert.equal(lu.statut, 502);
    });

    it('201 et 200 avec accusé « ok: true » → succès, statut conservé', async () => {
        const cree = await interpreterReponse(reponse({ status: 201, corps: { ok: true, numero: 'V1' } }));
        const rejoue = await interpreterReponse(reponse({ status: 200, corps: { ok: true, numero: 'V1' } }));

        assert.equal(cree.statut, 201);
        assert.equal(rejoue.statut, 200);
    });

    it('un refus métier garde son statut et son message', async () => {
        const lu = await interpreterReponse(reponse({ status: 422, corps: { ok: false, erreur: 'Paiement insuffisant.' } }));

        assert.equal(lu.statut, 422);
        assert.equal(lu.corps.erreur, 'Paiement insuffisant.');
    });

    it('bout en bout : la file conserve la vente quand la session a expiré', async () => {
        const depot = new DepotMemoire();
        const file = new FileSynchronisation({
            depot,
            // Ce que renvoie réellement le pare-feu : 302 vers /login, suivi ou non.
            envoyer: async () => interpreterReponse(reponse({ status: 200, redirected: true })),
        });

        await file.enfiler('uuid-a', { uuid: 'uuid-a' });
        await file.vider();

        const [entree] = await depot.toutes();
        assert.ok(entree, 'la vente est toujours dans la file');
        assert.equal(entree.statut, EN_ATTENTE);
    });
});

/** Serveur qui note, pour chaque vente reçue, sous quel compte elle arrive. */
function serveurParCompte(compte) {
    const recues = [];

    return {
        recues,
        async envoyer(charge) {
            recues.push({ uuid: charge.uuid, compte: compte.courant });

            return { statut: 201, corps: { ok: true } };
        },
    };
}

describe('FileSynchronisation — une vente repart sous le compte qui l’a encaissée', () => {
    it('les ventes de Fatou ne sont pas envoyées quand Yao est connecté', async () => {
        const compte = { courant: 'fatou' };
        const serveur = serveurParCompte(compte);
        const depot = new DepotMemoire();
        const file = new FileSynchronisation({
            depot,
            envoyer: (charge) => serveur.envoyer(charge),
            proprietaire: () => compte.courant,
        });

        // Fatou encaisse deux ventes hors ligne, puis quitte la tablette.
        await file.enfiler('f-1', { uuid: 'f-1' });
        await file.enfiler('f-2', { uuid: 'f-2' });

        // Yao se connecte et encaisse une vente.
        compte.courant = 'yao';
        await file.enfiler('y-1', { uuid: 'y-1' });

        const resultat = await file.viderJusquAVide();

        assert.deepEqual(serveur.recues, [{ uuid: 'y-1', compte: 'yao' }], 'seule la vente de Yao part');
        assert.equal(resultat.restantes, 0, 'pour Yao, la file est vide');
        assert.equal((await depot.toutes()).length, 2, 'les ventes de Fatou attendent sur la tablette');
    });

    it('elles repartent dès que Fatou se reconnecte, sous son compte', async () => {
        const compte = { courant: 'fatou' };
        const serveur = serveurParCompte(compte);
        const file = new FileSynchronisation({
            depot: new DepotMemoire(),
            envoyer: (charge) => serveur.envoyer(charge),
            proprietaire: () => compte.courant,
        });

        await file.enfiler('f-1', { uuid: 'f-1' });
        compte.courant = 'yao';
        await file.vider();
        assert.equal(serveur.recues.length, 0);

        compte.courant = 'fatou';
        await file.viderJusquAVide();

        assert.deepEqual(serveur.recues, [{ uuid: 'f-1', compte: 'fatou' }]);
    });

    it('l’état du bandeau ne compte que les ventes du compte connecté, et signale les autres', async () => {
        const compte = { courant: 'fatou' };
        const file = new FileSynchronisation({
            depot: new DepotMemoire(),
            envoyer: async () => ({ statut: 201, corps: { ok: true } }),
            proprietaire: () => compte.courant,
            estEnLigne: () => false,
        });

        await file.enfiler('f-1', { uuid: 'f-1' });
        await file.enfiler('f-2', { uuid: 'f-2' });
        compte.courant = 'yao';
        await file.enfiler('y-1', { uuid: 'y-1' });

        const etat = await file.notifier();

        assert.equal(etat.enAttente, 1, 'Yao ne voit que sa vente');
        assert.equal(etat.autres, 2, 'et sait que deux ventes d’une autre caissière attendent');
    });

    it('une vente bloquée d’un autre compte ne bloque pas la clôture de celui-ci', async () => {
        const compte = { courant: 'fatou' };
        const depot = new DepotMemoire();
        const file = new FileSynchronisation({
            depot,
            envoyer: async () => ({ statut: 422, corps: { erreur: 'Article indisponible.' } }),
            proprietaire: () => compte.courant,
        });

        await file.enfiler('f-1', { uuid: 'f-1' });
        await file.vider();
        assert.equal((await depot.toutes())[0].statut, BLOQUEE);

        compte.courant = 'yao';
        const etat = await file.notifier();

        assert.equal(etat.bloquees, 0);
        assert.equal(etat.enAttente, 0);
        assert.equal(etat.autres, 1);
    });

    it('une entrée sans propriétaire (ancienne file) reste transmissible', async () => {
        const compte = { courant: 'yao' };
        const serveur = serveurParCompte(compte);
        const depot = new DepotMemoire();
        const file = new FileSynchronisation({
            depot,
            envoyer: (charge) => serveur.envoyer(charge),
            proprietaire: () => compte.courant,
        });

        // Écrite avant l'introduction du propriétaire : aucun `utilisateurId`.
        await depot.ajouter({
            uuid: 'vieille', charge: { uuid: 'vieille' }, ticket: null, statut: EN_ATTENTE,
            tentatives: 0, creeA: 0, prochaineTentativeA: 0, erreur: null,
        });

        await file.viderJusquAVide();

        assert.equal(serveur.recues.length, 1);
    });

    it('sans compte connu (page sans marqueur), rien n’est filtré : comportement d’origine', async () => {
        const serveur = serveurParCompte({ courant: null });
        const file = new FileSynchronisation({
            depot: new DepotMemoire(),
            envoyer: (charge) => serveur.envoyer(charge),
        });

        await file.enfiler('a', { uuid: 'a' });
        await file.viderJusquAVide();

        assert.equal(serveur.recues.length, 1);
    });
});

describe('FileSynchronisation — ventes à vérifier', () => {
    /** Une file avec une vente refusée par le serveur (article retiré, prix changé…). */
    async function fileAvecVenteRefusee(compte = { courant: 'fatou' }) {
        const depot = new DepotMemoire();
        const file = new FileSynchronisation({
            depot,
            envoyer: async () => ({ statut: 422, corps: { erreur: 'Le prix de « Baguette » a changé.' } }),
            proprietaire: () => compte.courant,
        });

        await file.enfiler('v-1', { uuid: 'v-1' });
        await file.vider();

        return { file, depot, compte };
    }

    it('liste les ventes refusées du compte connecté seulement', async () => {
        const { file, compte } = await fileAvecVenteRefusee();

        assert.equal((await file.bloquees()).length, 1);

        compte.courant = 'yao';
        assert.equal((await file.bloquees()).length, 0, 'la vente de Fatou ne se traite pas sous le compte de Yao');
    });

    it('« réessayer » remet la vente en attente, prête à repartir', async () => {
        const { file, depot } = await fileAvecVenteRefusee();

        assert.equal(await file.reessayer('v-1'), true);

        const [entree] = await depot.toutes();
        assert.equal(entree.statut, EN_ATTENTE);
        assert.equal(entree.erreur, null);
        assert.equal(entree.tentatives, 0);
    });

    it('« réessayer » ne touche pas une vente qui n’est pas bloquée', async () => {
        const depot = new DepotMemoire();
        const file = new FileSynchronisation({ depot, envoyer: async () => ({ statut: 201, corps: { ok: true } }) });
        await file.enfiler('v-2', { uuid: 'v-2' });

        assert.equal(await file.reessayer('v-2'), false);
        assert.equal(await file.reessayer('inconnue'), false);
    });

    it('« retirer » n’efface la vente que si le serveur en a pris acte', async () => {
        const { file, depot } = await fileAvecVenteRefusee();
        let declaree = null;

        const retiree = await file.retirerBloquee('v-1', async (entree) => {
            declaree = entree.uuid;

            return true;
        });

        assert.equal(retiree, true);
        assert.equal(declaree, 'v-1', 'le serveur a reçu la déclaration de cette vente');
        assert.equal((await depot.toutes()).length, 0);
    });

    it('réseau coupé ou refus du serveur : la vente reste, rien n’est perdu', async () => {
        const { file, depot } = await fileAvecVenteRefusee();

        assert.equal(await file.retirerBloquee('v-1', async () => false), false, 'le serveur n’a pas confirmé');
        assert.equal(await file.retirerBloquee('v-1', async () => { throw new TypeError('Failed to fetch'); }), false, 'réseau coupé');
        assert.equal(await file.retirerBloquee('v-1', async () => undefined), false, 'réponse ambiguë');

        assert.equal((await depot.toutes()).length, 1, 'la vente est toujours sur la tablette');
    });

    it('ne retire pas la vente bloquée d’une autre caissière', async () => {
        const { file, depot, compte } = await fileAvecVenteRefusee();
        compte.courant = 'yao';

        assert.equal(await file.retirerBloquee('v-1', async () => true), false);
        assert.equal((await depot.toutes()).length, 1);
    });
});
