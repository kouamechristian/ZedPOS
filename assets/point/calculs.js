/*
 * Calcul du point à l'écran — pendant exact de App\Service\Point\PointCalculator,
 * testé sous Node (tests/js/point_calculs.test.js) sur les mêmes cas.
 *
 * Il ne fait foi pour personne : le serveur recalcule tout à l'enregistrement. Mais
 * la gérante annonce ces chiffres au vendeur avant qu'il remette l'argent, ils
 * doivent donc tomber exactement sur ceux du serveur.
 *
 * Entiers de bout en bout : quantités en millièmes, montants en centimes.
 */

/**
 * @param {Array<{id: number, confiee: number, dejaRetournee: number, dejaPerdue: number,
 *                tranches: Array<{quantite: number, prixVente: number, prixCession: number}>}>} produits
 *        tranches du plus ancien au plus récent
 * @param {Object<string, {invendu: number, perdu: number}>} saisie unités saisies, par id de produit
 * @param {'COMMISSION'|'MARGE'} mode
 * @param {number} tauxBp
 * @param {?number} remis centimes, null tant que non saisi
 */
export function calculerPoint(produits, saisie, mode, tauxBp, remis) {
    const lignes = [];
    let montantAttendu = 0;
    let marge = 0;
    let erreurs = 0;

    for (const produit of produits) {
        const saisi = saisie[produit.id] ?? { invendu: 0, perdu: 0 };
        const retournee = produit.dejaRetournee + saisi.invendu * 1000;
        const perdue = produit.dejaPerdue + saisi.perdu * 1000;
        const vendue = produit.confiee - retournee - perdue;

        if (vendue < 0) {
            erreurs += 1;
            lignes.push({ id: produit.id, vendue, montant: 0, erreur: true });
            continue;
        }

        // Plus anciennes dotations vendues d'abord.
        let aImputer = vendue;
        let montant = 0;
        let margeLigne = 0;
        for (const tranche of produit.tranches) {
            const vendu = Math.min(tranche.quantite, aImputer);
            aImputer -= vendu;
            const montantTranche = divEntiere(vendu * tranche.prixVente, 1000);
            montant += montantTranche;
            margeLigne += montantTranche - divEntiere(vendu * tranche.prixCession, 1000);
        }

        montantAttendu += montant;
        marge += margeLigne;
        lignes.push({ id: produit.id, vendue, montant, erreur: false });
    }

    const remuneration = 'MARGE' === mode ? marge : commission(montantAttendu, tauxBp);
    const net = montantAttendu - remuneration;

    return {
        lignes,
        erreurs,
        montantAttendu,
        remuneration,
        net,
        ecart: null === remis ? null : remis - net,
    };
}

/** Commission arrondie au franc le plus proche, demi-franc vers le haut. */
export function commission(montantCentimes, tauxBp) {
    return divEntiere(montantCentimes * tauxBp + 500000, 1000000) * 100;
}

/** 'juste' | 'manquant' | 'trop' | null — la couleur de l'écart. */
export function etatEcart(ecart) {
    if (null === ecart) {
        return null;
    }

    return 0 === ecart ? 'juste' : (ecart < 0 ? 'manquant' : 'trop');
}

/**
 * Au-delà du seuil du stand, l'écart se justifie ; un manquant passé en perte se
 * justifie toujours — mêmes règles que `Arrete::verifierValidable()`.
 *
 * @param {?string} traitement 'DETTE' | 'PERTE' | null
 */
export function justificationRequise(ecart, seuil, traitement = null) {
    if (null === ecart) {
        return false;
    }

    return Math.abs(ecart) > seuil || (ecart < 0 && 'PERTE' === traitement);
}

/** Un manquant attend que la gérante choisisse : dette du vendeur ou perte. */
export function traitementRequis(ecart) {
    return null !== ecart && ecart < 0;
}

/** Division entière, tronquée vers zéro comme intdiv() en PHP. */
function divEntiere(a, b) {
    return (a - (a % b)) / b;
}
