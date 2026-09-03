/*
 * Pont vers l'agent matériel local du terminal de caisse.
 *
 * Un petit service Node tourne sur le poste et expose http://127.0.0.1:9100 :
 * afficheur client (/display), ticket thermique (/print), tiroir-caisse (/drawer)
 * et un /status pour savoir s'il répond.
 *
 * RÈGLE ABSOLUE — aucun appel ne rejette jamais.
 * La très grande majorité des postes n'a pas d'agent : tablette du comptoir,
 * poste de secours, machine du gérant, navigateur de la dirigeante. Sur chacun
 * d'eux, `fetch` vers 127.0.0.1:9100 échoue immédiatement, et cet échec ne doit
 * avoir **aucun** effet visible — la caisse continue exactement comme avant, avec
 * son impression par `window.print()`. D'où deux partis pris :
 *
 *   - toutes les méthodes renvoient un booléen, jamais une exception ;
 *   - l'appelant n'a donc jamais besoin de `try/catch` ni de `.catch()`, et un
 *     `pos.display(...)` peut être lancé sans `await` au milieu d'une méthode
 *     synchrone sans risquer de rejet non capturé.
 *
 * ⚠ Les montants qui entrent ici sont en **FCFA entiers**, jamais en centimes :
 * l'agent les imprime tels quels. La conversion se fait chez l'appelant — côté
 * PHP dans TicketMateriel, côté JS dans le contrôleur de ticket.
 */

const BASE = 'http://127.0.0.1:9100';

/**
 * Délai au bout duquel on renonce.
 *
 * Court, et c'est le point important : l'agent est sur la boucle locale, il
 * répond en quelques millisecondes ou pas du tout. Attendre davantage ferait
 * traîner l'afficheur client derrière la frappe de la caissière, alors même
 * qu'il n'y a personne au bout.
 *
 * L'impression a droit à plus : l'agent pousse les octets vers la tête thermique
 * avant de répondre, et un ticket d'une quinzaine de lignes n'est pas instantané.
 */
const DELAI = 1200;
const DELAI_IMPRESSION = 6000;

/** Modes reconnus par l'afficheur client. */
export const PRIX = 'price';
export const TOTAL = 'total';
export const MONNAIE = 'change';
export const EFFACER = 'clear';

/**
 * Signal d'abandon au bout de `ms`. `AbortSignal.timeout` n'existe pas partout
 * (navigateurs anciens du parc) : le repli fait la même chose à la main.
 */
function expiration(ms) {
    if (AbortSignal.timeout) {
        return AbortSignal.timeout(ms);
    }

    const controleur = new AbortController();
    setTimeout(() => controleur.abort(), ms);

    return controleur.signal;
}

/**
 * Appel à l'agent. Renvoie `true` s'il a répondu favorablement, `false` dans tous
 * les autres cas — agent absent, hors délai, erreur HTTP, réponse illisible.
 * Ne lève jamais.
 */
async function appeler(route, corps = null, delai = DELAI) {
    try {
        const reponse = await fetch(BASE + route, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(corps ?? {}),
            signal: expiration(delai),
            // L'agent est un tiers vis-à-vis de l'application : pas de cookie de
            // session à lui transmettre, et rien à attendre de lui en retour.
            credentials: 'omit',
            mode: 'cors',
        });

        return reponse.ok;
    } catch {
        // Agent absent (le cas courant), refus CORS, coupure : silence complet.
        return false;
    }
}

/** Montant sûr pour l'agent : entier positif, jamais de décimale ni de NaN. */
function entier(montant) {
    const valeur = Math.round(Number(montant));

    return Number.isFinite(valeur) ? Math.max(0, valeur) : 0;
}

class PosAgent {
    constructor() {
        /** Dernier état connu de l'agent : null tant qu'on n'a rien demandé. */
        this.present = null;
        this.sonde = null;
    }

    /**
     * L'agent répond-il ?
     *
     * Le résultat est mémorisé : la réponse sert à décider du chemin d'impression
     * à chaque vente, et sonder la boucle locale vingt fois par heure pour la même
     * réponse n'apprendrait rien. `verifier()` force une nouvelle sonde — c'est ce
     * qu'appelle l'indicateur de l'écran de caisse.
     */
    async available() {
        if (null !== this.present) {
            return this.present;
        }

        return this.verifier();
    }

    /**
     * Sonde effective. Les appels concurrents partagent la même requête : au
     * chargement de la caisse, l'indicateur et le contrôleur de ticket demandent
     * l'état en même temps.
     */
    async verifier() {
        this.sonde ??= (async () => {
            try {
                const reponse = await fetch(`${BASE}/status`, {
                    signal: expiration(DELAI),
                    credentials: 'omit',
                    mode: 'cors',
                });
                this.present = reponse.ok;
            } catch {
                this.present = false;
            } finally {
                this.sonde = null;
            }

            return this.present;
        })();

        return this.sonde;
    }

    /**
     * Afficheur client. `montant` en FCFA entiers.
     *
     * Appelée à chaque frappe sur la grille de produits : elle doit rester sans
     * conséquence sur la cadence de saisie, d'où l'absence d'`await` chez ses
     * appelants et l'échec immédiat quand l'agent est absent.
     */
    async display(montant, mode = PRIX) {
        return appeler('/display', { amount: entier(montant), mode });
    }

    /**
     * Ticket thermique. `ticket` est la charge utile produite par le serveur
     * (voir App\Service\TicketMateriel) : elle porte déjà des FCFA entiers et
     * l'indicateur d'ouverture du tiroir.
     */
    async print(ticket) {
        if (!ticket) {
            return false;
        }

        return appeler('/print', ticket, DELAI_IMPRESSION);
    }

    /** Ouverture du tiroir hors impression (rendu de monnaie, contrôle). */
    async drawer() {
        return appeler('/drawer');
    }
}

/** Instance unique : l'état « agent présent » est partagé par tout l'écran. */
export const pos = new PosAgent();
