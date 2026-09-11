import { Controller } from '@hotwired/stimulus';
import { Chart, registerables } from 'chart.js';

// `chart.js/auto` n'est pas dans l'importmap (seul `chart.js` l'est) : on
// enregistre les composants explicitement, une seule fois au chargement.
Chart.register(...registerables);

/*
 * Courbe du chiffre d'affaires sur 30 jours (écran de pilotage).
 * Les valeurs sont transmises en FCFA entiers par le serveur : aucun calcul
 * monétaire n'est fait ici, uniquement du formatage d'affichage.
 *
 * Chargement paresseux : Chart.js pèse plusieurs centaines de kilo-octets et ne
 * sert que sur le tableau de bord de la dirigeante. En « eager », il serait
 * téléchargé sur toutes les pages, y compris l'écran de caisse.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['canvas'];
    static values = { libelles: Array, valeurs: Array };

    connect() {
        const reduit = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        this.graphique = new Chart(this.canvasTarget, {
            type: 'line',
            data: {
                labels: this.libellesValue,
                datasets: [{
                    data: this.valeursValue,
                    borderColor: '#d97706',
                    // Dégradé sous la courbe, calculé à la taille réelle du graphique :
                    // l'ambre s'efface vers le bas au lieu de peser en aplat.
                    backgroundColor: (contexte) => this.degrade(contexte),
                    borderWidth: 2.5,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 0,
                    pointHoverRadius: 6,
                    pointHoverBackgroundColor: '#fff',
                    pointHoverBorderColor: '#b45309',
                    pointHoverBorderWidth: 3,
                    pointHitRadius: 20,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: reduit ? false : { duration: 900, easing: 'easeOutQuart' },
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1c1917',
                        titleColor: '#fcd34d',
                        bodyColor: '#fafaf9',
                        padding: 10,
                        cornerRadius: 10,
                        displayColors: false,
                        callbacks: {
                            label: (contexte) => `${this.fcfa(contexte.parsed.y)} FCFA`,
                        },
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: {
                            color: '#a8a29e',
                            // Sur un écran de téléphone, un libellé sur cinq suffit.
                            maxRotation: 0,
                            autoSkip: false,
                            callback: (valeur, index) => (index % 5 === 0 ? this.libellesValue[index] : ''),
                        },
                    },
                    y: {
                        beginAtZero: true,
                        border: { display: false },
                        grid: { color: 'rgba(120, 113, 108, .12)' },
                        ticks: { color: '#a8a29e', callback: (valeur) => this.compact(valeur) },
                    },
                },
            },
        });
    }

    degrade({ chart }) {
        const { ctx, chartArea } = chart;
        if (!chartArea) {
            // Premier passage, avant la mise en page : Chart.js rappellera.
            return 'rgba(245, 158, 11, .12)';
        }

        const degrade = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
        degrade.addColorStop(0, 'rgba(245, 158, 11, .35)');
        degrade.addColorStop(1, 'rgba(245, 158, 11, 0)');

        return degrade;
    }

    disconnect() {
        this.graphique?.destroy();
    }

    fcfa(valeur) {
        return Math.round(valeur).toLocaleString('fr-FR').replace(/ | /g, ' ');
    }

    // Axe vertical compact : « 250 k » plutôt que « 250 000 ».
    compact(valeur) {
        return valeur >= 1000 ? `${Math.round(valeur / 1000)} k` : String(valeur);
    }
}
