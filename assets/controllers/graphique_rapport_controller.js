import { Controller } from '@hotwired/stimulus';
import { Chart, registerables } from 'chart.js';

// `chart.js/auto` n'est pas dans l'importmap : composants enregistrés à la main.
Chart.register(...registerables);

/*
 * Histogramme des rapports des stands : une série par stand, par vendeur, ou caisse
 * et stands côte à côte. L'axe horizontal suit le pas choisi (jour, semaine, mois).
 *
 * Les valeurs arrivent en FCFA entiers : aucun calcul monétaire ici, du formatage.
 * Chargement paresseux, comme la courbe du pilotage : Chart.js ne descend que sur
 * les pages qui en montrent un.
 */

// Teintes chaudes, dans l'esprit des touches produits ; l'ambre en premier.
const TEINTES = ['#d97706', '#b45309', '#a16207', '#9a3412', '#65a30d', '#be123c', '#7c2d12', '#57534e'];

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['canvas'];
    static values = { libelles: Array, series: Array, empile: Boolean };

    connect() {
        const reduit = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        this.graphique = new Chart(this.canvasTarget, {
            type: 'bar',
            data: {
                labels: this.libellesValue,
                datasets: this.seriesValue.map((serie, i) => ({
                    label: serie.libelle,
                    data: serie.valeurs,
                    backgroundColor: TEINTES[i % TEINTES.length],
                    borderRadius: 4,
                    maxBarThickness: 48,
                })),
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: reduit ? false : { duration: 700, easing: 'easeOutQuart' },
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: this.seriesValue.length > 1, position: 'bottom', labels: { boxWidth: 12, color: '#57534e' } },
                    tooltip: {
                        backgroundColor: '#1c1917',
                        titleColor: '#fcd34d',
                        bodyColor: '#fafaf9',
                        padding: 10,
                        cornerRadius: 10,
                        callbacks: {
                            label: (contexte) => `${contexte.dataset.label} : ${this.fcfa(contexte.parsed.y)} FCFA`,
                        },
                    },
                },
                scales: {
                    x: { stacked: this.empileValue, grid: { display: false }, border: { display: false }, ticks: { color: '#a8a29e', maxRotation: 0, autoSkip: true } },
                    y: {
                        stacked: this.empileValue,
                        beginAtZero: true,
                        border: { display: false },
                        grid: { color: 'rgba(120, 113, 108, .12)' },
                        ticks: { color: '#a8a29e', callback: (valeur) => this.compact(valeur) },
                    },
                },
            },
        });
    }

    disconnect() {
        this.graphique?.destroy();
    }

    fcfa(valeur) {
        return Math.round(valeur).toLocaleString('fr-FR').replace(/ | /g, ' ');
    }

    compact(valeur) {
        return valeur >= 1000 ? `${Math.round(valeur / 1000)} k` : String(valeur);
    }
}
