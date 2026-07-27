/*
 * Calculs du ticket de caisse.
 *
 * Ce sont les nombres que la caissière annonce au client : ils doivent être
 * exacts au centime, et le formatage ne doit jamais laisser traîner de décimale.
 *
 * Lancement : node --test "tests/js/*.test.js"
 */
import { strict as assert } from 'node:assert';
import { describe, it } from 'node:test';

import {
    ajouterUnite,
    ajusterQuantite,
    formaterFcfa,
    libelleTva,
    lireMontantFcfa,
    manquant,
    renduMonnaie,
    suggestionsEspeces,
    tauxPresents,
    totalTtc,
    tvaIncluse,
} from '../../assets/caisse/calculs.js';

// Prix en centimes, taux en points de base — conventions du projet.
const BAGUETTE = { articleId: '1', nom: 'Baguette', prix: 15000, tva: 0 };      // 150 FCFA, exonéré
const CROISSANT = { articleId: '2', nom: 'Croissant', prix: 25000, tva: 0 };    // 250 FCFA, exonéré
const SUCRERIE = { articleId: '3', nom: 'Sucrerie', prix: 50000, tva: 1800 };   // 500 FCFA, 18 %

describe('Ticket — total', () => {
    it('additionne quantité × prix', () => {
        const lignes = [
            { ...BAGUETTE, quantite: 3 },
            { ...CROISSANT, quantite: 2 },
        ];

        // 3 × 150 + 2 × 250 = 950 FCFA
        assert.equal(totalTtc(lignes), 95000);
    });

    it('vaut zéro sur un ticket vide', () => {
        assert.equal(totalTtc([]), 0);
    });
});

describe('Ticket — TVA incluse', () => {
    it('est nulle sur un ticket de boulangerie exonéré', () => {
        const lignes = [{ ...BAGUETTE, quantite: 4 }, { ...CROISSANT, quantite: 1 }];

        assert.equal(tvaIncluse(lignes), 0, 'Le pain et la viennoiserie ne portent pas de TVA.');
        assert.equal(libelleTva(lignes), 'Dont TVA (exonéré)');
    });

    it('extrait 18 % du TTC sur un article taxé', () => {
        const lignes = [{ ...SUCRERIE, quantite: 1 }]; // 500 FCFA TTC

        // 50000 × 1800 / 11800 = 7627,1… → 7627 centimes
        assert.equal(tvaIncluse(lignes), 7627);
        assert.equal(libelleTva(lignes), 'Dont TVA (18 %)');
    });

    it('ne taxe QUE les lignes taxées sur un ticket mixte', () => {
        const lignes = [
            { ...BAGUETTE, quantite: 2 },   // 300 FCFA exonérés
            { ...SUCRERIE, quantite: 1 },   // 500 FCFA à 18 %
        ];

        // Le piège : un taux unique appliqué au ticket entier donnerait
        // 80000 × 18/118 = 12203, soit 60 % de TVA en trop.
        assert.equal(totalTtc(lignes), 80000);
        assert.equal(tvaIncluse(lignes), 7627, 'Seule la sucrerie porte de la TVA.');
        assert.notEqual(tvaIncluse(lignes), Math.round((80000 * 18) / 118));
        assert.equal(libelleTva(lignes), 'Dont TVA (18 %)');
    });

    it('signale les taux multiples', () => {
        const lignes = [
            { ...SUCRERIE, quantite: 1 },
            { articleId: '9', nom: 'Article 5 %', prix: 10000, tva: 500, quantite: 1 },
        ];

        assert.deepEqual(tauxPresents(lignes), [500, 1800]);
        assert.equal(libelleTva(lignes), 'Dont TVA (taux multiples)');
    });

    it('reste un entier de centimes, jamais un flottant', () => {
        const lignes = [{ ...SUCRERIE, quantite: 3 }];
        const tva = tvaIncluse(lignes);

        assert.equal(Number.isInteger(tva), true);
        assert.equal(Number.isInteger(totalTtc(lignes)), true);
    });
});

describe('Ticket — ajout et ajustement', () => {
    it('un appui ajoute une unité, un second incrémente la même ligne', () => {
        let lignes = ajouterUnite([], BAGUETTE);
        lignes = ajouterUnite(lignes, BAGUETTE);
        lignes = ajouterUnite(lignes, BAGUETTE);

        assert.equal(lignes.length, 1, 'Une seule ligne pour trois appuis.');
        assert.equal(lignes[0].quantite, 3);
        assert.equal(totalTtc(lignes), 45000);
    });

    it('garde une ligne distincte par article', () => {
        let lignes = ajouterUnite([], BAGUETTE);
        lignes = ajouterUnite(lignes, CROISSANT);

        assert.equal(lignes.length, 2);
    });

    it('le bouton − retire une unité', () => {
        let lignes = ajouterUnite(ajouterUnite([], BAGUETTE), BAGUETTE);
        lignes = ajusterQuantite(lignes, '1', -1);

        assert.equal(lignes[0].quantite, 1);
    });

    it('la ligne disparaît quand la quantité tombe à zéro', () => {
        let lignes = ajouterUnite([], BAGUETTE);
        lignes = ajusterQuantite(lignes, '1', -1);

        assert.deepEqual(lignes, [], 'Pas de ligne à zéro qui traîne dans le ticket.');
    });

    it("n'altère pas le tableau d'origine", () => {
        const depart = ajouterUnite([], BAGUETTE);
        const apres = ajouterUnite(depart, BAGUETTE);

        assert.equal(depart[0].quantite, 1);
        assert.equal(apres[0].quantite, 2);
    });
});

describe('Caisse — rendu de monnaie', () => {
    it('rend la différence entre ce que le client a tendu et le total', () => {
        // Ticket de 1 500 FCFA, le client tend 2 000 → 500 à rendre.
        assert.equal(renduMonnaie(200000, 150000), 50000);
    });

    it('ne rend rien sur un compte juste', () => {
        assert.equal(renduMonnaie(150000, 150000), 0);
        assert.equal(manquant(150000, 150000), 0);
    });

    it("ne rend jamais un montant négatif quand le compte n'y est pas", () => {
        // Le piège : 100 000 − 150 000 = −50 000 affiché « rendu −500 FCFA »,
        // qui n'a aucun sens au comptoir. C'est un manque, pas un rendu.
        assert.equal(renduMonnaie(100000, 150000), 0);
        assert.equal(manquant(100000, 150000), 50000);
    });

    it('reste un entier de centimes', () => {
        assert.equal(Number.isInteger(renduMonnaie(200000, 150000)), true);
    });
});

describe('Caisse — lecture du montant reçu', () => {
    it('convertit des FCFA entiers en centimes', () => {
        assert.equal(lireMontantFcfa('2000'), 200000);
        assert.equal(lireMontantFcfa('0'), 0);
    });

    it('tolère les espaces de milliers, que la caissière recopie de l’écran', () => {
        assert.equal(lireMontantFcfa('10 000'), 1000000);
        assert.equal(lireMontantFcfa('10 000'), 1000000); // espace insécable étroite
    });

    it('renvoie null tant que la saisie n’est pas un montant', () => {
        // Champ vide ou frappe en cours : surtout ne pas lire « 0 », qui ferait
        // afficher un manque de tout le ticket dès le premier caractère effacé.
        for (const saisie of ['', '   ', 'abc', '2a0', '-500', '12,5', null, undefined]) {
            assert.equal(lireMontantFcfa(saisie), null, `Saisie refusée attendue : ${saisie}`);
        }
    });
});

describe('Caisse — coupures proposées', () => {
    it('propose d’abord le compte juste', () => {
        assert.equal(suggestionsEspeces(150000)[0], 150000);
    });

    it('arrondit à la coupure supérieure, sans doublon et par ordre croissant', () => {
        // 1 500 FCFA → juste, 2 000, 5 000, 10 000.
        assert.deepEqual(suggestionsEspeces(150000), [150000, 200000, 500000, 1000000]);
    });

    it('ne propose jamais moins que le total', () => {
        for (const total of [1, 15000, 150000, 300000, 500000, 1234500]) {
            for (const montant of suggestionsEspeces(total)) {
                assert.ok(montant >= total, `${montant} < ${total} : coupure insuffisante proposée.`);
            }
        }
    });

    it('reste croissante même quand une coupure ne divise pas la suivante', () => {
        // Piège : 5 000 FCFA pile donne 6 000 avec la coupure de 2 000 mais 5 000
        // avec celle de 5 000 — l'ordre des coupures ne suffit pas à trier.
        const montants = suggestionsEspeces(500000);

        assert.deepEqual(montants, [...montants].sort((a, b) => a - b));
    });

    it('ne propose rien sur un ticket vide', () => {
        assert.deepEqual(suggestionsEspeces(0), []);
    });
});

describe('Ticket — formatage FCFA', () => {
    it('affiche un entier avec séparateur de milliers, sans décimale', () => {
        assert.equal(formaterFcfa(128400), '1 284 FCFA');
        assert.equal(formaterFcfa(1500000), '15 000 FCFA');
        assert.equal(formaterFcfa(0), '0 FCFA');
    });

    it('arrondit au FCFA le plus proche', () => {
        assert.equal(formaterFcfa(15050), '151 FCFA');
        assert.equal(formaterFcfa(15049), '150 FCFA');
    });

    it('utilise une espace simple, pas une espace insécable étroite', () => {
        const rendu = formaterFcfa(128400);

        assert.equal(rendu.includes(' '), false);
        assert.equal(rendu.includes(' '), false);
        assert.match(rendu, /^\d[\d ]* FCFA$/);
    });

    it('ne laisse jamais apparaître de décimale', () => {
        for (const centimes of [1, 99, 12345, 99999]) {
            assert.equal(formaterFcfa(centimes).includes(','), false);
            assert.equal(formaterFcfa(centimes).includes('.'), false);
        }
    });
});
