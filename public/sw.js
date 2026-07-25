/*
 * Service Worker de ZedPOS — rend l'écran de caisse utilisable sans Internet.
 *
 * Servi depuis la racine (/sw.js) pour couvrir tout le site, mais il ne met en
 * cache que ce dont la caisse a besoin :
 *   - la page /caisse et le catalogue JSON  (réseau d'abord, cache en secours) ;
 *   - les assets versionnés /assets/…       (cache d'abord : leur URL contient un
 *                                            condensé, ils ne changent jamais).
 *
 * Ce qu'il ne fait JAMAIS :
 *   - intercepter autre chose qu'un GET — un encaissement (POST /api/vente) doit
 *     atteindre le réseau ou échouer franchement, pour que la file de
 *     synchronisation prenne le relais ;
 *   - mettre en cache une réponse d'authentification ou d'erreur.
 *
 * Écrit sans import : un Service Worker classique ne bénéficie pas de l'importmap.
 */

const VERSION = 'zedpos-v1';
const CACHE_COQUILLE = `${VERSION}-coquille`;
const CACHE_ASSETS = `${VERSION}-assets`;

const PAGE_CAISSE = '/caisse';
const CATALOGUE = '/caisse/catalogue.json';

self.addEventListener('install', (evenement) => {
    // Le nouveau worker prend la main sans attendre la fermeture des onglets :
    // en caisse, on ne veut pas d'une version obsolète qui traîne.
    evenement.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (evenement) => {
    evenement.waitUntil((async () => {
        const noms = await caches.keys();
        await Promise.all(
            noms.filter((nom) => !nom.startsWith(VERSION)).map((nom) => caches.delete(nom)),
        );
        await self.clients.claim();
    })());
});

/**
 * La page signale qu'elle est chargée et en ligne : on en profite pour rafraîchir
 * la coquille (page + catalogue) pendant que la connexion est disponible.
 */
self.addEventListener('message', (evenement) => {
    if (evenement.data?.type === 'PRECHARGER_CAISSE') {
        evenement.waitUntil(prechargerCoquille());
    }
});

async function prechargerCoquille() {
    const cache = await caches.open(CACHE_COQUILLE);

    await Promise.all([PAGE_CAISSE, CATALOGUE].map(async (url) => {
        try {
            const reponse = await fetch(url, { credentials: 'same-origin' });
            if (estCachable(reponse)) {
                await cache.put(url, reponse.clone());
            }
        } catch {
            // Hors ligne au moment du préchargement : sans conséquence.
        }
    }));
}

/**
 * Une réponse n'est mise en cache que si elle est réellement exploitable hors
 * ligne : 200 seulement, et surtout pas une redirection vers la page de connexion.
 */
function estCachable(reponse) {
    return reponse && reponse.status === 200 && reponse.type !== 'opaqueredirect' && !reponse.redirected;
}

self.addEventListener('fetch', (evenement) => {
    const requete = evenement.request;

    if (requete.method !== 'GET') {
        return; // POST /api/vente & co. : jamais interceptés.
    }

    const url = new URL(requete.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    if (url.pathname.startsWith('/assets/')) {
        evenement.respondWith(cacheDAbord(requete, CACHE_ASSETS));

        return;
    }

    if (url.pathname === CATALOGUE) {
        evenement.respondWith(reseauDAbord(requete, CACHE_COQUILLE));

        return;
    }

    // Navigation vers l'écran de caisse (et lui seul : les autres espaces restent
    // en ligne, ils n'ont pas de sens hors connexion).
    if (requete.mode === 'navigate' && url.pathname === PAGE_CAISSE) {
        evenement.respondWith(reseauDAbord(requete, CACHE_COQUILLE, PAGE_CAISSE));
    }
});

/** Assets versionnés : le cache fait foi, le réseau ne sert qu'au premier appel. */
async function cacheDAbord(requete, nomCache) {
    const cache = await caches.open(nomCache);
    const enCache = await cache.match(requete);
    if (enCache) {
        return enCache;
    }

    const reponse = await fetch(requete);
    if (estCachable(reponse)) {
        cache.put(requete, reponse.clone());
    }

    return reponse;
}

/**
 * Contenus qui doivent rester frais tant qu'on est en ligne, et rester
 * disponibles quand on ne l'est plus.
 */
async function reseauDAbord(requete, nomCache, cleSecours = null) {
    const cache = await caches.open(nomCache);

    try {
        const reponse = await fetch(requete);
        if (estCachable(reponse)) {
            cache.put(cleSecours ?? requete, reponse.clone());
        }

        return reponse;
    } catch (erreur) {
        const secours = await cache.match(cleSecours ?? requete);
        if (secours) {
            return secours;
        }

        throw erreur;
    }
}
