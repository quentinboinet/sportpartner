import { Controller } from '@hotwired/stimulus';
import { Chart, LineController, LineElement, PointElement, CategoryScale, LinearScale, Filler, Tooltip } from 'chart.js';

Chart.register(LineController, LineElement, PointElement, CategoryScale, LinearScale, Filler, Tooltip);

// Plugin : ligne verticale de crosshair au survol
const crosshairPlugin = {
    id: 'crosshair',
    afterDraw(chart) {
        if (chart._crosshairX === undefined) return;
        const { ctx, chartArea: { top, bottom } } = chart;
        ctx.save();
        ctx.beginPath();
        ctx.moveTo(chart._crosshairX, top);
        ctx.lineTo(chart._crosshairX, bottom);
        ctx.strokeStyle = 'rgba(0,0,0,0.18)';
        ctx.lineWidth = 1;
        ctx.setLineDash([4, 3]);
        ctx.stroke();
        ctx.restore();
    },
};

export default class extends Controller {
    static values = { values: Array, distance: Number };

    connect() {
        const data = this.valuesValue;

        if (!data || data.length === 0) {
            this.element.insertAdjacentHTML('afterend',
                '<div class="sp-chart-placeholder">Profil disponible après sync Strava</div>'
            );
            this.element.style.display = 'none';
            return;
        }

        const totalKm = this.distanceValue / 1000;
        const labels  = data.map((_, i) => {
            const km = (i / (data.length - 1)) * totalKm;
            return `${km.toFixed(1)} km`;
        });

        new Chart(this.element, {
            type: 'line',
            plugins: [crosshairPlugin],
            data: {
                labels,
                datasets: [{
                    data,
                    borderColor: '#E8400C',
                    backgroundColor: 'rgba(232,64,12,0.12)',
                    fill: true,
                    tension: 0.4,
                    borderWidth: 2,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    pointHoverBackgroundColor: '#E8400C',
                    pointHoverBorderColor: '#fff',
                    pointHoverBorderWidth: 2,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                onHover(_, active, chart) {
                    if (active.length) {
                        chart._crosshairX = active[0].element.x;
                        const t = data.length > 1 ? active[0].index / (data.length - 1) : 0;
                        window.dispatchEvent(new CustomEvent('elevation:hover', { detail: { t } }));
                    } else {
                        delete chart._crosshairX;
                        window.dispatchEvent(new CustomEvent('elevation:leave'));
                    }
                    chart.draw();
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        enabled: true,
                        backgroundColor: 'rgba(22,23,42,0.92)',
                        titleColor: 'rgba(255,255,255,0.55)',
                        bodyColor: '#fff',
                        padding: { x: 12, y: 8 },
                        cornerRadius: 8,
                        displayColors: false,
                        callbacks: {
                            title: items => items[0].label,
                            label: item  => `${Math.round(item.raw)} m`,
                        },
                    },
                },
                scales: {
                    x: {
                        display: true,
                        grid: { display: false },
                        ticks: {
                            color: '#6B7280',
                            font: { size: 10 },
                            maxTicksLimit: 6,
                            maxRotation: 0,
                        },
                        border: { display: false },
                    },
                    y: {
                        display: true,
                        beginAtZero: false,
                        grid: { color: 'rgba(0,0,0,0.05)', drawBorder: false },
                        ticks: {
                            color: '#6B7280',
                            font: { size: 10 },
                            maxTicksLimit: 4,
                            callback: v => `${Math.round(v)} m`,
                        },
                        border: { display: false },
                    },
                },
            },
        });
    }
}
