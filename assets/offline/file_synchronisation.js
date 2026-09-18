/*
 * File de synchronisation des ventes encaissées hors ligne.
 *
 * Garanties recherchées, dans cet ordre de priorité :
 *
 *  1. AUCUNE VENTE PERDUE — l'entrée est écrite dans le dépôt durable AVANT tout
 *     appel réseau, et n'en sort que sur un succès serveur confirmé. Une erreur
 *     métier définitive ne supprime rien : l'entrée est marquée « bloquée » et
 *     reste consultable.
 *  2. AUCUNE VENTE DUPLIQUÉE — chaque entrée porte un uuid généré à l'encaissement
 *     et jamais réémis. Le serveur (POST /api/vente) est idempotent sur cet uuid :
 *     rejouer une requête déjà traitée renvoie 200 au lieu de 201, sans créer de
 *     doublon. Un envoi dont on ne connaît pas l'issue (coupure pendant la réponse)
 *     peut donc être rejoué sans risque.
 *
 * Ce module ne dépend d'aucune API navigateur : le dépôt, la fonction d'envoi et
 * l'horloge sont injectés. Il est testable tel quel sous Node.
 */

/** États possibles d'une entrée de la file. */
export const EN_ATTENTE = 'EN_ATTENTE';
export const BLOQUEE = 'BLOQUEE';

/** Codes HTTP qui valent succès : 201 création, 200 rejeu idempotent. */
const SUCCES = [200, 201];

/**
 * Codes qui traduisent une erreur définitive de la requête elle-même : la rejouer
 * à l'identique donnera toujours le même résultat. On cesse de réessayer, mais on
 * conserve l'entrée pour intervention humaine.
 *
 * 401/403 (session expirée) et 409 (pas de session de caisse ouverte) en sont
 * volontairement exclus : ils se résolvent dès que le caissier se reconnecte ou
 * rouvre sa caisse.
 */
const DEFINITIFS = [400, 404, 422];

export class FileSynchronisation {
    /**
     * @param {object}   options
     * @param {object}   options.depot      dépôt durable (DepotIndexedDb ou DepotMemoire)
     * @param {Function} options.envoyer    (charge) => Promise<{statut, corps}> ; rejette si le réseau est coupé
     * @param {Function} [options.maintenant] horloge injectable (ms)
     * @param {Function} [options.attendre]   temporisation injectable (ms) => Promise
     * @param {number}   [options.delaiBase]  délai de la première relance, en ms
     * @param {number}   [options.delaiMax]   plafond du délai de relance, en ms
     * @param {Function} [options.estEnLigne] () => bool
     * @param {Function} [options.proprietaire] () => identifiant de la caissière connectée, ou null
     */
    constructor({
        depot,
        envoyer,
        maintenant = () => Date.now(),
        attendre = (ms) => new Promise((r) => setTimeout(r, ms)),
        delaiBase = 2000,
        delaiMax = 300000,
        estEnLigne = () => true,
        proprietaire = () => null,
    }) {
        this.depot = depot;
        this.envoyer = envoyer;
        this.maintenant = maintenant;
        this.attendre = attendre;
        this.delaiBase = delaiBase;
        this.delaiMax = delaiMax;
        this.estEnLigne = estEnLigne;
        this.proprietaire = proprietaire;

        this.enCours = false;
        this.ecouteurs = new Set();
    }

    // ------------------------------------------------------------ Propriétaire

    /**
     * Une vente appartient à la caissière qui l'a encaissée. Le serveur, lui,
     * l'enregistre dans la session de **celle qui est connectée** au moment de
     * l'envoi : sur une tablette partagée, les ventes de Fatou envoyées après la
     * connexion de Yao tomberaient dans la caisse de Yao — excédent chez l'une,
     * manquant chez l'autre. Chaque compte ne vide donc que sa propre file.
     *
     * Une entrée sans propriétaire (écrite avant ce garde-fou) reste transmissible
     * par tous : mieux vaut la voir partir que la laisser bloquée pour toujours.
     * Si le compte connecté est inconnu (tests, page sans marqueur), rien n'est
     * filtré : le comportement d'origine.
     */
    estAMoi(entree) {
        const moi = this.proprietaire();

        return null === moi || undefined === entree.utilisateurId || null === entree.utilisateurId
            || String(entree.utilisateurId) === String(moi);
    }

    // ------------------------------------------------------------- Observation

    surChangement(ecouteur) {
        this.ecouteurs.add(ecouteur);

        return () => this.ecouteurs.delete(ecouteur);
    }

    async notifier() {
        const entrees = await this.depot.toutes();
        const miennes = entrees.filter((e) => this.estAMoi(e));
        const etat = {
            enAttente: miennes.filter((e) => e.statut === EN_ATTENTE).length,
            bloquees: miennes.filter((e) => e.statut === BLOQUEE).length,
            // Ventes d'une autre caissière restées sur cette tablette : elles
            // attendent son retour, elles ne partiront pas sous ce compte.
            autres: entrees.length - miennes.length,
            synchronisation: this.enCours,
            enLigne: this.estEnLigne(),
        };

        this.ecouteurs.forEach((ecouteur) => ecouteur(etat));

        return etat;
    }

    // ------------------------------------------------------------- Alimentation

    /**
     * Enregistre durablement une vente à transmettre. À appeler AVANT tout appel
     * réseau : c'est le point à partir duquel la vente ne peut plus être perdue.
     *
     * @param {string} uuid   identifiant idempotent généré à l'encaissement
     * @param {object} charge corps JSON destiné à POST /api/vente
     */
    async enfiler(uuid, charge, ticket = null) {
        await this.depot.ajouter({
            uuid,
            charge,
            ticket,
            utilisateurId: this.proprietaire(),
            statut: EN_ATTENTE,
            tentatives: 0,
            creeA: this.maintenant(),
            prochaineTentativeA: this.maintenant(),
            erreur: null,
        });

        await this.notifier();

        return uuid;
    }

    // ------------------------------------------------------------------- Vidage

    /**
     * Tente de transmettre toutes les entrées dues. Un seul vidage à la fois : deux
     * exécutions concurrentes pourraient émettre deux fois la même entrée (sans
     * conséquence grâce à l'idempotence, mais autant l'éviter).
     *
     * @returns {Promise<{transmises: number, restantes: number, bloquees: number}>}
     */
    async vider() {
        if (this.enCours) {
            return this.resume();
        }

        this.enCours = true;
        await this.notifier();

        let transmises = 0;

        try {
            for (const entree of await this.depot.toutes()) {
                if (!this.estAMoi(entree) || entree.statut !== EN_ATTENTE || entree.prochaineTentativeA > this.maintenant()) {
                    continue;
                }
                if (await this.transmettre(entree)) {
                    transmises += 1;
                }
            }
        } finally {
            this.enCours = false;
        }

        await this.notifier();

        return { ...(await this.resume()), transmises };
    }

    /**
     * Transmet une entrée. Renvoie true si le serveur l'a acceptée (ou l'avait déjà).
     */
    async transmettre(entree) {
        let reponse;

        try {
            reponse = await this.envoyer(entree.charge);
        } catch (erreur) {
            // Réseau coupé ou requête interrompue : on ignore si le serveur a reçu
            // la vente. On la garde et on rejouera — l'idempotence absorbe le doublon.
            await this.reporter(entree, erreur?.message ?? 'Réseau indisponible');

            return false;
        }

        if (SUCCES.includes(reponse.statut)) {
            // Seul cas où l'entrée quitte le dépôt : le serveur a confirmé.
            await this.depot.supprimer(entree.uuid);

            return true;
        }

        if (DEFINITIFS.includes(reponse.statut)) {
            await this.depot.mettreAJour({
                ...entree,
                statut: BLOQUEE,
                erreur: reponse.corps?.erreur ?? `Refus serveur (${reponse.statut})`,
            });

            return false;
        }

        // 401, 403, 409, 5xx… : temporaire, on réessaiera.
        await this.reporter(entree, reponse.corps?.erreur ?? `Erreur serveur (${reponse.statut})`);

        return false;
    }

    /** Replanifie une entrée avec une relance exponentielle bornée. */
    async reporter(entree, erreur) {
        const tentatives = entree.tentatives + 1;

        await this.depot.mettreAJour({
            ...entree,
            tentatives,
            erreur,
            prochaineTentativeA: this.maintenant() + this.delai(tentatives),
        });
    }

    /**
     * Relance exponentielle : base × 2^(n−1), plafonnée, avec une gigue de ±12,5 %
     * pour éviter que plusieurs caisses ne repartent toutes à la même seconde.
     */
    delai(tentatives) {
        const brut = Math.min(this.delaiBase * 2 ** (tentatives - 1), this.delaiMax);
        const gigue = brut * 0.125 * (Math.random() * 2 - 1);

        return Math.round(brut + gigue);
    }

    // ------------------------------------------------------- Ventes à vérifier

    /** Ventes refusées par le serveur, de la caissière connectée. Jamais supprimées d'elles-mêmes. */
    async bloquees() {
        return (await this.depot.toutes()).filter((e) => e.statut === BLOQUEE && this.estAMoi(e));
    }

    /**
     * Remet une vente refusée dans la file, pour un nouvel essai. Utile quand la
     * cause a disparu : article réactivé, caisse rouverte, connexion rétablie.
     *
     * @returns {Promise<boolean>} faux si la vente n'existe pas ou n'est pas bloquée
     */
    async reessayer(uuid) {
        const entree = (await this.bloquees()).find((e) => e.uuid === uuid);
        if (!entree) {
            return false;
        }

        await this.depot.mettreAJour({
            ...entree,
            statut: EN_ATTENTE,
            tentatives: 0,
            erreur: null,
            prochaineTentativeA: this.maintenant(),
        });
        await this.notifier();

        return true;
    }

    /**
     * Retire une vente refusée de la tablette — **à condition que le serveur en ait
     * pris acte**. Une vente bloquée n'existe qu'ici : l'effacer sans trace ferait
     * disparaître l'unique preuve d'un argent entré dans le tiroir. `declarer`
     * consigne la vente côté serveur ; s'il ne confirme pas (réseau coupé, refus),
     * la vente reste en place.
     *
     * @param {string}   uuid
     * @param {Function} declarer (entree) => Promise<boolean> ; vrai seulement si le serveur a enregistré la déclaration
     * @returns {Promise<boolean>}
     */
    async retirerBloquee(uuid, declarer) {
        const entree = (await this.bloquees()).find((e) => e.uuid === uuid);
        if (!entree) {
            return false;
        }

        let declaree = false;
        try {
            declaree = (await declarer(entree)) === true;
        } catch {
            declaree = false;
        }
        if (!declaree) {
            return false;
        }

        await this.depot.supprimer(uuid);
        await this.notifier();

        return true;
    }

    async resume() {
        const entrees = (await this.depot.toutes()).filter((e) => this.estAMoi(e));

        return {
            transmises: 0,
            restantes: entrees.filter((e) => e.statut === EN_ATTENTE).length,
            bloquees: entrees.filter((e) => e.statut === BLOQUEE).length,
        };
    }

    /**
     * Boucle de vidage : réveillée par le retour de la connexion, elle réessaie
     * tant qu'il reste des entrées dues, en respectant les délais de relance.
     */
    async viderJusquAVide({ maxPasses = 50 } = {}) {
        for (let passe = 0; passe < maxPasses; passe += 1) {
            if (!this.estEnLigne()) {
                return this.resume();
            }

            const resultat = await this.vider();
            if (resultat.restantes === 0) {
                return resultat;
            }

            const delai = await this.delaiAvantProchaineTentative();
            if (delai === null) {
                return resultat;
            }

            await this.attendre(delai);
        }

        return this.resume();
    }

    /** Millisecondes avant la prochaine entrée due, ou null s'il n'y en a plus. */
    async delaiAvantProchaineTentative() {
        const dus = (await this.depot.toutes())
            .filter((e) => this.estAMoi(e) && e.statut === EN_ATTENTE)
            .map((e) => e.prochaineTentativeA);

        if (dus.length === 0) {
            return null;
        }

        return Math.max(0, Math.min(...dus) - this.maintenant());
    }
}
