/*
 * Calculs de l'écran de dotation, sans navigateur : testés sous Node
 * (tests/js/dotation_calculs.test.js).
 *
 * Arithmétique entière de bout en bout — quantités en unités, prix en centimes,
 * stocks en millièmes. Le serveur recalcule tout à la validation : l'écran ne fait
 * foi pour personne, mais la gérante lit ces totaux au vendeur, ils doivent tomber
 * juste.
 */

/** Cinq chiffres : au-delà, c'est une touche restée enfoncée. */
export const CHIFFRES_MAX = 5;

/** Saisie au pavé : pas de zéro en tête, longueur bornée. */
export function ajouterChiffre(saisie, chiffre) {
    const suite = `${saisie ?? ''}${chiffre}`.replace(/^0+(?=\d)/, '');

    return suite.length > CHIFFRES_MAX ? (saisie ?? '') : suite;
}

export function retirerChiffre(saisie) {
    return (saisie ?? '').slice(0, -1);
}

/** Saisie → unités. Vide, zéro ou illisible : 0, « non pris ». */
export function lireUnites(saisie) {
    return /^\d+$/.test(saisie ?? '') ? Number.parseInt(saisie, 10) : 0;
}

/**
 * @param {Array<{unites: number, prixVente: number, prixCession: number}>} lignes prix en centimes
 * @returns {{produits: number, unites: number, vente: number, cession: number}} montants en centimes
 */
export function totaux(lignes) {
    const total = { produits: 0, unites: 0, vente: 0, cession: 0 };

    for (const { unites, prixVente, prixCession } of lignes) {
        if (unites > 0) {
            total.produits += 1;
            total.unites += unites;
            total.vente += unites * prixVente;
            total.cession += unites * prixCession;
        }
    }

    return total;
}

/**
 * Stock dépôt restant si la dotation part, en millièmes. `null` : le dépôt ne
 * suit pas cet article (pain fabriqué) — ce n'est pas zéro.
 */
export function stockRestant(stockMillimes, unites) {
    return null === stockMillimes ? null : stockMillimes - unites * 1000;
}

/** Millièmes → « 12 », « 2,5 », « -3 ». */
export function formaterQuantite(millimes) {
    const absolu = Math.abs(millimes);
    const reste = absolu % 1000;
    const entier = (absolu - reste) / 1000;
    const decimales = String(reste).padStart(3, '0').replace(/0+$/, '');

    return `${millimes < 0 ? '-' : ''}${grouper(entier)}${decimales ? `,${decimales}` : ''}`;
}

/** Centimes → « 12 500 FCFA ». Division d'un multiple exact : aucun flottant n'apparaît. */
export function formaterFcfa(centimes) {
    const absolu = Math.abs(centimes);
    const fcfa = (absolu - (absolu % 100)) / 100;

    return `${centimes < 0 ? '-' : ''}${grouper(fcfa)} FCFA`;
}

function grouper(entier) {
    return String(entier).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
}
