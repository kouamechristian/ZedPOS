/**
 * Quantités saisies au magasin, en millièmes **entiers** — pendant JS de
 * `App\Service\Magasin\SaisieQuantite`. Jamais de flottant : « 2,5 » devient
 * 2500 par recomposition de la partie entière et des décimales.
 */

/** « 18 », « 2,5 », « 1 000 » → millièmes ; null si vide ou illisible. */
export function lireMillimes(texte) {
    const propre = String(texte ?? '').replace(/[\s  ]/g, '');
    const m = /^(\d+)(?:[.,](\d{1,3}))?$/.exec(propre);
    if (!m) {
        return null;
    }

    return Number(m[1]) * 1000 + Number((m[2] ?? '').padEnd(3, '0'));
}

/** Millièmes → « 18 », « 2,5 ». */
export function ecrireMillimes(millimes) {
    const signe = millimes < 0 ? '-' : '';
    const absolu = Math.abs(millimes);
    const decimales = String(absolu % 1000).padStart(3, '0').replace(/0+$/, '');

    return signe + Math.trunc(absolu / 1000) + (decimales ? ',' + decimales : '');
}
