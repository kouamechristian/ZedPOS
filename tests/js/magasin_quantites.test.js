import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ecrireMillimes, lireMillimes } from '../../assets/magasin/quantites.js';

// Mêmes cas que ReceptionCalculsTest (PHP, SaisieQuantite) : l'écran et le
// serveur lisent une quantité de la même façon.

test('les saisies se lisent en millièmes entiers', () => {
    assert.equal(lireMillimes('20'), 20000);
    assert.equal(lireMillimes('2,5'), 2500);
    assert.equal(lireMillimes('2.25'), 2250);
    assert.equal(lireMillimes('0,125'), 125);
    assert.equal(lireMillimes('1 000'), 1000000);
    assert.equal(lireMillimes('0'), 0);
});

test('une saisie vide ou illisible donne null', () => {
    for (const saisie of ['', '  ', 'vingt', '1,2345', '-3', '1,2,3', null, undefined]) {
        assert.equal(lireMillimes(saisie), null, String(saisie));
    }
});

test('les millièmes se réécrivent sans zéro inutile', () => {
    assert.equal(ecrireMillimes(20000), '20');
    assert.equal(ecrireMillimes(2500), '2,5');
    assert.equal(ecrireMillimes(125), '0,125');
    assert.equal(ecrireMillimes(0), '0');
    assert.equal(ecrireMillimes(19000 - 1000), '18');
});
