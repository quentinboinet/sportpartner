import { Controller } from '@hotwired/stimulus';
import { Chart, LineController, LineElement, PointElement, CategoryScale, LinearScale, Filler, Tooltip } from 'chart.js';

Chart.register(LineController, LineElement, PointElement, CategoryScale, LinearScale, Filler, Tooltip);

// Plugin : ligne verticale crosshair
const crosshairPlugin = {
    id: 'hr-crosshair',
    afterDraw(chart) {
        if (chart._crosshairX === undefined) return;
        const { ctx, chartArea: { top, bottom } } = chart;
        ctx.save();
        ctx.beginPath();
        ctx.moveTo(chart._crosshairX, top);
        ctx.lineTo(chart._crosshairX, bottom);
        ctx.strokeStyle = 'rgba(0,0,0,0.15)';
        ctx.lineWidth = 1;
        ctx.setLineDash([4, 3]);
        ctx.stroke();
        ctx.restore();
    },
};

export default class extends Controller {
    static values = { data: Array, distance: Number, maxHr: Number };

    connect() {
        const hrData = this.dataValue;

        if (!hrData || hrData.length === 0) {
            this.element.insertAdjacentHTML('afterend',
                '<div class="sp-chart-placeholder">FC non disponible (montre HR requise)</div>'
            );
            this.element.style.display = 'none';
            return;
        }

        const totalKm  = this.distanceValue / 1000;
        const maxHr    = this.maxHrValue || Math.max(...hrData);
        const avgHr    = Math.round(hrData.reduce((a, b) => a + b, 0) / hrData.length);

        const labels = hrData.map((_, i) => {
            const km = (i / (hrData.length - 1)) * totalKm;
            return `${km.toFixed(1)} km`;
        });

        // Zone thresholds as % of maxHR: Z1<60, Z2<70, Z3<80, Z4<90, Z5>=90
        const zoneColor = (bpm) => {
            const pct = bpm / maxHr;
            if (pct < 0.60) return '#93C5FD';
            if (pct < 0.70) return '#22C55E';
            if (pct < 0.80) return '#F59E0B';
            if (pct < 0.90) return '#FB923C';
            return '#EF4444';
        };

        // Dominant zone color for the fill gradient
        const dominantColor = zoneColor(avgHr);

        new Chart(this.element, {
            type: 'line',
            plugins: [crosshairPlugin],
            data: {
                labels,
                datasets: [
                    {
                        data: hrData,
                        borderColor: '#EF4444',
                        backgroundColor: 'rgba(239,68,68,0.10)',
                        fill: true,
                        tension: 0.4,
                        borderWidth: 2,
                        pointRadius: 0,
                        pointHoverRadius: 4,
                        pointHoverBackgroundColor: '#EF4444',
                        pointHoverBorderColor: '#fff',
                        pointHoverBorderWidth: 2,
                        order: 2,
                    },
                    // Ligne de FC moyenne
                    {
                        data: hrData.map(() => avgHr),
                        borderColor: 'rgba(0,0,0,0.20)',
                        borderWidth: 1.5,
                        borderDash: [4, 3],
                        pointRadius: 0,
                        fill: false,
                        order: 1,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                onHover(_, active, chart) {
                    chart._crosshairX = active.length ? active[0].element.x : undefined;
                    chart.draw();
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(22,23,42,0.92)',
                        titleColor: 'rgba(255,255,255,0.55)',
                        bodyColor: '#fff',
                        padding: { x: 12, y: 8 },
                        cornerRadius: 8,
                        displayColors: false,
                        filter: item => item.datasetIndex === 0,
                        callbacks: {
                            title: items => items[0].label,
                            label: item => `${Math.round(item.raw)} bpm`,
                            afterLabel: item => {
                                const pct = item.raw / maxHr;
                                if (pct < 0.60) return 'Zone 1 — Récupération';
                                if (pct < 0.70) return 'Zone 2 — Endurance';
                                if (pct < 0.80) return 'Zone 3 — Tempo';
                                if (pct < 0.90) return 'Zone 4 — Seuil';
                                return 'Zone 5 — VO2max';
                            },
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
                        grid: { color: 'rgba(0,0,0,0.05)' },
                        ticks: {
                            color: '#6B7280',
                            font: { size: 10 },
                            maxTicksLimit: 4,
                            callback: v => `${Math.round(v)} bpm`,
                        },
                        border: { display: false },
                    },
                },
            },
        });
    }
}
