import { Controller } from '@hotwired/stimulus';
import {
    Chart,
    BarController, BarElement,
    CategoryScale, LinearScale,
    Tooltip,
} from 'chart.js';

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip);

export default class extends Controller {
    static values = {
        data: Object,  // { labels: string[], granularity: string, datasets: [{slug, color, data[]}] }
    };

    connect() {
        const { labels, datasets } = this.dataValue;
        if (!labels || labels.length === 0) {
            this.element.insertAdjacentHTML('afterend',
                '<div class="sp-chart-placeholder">Aucune donnée</div>');
            this.element.style.display = 'none';
            return;
        }

        new Chart(this.element, {
            type: 'bar',
            data: {
                labels,
                datasets: datasets.map(d => ({
                    label: d.slug,
                    data: d.data,
                    backgroundColor: d.color,
                    borderRadius: 5,
                    borderSkipped: false,
                    stack: 'volume',
                })),
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
                            label: (ctx) => ` ${ctx.dataset.label}: ${ctx.raw} km`,
                        },
                    },
                },
                scales: {
                    x: {
                        stacked: true,
                        grid: { display: false },
                        border: { display: false },
                        ticks: { color: '#9CA3AF', font: { size: 11 } },
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        grid: { color: 'rgba(0,0,0,.04)' },
                        border: { display: false },
                        ticks: { color: '#9CA3AF', font: { size: 11 } },
                    },
                },
            },
        });
    }
}
