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
        this.graphique = new Chart(this.canvasTarget, {
            type: 'line',
            data: {
                labels: this.libellesValue,
                datasets: [{
                    data: this.valeursValue,
                    borderColor: '#b45309',
                    backgroundColor: 'rgba(180, 83, 9, .10)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.3,
                    pointRadius: 0,
                    pointHoverRadius: 5,
                    pointHitRadius: 20,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (contexte) => `${this.fcfa(contexte.parsed.y)} FCFA`,
                        },
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: {
                            // Sur un écran de téléphone, un libellé sur cinq suffit.
                            maxRotation: 0,
                            autoSkip: false,
                            callback: (valeur, index) => (index % 5 === 0 ? this.libellesValue[index] : ''),
                        },
                    },
                    y: {
                        beginAtZero: true,
                        border: { display: false },
                        ticks: { callback: (valeur) => this.compact(valeur) },
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

    // Axe vertical compact : « 250 k » plutôt que « 250 000 ».
    compact(valeur) {
        return valeur >= 1000 ? `${Math.round(valeur / 1000)} k` : String(valeur);
    }
}
