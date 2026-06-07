import { Controller } from '@hotwired/stimulus';
import { Chart, BarController, BarElement, CategoryScale, LinearScale, Tooltip } from 'chart.js';

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip);

export default class extends Controller {
    static values = { splits: Array };

    connect() {
        const splits = this.splitsValue; // pace in s/km per km split

        if (!splits || splits.length === 0) {
            this.element.insertAdjacentHTML('afterend',
                '<div class="sp-chart-placeholder">Splits disponibles après sync Strava</div>'
            );
            this.element.style.display = 'none';
            return;
        }

        const minPace   = Math.min(...splits);
        const speeds    = splits.map(p => Math.round(3600 / p * 10) / 10); // km/h
        const maxSpeed  = Math.max(...speeds);
        const colors    = splits.map(p => p === minPace ? '#E8400C' : 'rgba(0,0,0,0.10)');

        new Chart(this.element, {
            type: 'bar',
            data: {
                labels: splits.map((_, i) => `${i + 1}`),
                datasets: [{
                    data: speeds,
                    backgroundColor: colors,
                    borderRadius: 4,
                    borderSkipped: false,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#16172A',
                        bodyColor: '#fff',
                        padding: 8,
                        cornerRadius: 8,
                        callbacks: {
                            label: (ctx) => {
                                const s = splits[ctx.dataIndex];
                                return ` ${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')} /km`;
                            },
                        },
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: { color: '#9CA3AF', font: { size: 10 } },
                    },
                    y: {
                        display: false,
                        beginAtZero: false,
                        min: Math.max(0, Math.min(...speeds) - 2),
                        max: maxSpeed + 1,
                    },
                },
            },
        });
    }
}
