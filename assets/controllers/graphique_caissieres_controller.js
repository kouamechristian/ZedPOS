import { Controller } from '@hotwired/stimulus';
import { Chart, registerables } from 'chart.js';

// Même remarque que pour la courbe du CA : `chart.js/auto` n'est pas dans
// l'importmap, on enregistre les composants nous-mêmes.
Chart.register(...registerables);

/*
 * Répartition du chiffre d'affaires par caissière (écran de pilotage).
 *
 * Barres **horizontales** : les noms se lisent en entier sans être inclinés, et
 * la hauteur du graphique suit le nombre de caissières au lieu d'écraser les
 * barres quand il y en a plusieurs.
 *
 * Les valeurs arrivent en FCFA entiers, converties côté serveur : aucun calcul
 * monétaire n'est fait ici, uniquement du formatage.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['canvas'];
    static values = { libelles: Array, valeurs: Array, couleurs: Array };

    connect() {
        this.graphique = new Chart(this.canvasTarget, {
            type: 'bar',
            data: {
                labels: this.libellesValue,
                datasets: [{
                    data: this.valeursValue,
                    backgroundColor: this.couleursValue,
                    borderRadius: 6,
                    borderSkipped: false,
                    maxBarThickness: 34,
                }],
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (contexte) => `${this.fcfa(contexte.parsed.x)} FCFA`,
                        },
                    },
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        border: { display: false },
                        ticks: { callback: (valeur) => this.compact(valeur) },
                    },
                    y: {
                        grid: { display: false },
                        border: { display: false },
                    },
                },
            },
        });
    }

    disconnect() {
        this.graphique?.destroy();
    }

    fcfa(valeur) {
        return Math.round(valeur).toLocaleString('fr-FR').replace(/ | /g, ' ');
    }

    compact(valeur) {
        return valeur >= 1000 ? `${Math.round(valeur / 1000)} k` : String(valeur);
    }
}
