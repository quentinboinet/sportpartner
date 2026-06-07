import { Controller } from '@hotwired/stimulus';
import { Chart, LineController, LineElement, PointElement, CategoryScale, LinearScale, Filler } from 'chart.js';

Chart.register(LineController, LineElement, PointElement, CategoryScale, LinearScale, Filler);

export default class extends Controller {
    static values = { values: Array };

    connect() {
        const data = this.valuesValue;

        if (!data || data.length === 0) {
            this.element.insertAdjacentHTML('afterend',
                '<div class="sp-chart-placeholder">Profil disponible après sync Strava</div>'
            );
            this.element.style.display = 'none';
            return;
        }

        new Chart(this.element, {
            type: 'line',
            data: {
                labels: data.map((_, i) => i),
                datasets: [{
                    data,
                    borderColor: '#E8400C',
                    backgroundColor: 'rgba(232,64,12,0.12)',
                    fill: true,
                    tension: 0.4,
                    borderWidth: 2,
                    pointRadius: 0,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
                scales: {
                    x: { display: false },
                    y: { display: false, beginAtZero: false },
                },
            },
        });
    }
}
