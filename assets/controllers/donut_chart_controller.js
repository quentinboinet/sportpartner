import { Controller } from '@hotwired/stimulus';
import { Chart, DoughnutController, ArcElement, Tooltip } from 'chart.js';

Chart.register(DoughnutController, ArcElement, Tooltip);

export default class extends Controller {
    static values = {
        data: Array,  // [{slug, color, km, pct}]
    };

    connect() {
        const data = this.dataValue;
        if (!data || data.length === 0) return;

        new Chart(this.element, {
            type: 'doughnut',
            data: {
                labels: data.map(d => d.slug),
                datasets: [{
                    data: data.map(d => d.pct),
                    backgroundColor: data.map(d => d.color),
                    borderWidth: 3,
                    borderColor: '#ffffff',
                    hoverBorderWidth: 3,
                    hoverBorderColor: '#ffffff',
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '72%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#16172A',
                        bodyColor: '#fff',
                        padding: 10,
                        cornerRadius: 8,
                        callbacks: {
                            label: (ctx) => ` ${ctx.raw}%`,
                        },
                    },
                },
            },
        });
    }
}
