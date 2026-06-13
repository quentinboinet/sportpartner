import { Controller } from '@hotwired/stimulus';
import { Chart, BarController, BarElement, LineController, LineElement, PointElement, CategoryScale, LinearScale, Tooltip } from 'chart.js';

Chart.register(BarController, BarElement, LineController, LineElement, PointElement, CategoryScale, LinearScale, Tooltip);

function fmtPace(seconds) {
    return `${Math.floor(seconds / 60)}:${String(Math.floor(seconds % 60)).padStart(2, '0')}`;
}

export default class extends Controller {
    static values = { splits: Array };

    connect() {
        const splits = this.splitsValue; // seconds/km per split

        if (!splits || splits.length === 0) {
            this.element.insertAdjacentHTML('afterend',
                '<div class="sp-chart-placeholder">Allure disponible après sync Strava</div>'
            );
            this.element.style.display = 'none';
            return;
        }

        const avgPace = Math.round(splits.reduce((a, b) => a + b, 0) / splits.length);
        const minPace = Math.min(...splits);
        const maxPace = Math.max(...splits);
        const range   = maxPace - minPace || 1;

        // Convert pace (s/km) to speed (km/h) so faster splits have taller bars.
        const speeds = splits.map(p => Math.round((3600 / p) * 10) / 10);

        // Gradient coloring: fastest = solid orange, slowest = very faint.
        const colors = splits.map(p => {
            const ratio = 1 - (p - minPace) / range;
            return `rgba(232,64,12,${(0.2 + ratio * 0.8).toFixed(2)})`;
        });

        // Average pace as a horizontal reference line (constant speed value).
        const avgSpeed = Math.round((3600 / avgPace) * 10) / 10;
        const avgLine  = splits.map(() => avgSpeed);

        new Chart(this.element, {
            data: {
                labels: splits.map((_, i) => `Km ${i + 1}`),
                datasets: [
                    {
                        type: 'bar',
                        data: speeds,
                        backgroundColor: colors,
                        borderRadius: 4,
                        borderSkipped: false,
                        order: 2,
                    },
                    {
                        type: 'line',
                        data: avgLine,
                        borderColor: 'rgba(0,0,0,0.25)',
                        borderWidth: 1.5,
                        borderDash: [4, 3],
                        pointRadius: 0,
                        order: 1,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(22,23,42,0.92)',
                        titleColor: 'rgba(255,255,255,0.55)',
                        bodyColor: '#fff',
                        padding: { x: 12, y: 8 },
                        cornerRadius: 8,
                        displayColors: false,
                        callbacks: {
                            title: items => items[0].label,
                            label: ctx => {
                                if (ctx.datasetIndex === 1) return `Moyenne : ${fmtPace(avgPace)} /km`;
                                return `${fmtPace(splits[ctx.dataIndex])} /km`;
                            },
                        },
                        filter: (item, _, __, chart) => {
                            // Show average line tooltip only once (first bar hovered).
                            if (item.datasetIndex === 1) return item.dataIndex === 0;
                            return true;
                        },
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: { color: '#9CA3AF', font: { size: 10 }, maxRotation: 0 },
                    },
                    y: {
                        display: false,
                        beginAtZero: false,
                        min: Math.max(0, Math.min(...speeds) - 1),
                        max: Math.max(...speeds) + 1,
                    },
                },
            },
        });
    }
}
