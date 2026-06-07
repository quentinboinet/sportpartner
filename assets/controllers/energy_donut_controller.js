import { Controller } from "@hotwired/stimulus";
import Chart from "chart.js/auto";

export default class extends Controller {
    static values = { consumed: Number, goal: Number };

    connect() {
        const consumed = this.consumedValue;
        const goal     = this.goalValue;
        const remaining = Math.max(0, goal - consumed);

        new Chart(this.element, {
            type: 'doughnut',
            data: {
                datasets: [{
                    data: [consumed > 0 ? consumed : 0.001, remaining > 0 ? remaining : 0.001],
                    backgroundColor: ['#E8400C', '#2C2E3E'],
                    borderWidth: 0,
                    hoverOffset: 0,
                }],
            },
            options: {
                cutout: '80%',
                animation: { duration: 600, easing: 'easeOutQuart' },
                plugins: {
                    legend: { display: false },
                    tooltip: { enabled: false },
                },
            },
        });
    }
}
