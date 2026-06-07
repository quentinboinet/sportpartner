import { Controller } from '@hotwired/stimulus';
import {
    Chart,
    LineController, LineElement, PointElement,
    CategoryScale, LinearScale,
    Filler, Tooltip,
} from 'chart.js';

Chart.register(LineController, LineElement, PointElement, CategoryScale, LinearScale, Filler, Tooltip);

export default class extends Controller {
    static values = {
        data: Array,  // [{week: 'S12', paceSec: 285}, ...]
    };

    connect() {
        const data = this.dataValue;
        if (!data || data.length === 0) {
            this.element.insertAdjacentHTML('afterend',
                '<div class="sp-chart-placeholder">Aucune donnée de course</div>');
            this.element.style.display = 'none';
            return;
        }

        const labels = data.map(d => d.week);
        const paces  = data.map(d => d.paceSec);
        const lastIdx = data.length - 1;

        new Chart(this.element, {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    data: paces,
                    borderColor: '#E8400C',
                    backgroundColor: 'rgba(232,64,12,0.09)',
                    fill: true,
                    tension: 0.45,
                    borderWidth: 2.5,
                    pointRadius: paces.map((_, i) => i === lastIdx ? 5 : 0),
                    pointBackgroundColor: '#E8400C',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
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
                        padding: 10,
                        cornerRadius: 8,
                        callbacks: {
                            label: (ctx) => {
                                const s = ctx.raw;
                                return ` ${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')} /km`;
                            },
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
                        beginAtZero: false,
                        grid: { color: 'rgba(0,0,0,.04)' },
                        border: { display: false },
                        ticks: {
                            color: '#9CA3AF',
                            font: { size: 11 },
                            callback: (v) => `${Math.floor(v / 60)}:${String(Math.floor(v % 60)).padStart(2, '0')}`,
                        },
                    },
                },
            },
        });
    }
}
