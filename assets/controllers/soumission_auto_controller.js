import { Controller } from '@hotwired/stimulus';

/*
 * Soumet le formulaire dès qu'un champ change, sans passer par le bouton.
 *
 * Sur un sélecteur de date, cliquer une journée puis chercher « Voir » est un
 * geste de trop — surtout au pouce sur un téléphone.
 *
 * `requestSubmit()` et **jamais** `submit()` : le `submit()` natif n'émet pas
 * d'événement `submit`, Turbo ne peut donc pas l'intercepter et le navigateur
 * recharge toute la page (voir la section Turbo de CLAUDE.md).
 */
export default class extends Controller {
    soumettre(event) {
        // `requestSubmit` déclenche aussi la validation HTML : une date effacée
        // ou incomplète ne part pas.
        event.currentTarget.form?.requestSubmit();
    }
}
