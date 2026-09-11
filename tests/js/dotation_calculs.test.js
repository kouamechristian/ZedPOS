/*
 * Calculs de l'écran de dotation.
 *
 * La gérante lit ces totaux au vendeur avant qu'il signe : ils doivent tomber juste
 * au franc, et le pavé ne doit jamais produire une quantité qu'elle n'a pas tapée.
 *
 * Lancement : node --test "tests/js/*.test.js"
 */
import { strict as assert } from 'node:assert';
import { describe, it } from 'node:test';

import {
    CHIFFRES_MAX,
    ajouterChiffre,
    formaterFcfa,
    formaterQuantite,
    lireUnites,
    retirerChiffre,
    stockRestant,
    totaux,
} from '../../assets/dotation/calculs.js';

describe('Pavé de quantité', () => {
    it('empile les chiffres sans zéro en tête', () => {
        assert.equal(ajouterChiffre('', '0'), '0');
        assert.equal(ajouterChiffre('0', '5'), '5');
        assert.equal(ajouterChiffre('4', '0'), '40');
        assert.equal(ajouterChiffre(undefined, '3'), '3');
    });

    it('refuse un chiffre de trop plutôt que de tronquer la saisie', () => {
        const pleine = '9'.repeat(CHIFFRES_MAX);
        assert.equal(ajouterChiffre(pleine, '1'), pleine);
    });

    it('retire le dernier chiffre, et rien sur une case vide', () => {
        assert.equal(retirerChiffre('120'), '12');
        assert.equal(retirerChiffre(''), '');
    });

    it('lit une case vide, nulle ou illisible comme « non pris »', () => {
        assert.equal(lireUnites(''), 0);
        assert.equal(lireUnites('0'), 0);
        assert.equal(lireUnites('abc'), 0);
        assert.equal(lireUnites('12'), 12);
    });
});

describe('Totaux du bon', () => {
    it('somme au centime, et ignore les produits non pris', () => {
        const total = totaux([
            { unites: 40, prixVente: 15000, prixCession: 12500 },  // 40 baguettes
            { unites: 0, prixVente: 30000, prixCession: 25000 },   // non pris
            { unites: 12, prixVente: 50000, prixCession: 42000 },  // 12 sodas
        ]);

        assert.deepEqual(total, { produits: 2, unites: 52, vente: 1200000, cession: 1004000 });
    });

    it('formate les francs par milliers, sans décimale', () => {
        assert.equal(formaterFcfa(1004000), '10 040 FCFA');
        assert.equal(formaterFcfa(0), '0 FCFA');
        assert.equal(formaterFcfa(-250000), '-2 500 FCFA');
    });
});

describe('Stock dépôt restant', () => {
    it('déduit la dotation en millièmes', () => {
        assert.equal(stockRestant(24000, 10), 14000);
        assert.equal(stockRestant(5000, 8), -3000);
    });

    it('distingue « non suivi » de « zéro »', () => {
        assert.equal(stockRestant(null, 40), null);
        assert.equal(stockRestant(0, 0), 0);
    });

    it('affiche les quantités lisiblement', () => {
        assert.equal(formaterQuantite(14000), '14');
        assert.equal(formaterQuantite(2500), '2,5');
        assert.equal(formaterQuantite(-3000), '-3');
        assert.equal(formaterQuantite(1200000), '1 200');
    });
});
