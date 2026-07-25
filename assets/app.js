/*
 * Point d'entrée JavaScript de ZedPOS (AssetMapper, sans bundler).
 *
 * Turbo Drive est démarré ici explicitement. Il l'était déjà indirectement — le
 * contrôleur `symfony--ux-turbo--turbo-core` est chargé en « eager » par
 * assets/controllers.json et importe `@hotwired/turbo` — mais l'import explicite
 * rend la dépendance visible et survit à un changement de controllers.json.
 *
 * Note : avec AssetMapper le paquet s'appelle `@hotwired/turbo`. Le nom
 * `@hotwired/turbo-bundle` que l'on trouve dans la documentation appartient au
 * monde Webpack Encore et n'existe pas dans notre importmap.
 */
import '@hotwired/turbo';
import './stimulus_bootstrap.js';
import './styles/app.css';
