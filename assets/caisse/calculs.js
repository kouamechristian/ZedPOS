/*
 * Calculs du ticket de caisse — fonctions pures, sans dépendance navigateur.
 *
 * Extraites du contrôleur Stimulus pour être vérifiables sous Node : ce sont
 * elles qui affichent le total au client, elles ne doivent pas dériver.
 *
 * Convention du projet : **les montants sont des entiers en centimes de FCFA**,
 * les taux de TVA des entiers en points de base (1800 = 18 %). Aucun flottant
 * n'entre dans un calcul d'argent ; l'arrondi n'a lieu qu'au formatage.
 *
 * @typedef {{prix: number, quantite: number, tva: number}} Ligne
 */

/**
 * Total toutes taxes comprises, en centimes.
 *
 * @param {Ligne[]} lignes
 */
export function totalTtc(lignes) {
    return lignes.reduce((somme, l) => somme + l.quantite * l.prix, 0);
}

/**
 * TVA **incluse** dans le total, en centimes, calculée ligne par ligne au taux
 * réel de chaque article.
 *
 * Un taux unique appliqué à tout le ticket serait faux ici : le pain, la
 * viennoiserie et la pâtisserie sont exonérés (taux 0), alors que les boissons
 * et les plats sont à 18 %. Un ticket de boulangerie mélange couramment les deux.
 *
 * @param {Ligne[]} lignes
 */
export function tvaIncluse(lignes) {
    return lignes.reduce((somme, l) => {
        const ttc = l.quantite * l.prix;

        return somme + Math.round((ttc * l.tva) / (10000 + l.tva));
    }, 0);
}

/**
 * Taux de TVA non nuls présents dans le ticket, triés — sert à libeller la ligne
 * de TVA honnêtement (« exonéré », « 18 % », ou « taux multiples »).
 *
 * @param {Ligne[]} lignes
 * @returns {number[]} taux en points de base
 */
export function tauxPresents(lignes) {
    return [...new Set(lignes.filter((l) => l.tva > 0).map((l) => l.tva))].sort((a, b) => a - b);
}

/**
 * Libellé de la ligne de TVA, cohérent avec le contenu réel du ticket.
 *
 * @param {Ligne[]} lignes
 */
export function libelleTva(lignes) {
    const taux = tauxPresents(lignes);

    if (taux.length === 0) {
        return 'Dont TVA (exonéré)';
    }
    if (taux.length === 1) {
        return `Dont TVA (${taux[0] / 100} %)`;
    }

    return 'Dont TVA (taux multiples)';
}

/**
 * Centimes → FCFA affichables : entier, séparateur de milliers français,
 * jamais de décimale. L'espace insécable étroit produit par `toLocaleString`
 * est normalisé en espace simple pour rester lisible partout.
 *
 * @param {number} centimes
 */
export function formaterFcfa(centimes) {
    const fcfa = Math.round(centimes / 100);

    return `${fcfa.toLocaleString('fr-FR').replace(/ | /g, ' ')} FCFA`;
}

/**
 * Monnaie à rendre au client, en centimes : l'excédent de ce qu'il a tendu sur le
 * total du ticket.
 *
 * **Jamais négatif.** Tant que le compte n'y est pas, il n'y a rien à rendre — il
 * manque de l'argent, ce que dit {@see manquant}. Confondre les deux ferait
 * afficher un rendu négatif à la caissière, qui n'a aucun sens au comptoir.
 *
 * @param {number} recu  ce que le client a donné, en centimes
 * @param {number} total total TTC du ticket, en centimes
 */
export function renduMonnaie(recu, total) {
    return Math.max(0, recu - total);
}

/**
 * Ce qu'il manque encore pour couvrir le ticket, en centimes. Zéro dès que le
 * compte est bon.
 *
 * @param {number} recu
 * @param {number} total
 */
export function manquant(recu, total) {
    return Math.max(0, total - recu);
}

/**
 * Lit un montant saisi **en FCFA** et le convertit en centimes.
 *
 * La caissière tape des francs entiers : il n'existe pas de pièce en dessous du
 * franc CFA, et le pavé tactile n'a pas de virgule. Les espaces de milliers sont
 * tolérés — elle peut recopier telle quelle la somme affichée à l'écran.
 *
 * @param {string} saisie
 * @returns {?number} montant en centimes, ou `null` si la saisie n'est pas un
 *                    montant (champ vide, en cours de frappe, caractère parasite).
 */
export function lireMontantFcfa(saisie) {
    const chiffres = String(saisie ?? '').replace(/[\s  .]/g, '');

    if ('' === chiffres || !/^\d+$/.test(chiffres)) {
        return null;
    }

    return parseInt(chiffres, 10) * 100;
}

/** Coupures franc CFA réellement tendues au comptoir, en centimes. */
const COUPURES = [50000, 100000, 200000, 500000, 1000000]; // 500, 1 000, 2 000, 5 000, 10 000 FCFA

/**
 * Montants à proposer d'un seul appui pour un ticket donné, en centimes.
 *
 * Le premier est le **compte juste** ; les suivants sont le total arrondi à la
 * coupure supérieure — c'est-à-dire ce que le client tend réellement quand il
 * n'a pas l'appoint. Saisir 2 000 chiffre par chiffre pendant la file du matin
 * coûte plus cher que le calcul lui-même.
 *
 * @param {number} total  total TTC, en centimes
 * @param {number} combien nombre maximal de propositions (la place est comptée)
 * @returns {number[]} montants en centimes, croissants, sans doublon
 */
export function suggestionsEspeces(total, combien = 4) {
    if (total <= 0) {
        return [];
    }

    const montants = new Set([total]);
    for (const coupure of COUPURES) {
        montants.add(Math.ceil(total / coupure) * coupure);
    }

    return [...montants].sort((a, b) => a - b).slice(0, combien);
}

/**
 * Ajoute une unité d'un article au ticket, ou incrémente la ligne existante.
 * Renvoie un **nouveau** tableau : l'état reste facile à raisonner et à tester.
 *
 * @param {Ligne[]} lignes
 * @param {{articleId: string, nom: string, prix: number, tva: number}} article
 */
export function ajouterUnite(lignes, article) {
    const existante = lignes.find((l) => l.articleId === article.articleId);

    if (existante) {
        return lignes.map((l) => (l === existante ? { ...l, quantite: l.quantite + 1 } : l));
    }

    return [...lignes, { ...article, quantite: 1 }];
}

/**
 * Fait varier la quantité d'une ligne. La ligne disparaît quand elle atteint 0 :
 * la caissière n'a pas de bouton « supprimer » à viser.
 *
 * @param {Ligne[]} lignes
 * @param {string}  articleId
 * @param {number}  delta
 */
export function ajusterQuantite(lignes, articleId, delta) {
    return lignes
        .map((l) => (l.articleId === articleId ? { ...l, quantite: l.quantite + delta } : l))
        .filter((l) => l.quantite > 0);
}
