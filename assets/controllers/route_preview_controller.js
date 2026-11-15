import { Controller } from '@hotwired/stimulus';
import L from 'leaflet';
import { Chart, LineController, LineElement, PointElement, LinearScale, Filler, Tooltip } from 'chart.js';

Chart.register(LineController, LineElement, PointElement, LinearScale, Filler, Tooltip);

/*
 * Aperçu d'un parcours GPX (page d'une course) : carte Leaflet + mini profil Chart.js, en lecture seule.
 * L'édition (ajout de repères, mode visée) reste sur le plan de course (race_route_controller).
 *
 * Valeurs :
 *  - points      : [[distanceM, ele|null, lat, lon], …] (aperçu allégé, ~600 points)
 *  - checkpoints : [{ number, name, type, distanceM, lat, lon }, …]
 *  - labels      : { start, finish }
 *  - locale      : 'fr' | 'en'
 * Targets : map, chart (canvas), chartWrap.
 * Les libellés issus des données sont échappés (tooltips Leaflet = HTML).
 */

const ORANGE = '#E8400C';
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

export default class extends Controller {
    static targets = ['map', 'chart', 'chartWrap'];
    static values = { points: Array, checkpoints: Array, labels: Object, locale: { type: String, default: 'fr' } };

    connect() {
        this.points = this.pointsValue || [];
        if (this.points.length < 2) return;
        this.nf1 = new Intl.NumberFormat(this.localeValue, { minimumFractionDigits: 1, maximumFractionDigits: 1 });
        this.nf0 = new Intl.NumberFormat(this.localeValue, { maximumFractionDigits: 0 });
        this.buildMap();
        if (this.hasChartTarget && this.points.some(p => p[1] !== null && p[1] !== undefined)) {
            this.buildChart();
        } else if (this.hasChartWrapTarget) {
            this.chartWrapTarget.hidden = true;
        }
    }

    disconnect() {
        this.chart?.destroy();
        this.chart = null;
        this.map?.remove();
        this.map = null;
        this.marker = null;
    }

    buildMap() {
        const L_ = this.labelsValue || {};
        const latlngs = this.points.map(p => [p[2], p[3]]);
        const map = L.map(this.mapTarget, { zoomControl: true, attributionControl: true, scrollWheelZoom: false });
        map.attributionControl.setPrefix(false);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map);
        const track = L.polyline(latlngs, { color: ORANGE, weight: 3.5, opacity: 0.9, interactive: false }).addTo(map);
        map.fitBounds(track.getBounds(), { padding: [16, 16] });

        const dot = (cls) => L.divIcon({ className: '', html: `<div class="sp-map-dot ${cls}"></div>`, iconSize: [12, 12], iconAnchor: [6, 6] });
        L.marker(latlngs[0], { icon: dot('sp-map-dot-start'), keyboard: false }).bindTooltip(esc(L_.start), { direction: 'top', offset: [0, -6] }).addTo(map);
        L.marker(latlngs[latlngs.length - 1], { icon: dot('sp-map-dot-end'), keyboard: false }).bindTooltip(esc(L_.finish), { direction: 'top', offset: [0, -6] }).addTo(map);

        (this.checkpointsValue || []).forEach(cp => {
            const icon = L.divIcon({
                className: '',
                html: `<div class="rs-cp-pin rs-cp-pin--${esc(cp.type)}">${Number(cp.number) || ''}</div>`,
                iconSize: [20, 20], iconAnchor: [10, 10],
            });
            L.marker([cp.lat, cp.lon], { icon, keyboard: false })
                .bindTooltip(`${Number(cp.number)}. ${esc(cp.name)} · ${this.nf1.format(cp.distanceM / 1000)} km`, { direction: 'top', offset: [0, -10] })
                .addTo(map);
        });
        this.map = map;
    }

    buildChart() {
        const data = this.points.map(p => ({ x: p[0] / 1000, y: p[1] }));
        const totalKm = this.points[this.points.length - 1][0] / 1000;
        const ctrl = this;
        this.chart = new Chart(this.chartTarget, {
            type: 'line',
            data: { datasets: [{
                data, parsing: false, borderColor: ORANGE, borderWidth: 1.6, pointRadius: 0, pointHoverRadius: 3,
                fill: true, backgroundColor: 'rgba(232,64,12,.12)', tension: 0.15, spanGaps: true,
            }] },
            options: {
                animation: false, responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'nearest', axis: 'x', intersect: false },
                scales: {
                    x: { type: 'linear', min: 0, max: totalKm, grid: { display: false },
                         ticks: { maxTicksLimit: 6, font: { size: 10 }, callback: v => `${this.nf0.format(v)} km` } },
                    y: { grid: { color: 'rgba(0,0,0,.05)' }, ticks: { maxTicksLimit: 4, font: { size: 10 }, callback: v => `${this.nf0.format(v)} m` } },
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        displayColors: false,
                        callbacks: {
                            title: (items) => `${ctrl.nf1.format(items[0].parsed.x)} km`,
                            label: (item) => `${ctrl.nf0.format(item.parsed.y)} m`,
                        },
                    },
                },
                // Survol du profil -> position sur la carte
                onHover: (e, elements) => ctrl.showOnMap(elements.length ? elements[0].index : null),
            },
        });
        this.chartTarget.addEventListener('mouseleave', () => this.showOnMap(null));
    }

    showOnMap(i) {
        if (!this.map) return;
        if (i === null || !this.points[i]) {
            this.marker?.remove();
            this.marker = null;
            return;
        }
        const p = this.points[i];
        if (this.marker) {
            this.marker.setLatLng([p[2], p[3]]);
        } else {
            this.marker = L.circleMarker([p[2], p[3]], {
                radius: 6, color: '#fff', fillColor: ORANGE, fillOpacity: 1, weight: 2, interactive: false,
            }).addTo(this.map);
        }
    }
}
