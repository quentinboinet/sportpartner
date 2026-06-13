import { Controller } from "@hotwired/stimulus";
import { Chart, BarController, BarElement, CategoryScale, LinearScale, Tooltip } from 'chart.js';
Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip);

export default class extends Controller {
    static values = { data: Object };

    connect() {
        const d = this.dataValue;
        new Chart(this.element, {
            type: 'bar',
            data: {
                labels: d.days,
                datasets: [
                    {
                        label: d.labelIntake,
                        data: d.intake,
                        backgroundColor: '#22C55E',
                        borderRadius: 4,
                        barPercentage: 0.75,
                    },
                    {
                        label: d.labelExpenditure,
                        data: d.expenditure,
                        backgroundColor: '#EF4444',
                        borderRadius: 4,
                        barPercentage: 0.75,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: ctx => `${ctx.dataset.label}: ${ctx.raw} kcal`,
                        },
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { color: '#6B7280', font: { size: 11 } },
                    },
                    y: { display: false },
                },
            },
        });
    }
}
