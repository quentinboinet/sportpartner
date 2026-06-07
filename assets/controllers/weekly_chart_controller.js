import { Controller } from '@hotwired/stimulus';
import {
    Chart,
    BarElement, CategoryScale, LinearScale, Tooltip, Legend,
    BarController, LineController, LineElement, PointElement, Filler,
} from 'chart.js';

Chart.register(
    BarElement, CategoryScale, LinearScale, Tooltip, Legend,
    BarController, LineController, LineElement, PointElement, Filler
);

export default class extends Controller {
    static values = {
        data: Array,
    };

    connect() {
        const weeks = this.dataValue;
        if (!weeks || weeks.length === 0) return;

        const labels    = weeks.map(w => `S${w.week}`);
        const distances = weeks.map(w => Math.round(w.totalDistance / 100) / 10); // km, 1 decimal

        // Forme : rolling 4-week average of distance, normalized to 35–90
        const maxDist = Math.max(...distances, 1);
        const forme = distances.map((_, i, arr) => {
            const window = arr.slice(Math.max(0, i - 3), i + 1);
            const avg    = window.reduce((a, b) => a + b, 0) / window.length;
            return Math.min(90, Math.max(35, Math.round(avg / maxDist * 60 + 32)));
        });

        const lastIdx   = distances.length - 1;
        const barColors = distances.map((_, i) =>
            i === lastIdx ? '#1a1b2e' : 'rgba(0,0,0,0.11)'
        );

        new Chart(this.element, {
            data: {
                labels,
                datasets: [
                    {
                        type: 'bar',
                        label: 'Distance (km)',
                        data: distances,
                        backgroundColor: barColors,
                        borderRadius: 6,
                        borderSkipped: false,
                        order: 2,
                        yAxisID: 'y',
                    },
                    {
                        type: 'line',
                        label: 'Forme',
                        data: forme,
                        borderColor: '#E8400C',
                        backgroundColor: 'rgba(232,64,12,0.07)',
                        fill: true,
                        tension: 0.45,
                        pointRadius: distances.map((_, i) => i === lastIdx ? 5 : 0),
                        pointBackgroundColor: '#E8400C',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        borderWidth: 2.5,
                        order: 1,
                        yAxisID: 'y2',
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
                        backgroundColor: '#16172A',
                        titleColor: 'rgba(255,255,255,.5)',
                        bodyColor: '#fff',
                        padding: 10,
                        cornerRadius: 8,
                        callbacks: {
                            label: (ctx) => ctx.dataset.yAxisID === 'y2'
                                ? ` Forme : ${ctx.raw}`
                                : ` ${ctx.raw} km`,
                        },
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: { color: '#9CA3AF', font: { size: 11 } },
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(0,0,0,.04)' },
                        border: { display: false },
                        ticks: { color: '#9CA3AF', font: { size: 11 } },
                    },
                    y2: {
                        position: 'right',
                        min: 0, max: 100,
                        grid: { display: false },
                        border: { display: false },
                        ticks: { display: false },
                    },
                },
            },
        });
    }
}
