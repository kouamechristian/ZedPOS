/*
 * Calcul du point à l'écran : mêmes cas que tests/Unit/PointCalculatorTest.php,
 * mêmes résultats — l'écran et le serveur ne doivent jamais se contredire.
 *
 * Lancement : node --test "tests/js/*.test.js"
 */
import { strict as assert } from 'node:assert';
import { describe, it } from 'node:test';

import { calculerPoint, commission, etatEcart, justificationRequise, traitementRequis } from '../../assets/point/calculs.js';

const produit = (id, tranches, deja = {}) => ({
    id,
    confiee: tranches.reduce((s, [q]) => s + q * 1000, 0),
    dejaRetournee: (deja.retournee ?? 0) * 1000,
    dejaPerdue: (deja.perdue ?? 0) * 1000,
    tranches: tranches.map(([q, pv, pc]) => ({ quantite: q * 1000, prixVente: pv * 100, prixCession: pc * 100 })),
});

describe('Point — mêmes cas que le serveur', () => {
    it('période d\'un jour', () => {
        const point = calculerPoint(
            [produit(1, [[40, 150, 125]])],
            { 1: { invendu: 6, perdu: 2 } },
            'COMMISSION',
            1000,
            285000,
        );

        assert.equal(point.lignes[0].vendue, 32000);
        assert.equal(point.montantAttendu, 480000);
        assert.equal(point.remuneration, 48000);
        assert.equal(point.net, 432000);
        assert.equal(point.ecart, 285000 - 432000);
    });

    it('changement de prix : plus anciens vendus d\'abord', () => {
        const point = calculerPoint(
            [produit(1, [[20, 150, 125], [20, 200, 160]])],
            { 1: { invendu: 10, perdu: 0 } },
            'MARGE',
            0,
            null,
        );

        assert.equal(point.montantAttendu, 500000);
        assert.equal(point.remuneration, 90000);
        assert.equal(point.net, 410000);
        assert.equal(point.ecart, null);
    });

    it('plusieurs dotations et plusieurs retours', () => {
        const point = calculerPoint(
            [
                produit(1, [[40, 150, 125], [10, 150, 125], [40, 150, 125]]),
                produit(2, [[12, 300, 250]]),
                produit(3, [[24, 500, 420]]),
            ],
            { 1: { invendu: 12, perdu: 3 }, 2: { invendu: 0, perdu: 2 }, 3: { invendu: 4, perdu: 0 } },
            'COMMISSION',
            750,
            3000000,
        );

        assert.equal(point.montantAttendu, 2425000);
        assert.equal(point.remuneration, 181900);
        assert.equal(point.net, 2243100);
        assert.equal(point.ecart, 3000000 - 2243100);
    });

    it('compte les retours déjà écrits sur la période', () => {
        const point = calculerPoint([produit(1, [[10, 150, 125]], { retournee: 3 })], { 1: { invendu: 2, perdu: 0 } }, 'COMMISSION', 0, null);

        assert.equal(point.lignes[0].vendue, 5000);
    });

    it('signale un retour au-delà du confié au lieu de calculer faux', () => {
        const point = calculerPoint([produit(1, [[10, 150, 125]])], { 1: { invendu: 8, perdu: 3 } }, 'COMMISSION', 1000, null);

        assert.equal(point.erreurs, 1);
        assert.equal(point.lignes[0].erreur, true);
        assert.equal(point.montantAttendu, 0);
    });
});

describe('Écart', () => {
    it('arrondit la commission comme le serveur', () => {
        assert.equal(commission(123400, 750), 9300);
        assert.equal(commission(122000, 750), 9200);
        assert.equal(commission(121900, 750), 9100);
    });

    it('colore : vert juste, rouge manquant, orange trop-perçu', () => {
        assert.equal(etatEcart(0), 'juste');
        assert.equal(etatEcart(-500), 'manquant');
        assert.equal(etatEcart(500), 'trop');
        assert.equal(etatEcart(null), null);
    });

    it('exige une justification strictement au-delà du seuil', () => {
        assert.equal(justificationRequise(-50000, 50000), false);
        assert.equal(justificationRequise(-50100, 50000), true);
        assert.equal(justificationRequise(100, 0), true);
        assert.equal(justificationRequise(null, 0), false);
    });

    it('un manquant passé en perte se justifie toujours, une dette suit le seuil', () => {
        assert.equal(justificationRequise(-20000, 50000, 'PERTE'), true);
        assert.equal(justificationRequise(-20000, 50000, 'DETTE'), false);
        assert.equal(justificationRequise(-20000, 50000, null), false);
        assert.equal(justificationRequise(20000, 50000, 'PERTE'), false, 'un trop-perçu n\'est pas une perte');
    });

    it('seul un manquant attend un choix entre dette et perte', () => {
        assert.equal(traitementRequis(-100), true);
        assert.equal(traitementRequis(0), false);
        assert.equal(traitementRequis(100), false);
        assert.equal(traitementRequis(null), false);
    });
});
