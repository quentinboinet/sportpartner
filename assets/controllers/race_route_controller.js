import { Controller } from '@hotwired/stimulus';
import L from 'leaflet';
import { Chart, LineController, LineElement, PointElement, LinearScale, Filler, Tooltip } from 'chart.js';

Chart.register(LineController, LineElement, PointElement, LinearScale, Filler, Tooltip);

/*
 * Plan de course : carte Leaflet + profil Chart.js d'un parcours GPX importé.
 *
 * Valeurs :
 *  - route       : { points: [[distanceM, ele|null, lat, lon], …], distanceM, gainM, lossM, minEle, maxEle, fileName }
 *  - checkpoints : [{ id, name, type, distanceM, ele, stopMinutes, cutoff, notes }, …] triés par distance
 *  - labels      : libellés traduits { grade, start, finish }
 *  - locale      : 'fr' | 'en' (formatage des nombres)
 *
 * Événements émis (bubbles) :
 *  - race-route:pick   { distanceKm } - clic sur le profil ou près du tracé (ajout d'un repère)
 *  - race-route:select { id }         - clic sur un repère (édition)
 */

const ORANGE = '#E8400C';
const HIT_PX = 22;        // tolérance (px) pour accrocher le tracé sur la carte
const GRADE_WINDOW_M = 100; // demi-fenêtre de calcul de la pente au survol

const cssVar = (name, fallback) =>
    getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

const clamp = (v, min, max) => Math.min(max, Math.max(min, v));

// Index du dernier point dont la distance est ≤ d (points triés par distance)
function indexAt(points, d) {
    let lo = 0;
    let hi = points.length - 1;
    if (d <= points[lo][0]) return lo;
    if (d >= points[hi][0]) return hi;
    while (hi - lo > 1) {
        const mid = (lo + hi) >> 1;
        if (points[mid][0] <= d) lo = mid; else hi = mid;
    }
    return lo;
}

// Position et altitude interpolées à la distance d
function interpolate(points, d) {
    const i = indexAt(points, d);
    const a = points[i];
    const b = points[Math.min(i + 1, points.length - 1)];
    const span = b[0] - a[0];
    const t = span > 0 ? clamp((d - a[0]) / span, 0, 1) : 0;
    let ele = null;
    if (a[1] != null && b[1] != null) ele = a[1] + (b[1] - a[1]) * t;
    else ele = a[1] ?? b[1] ?? null;
    return { lat: a[2] + (b[2] - a[2]) * t, lon: a[3] + (b[3] - a[3]) * t, ele };
}

export default class extends Controller {
    static targets = ['map', 'chart', 'chartWrap', 'noElevation'];
    static values = {
        route: Object,
        checkpoints: Array,
        labels: Object,
        locale: { type: String, default: 'fr' },
    };

    connect() {
        const route = this.routeValue || {};
        this.points = Array.isArray(route.points) ? route.points : [];
        if (this.points.length < 2) return;

        this.totalM = route.distanceM || this.points[this.points.length - 1][0];
        this.labels = this.labelsValue || {};
        this.nf0 = new Intl.NumberFormat(this.localeValue, { maximumFractionDigits: 0 });
        this.nf1 = new Intl.NumberFormat(this.localeValue, { minimumFractionDigits: 1, maximumFractionDigits: 1 });
        this.colors = {
            point: cssVar('--sp-dark', '#16172A'),
            aid_station: cssVar('--sp-teal', '#06B6D4'),
        };

        // Numérotation identique au tableau (ordre distance, id) ; on n'affiche que les repères sur le parcours
        this.marks = (this.checkpointsValue || [])
            .map((cp, i) => ({ ...cp, number: i + 1 }))
            .filter(cp => cp.distanceM >= 0 && cp.distanceM <= this.totalM);

        if (this.hasMapTarget) this.buildMap();

        const hasEle = this.points.some(p => p[1] !== null && p[1] !== undefined);
        if (hasEle && this.hasChartTarget) {
            this.buildChart();
        } else {
            if (this.hasChartWrapTarget) this.chartWrapTarget.hidden = true;
            if (this.hasNoElevationTarget) this.noElevationTarget.hidden = false;
        }
    }

    disconnect() {
        if (this.onCanvasClick && this.hasChartTarget) {
            this.chartTarget.removeEventListener('click', this.onCanvasClick);
            this.chartTarget.removeEventListener('mousemove', this.onCanvasMove);
            this.chartTarget.removeEventListener('mouseleave', this.onCanvasLeave);
        }
        if (this.chart) { this.chart.destroy(); this.chart = null; }
        if (this.map) { this.map.remove(); this.map = null; }
        this.posMarker = null;
    }

    // ── Émission des événements ──────────────────────────────────────────────

    pick(distanceM) {
        // Arrondi au dixième ; près de l'arrivée le serveur tolère 50 m de dépassement et borne
        const km = Math.round(clamp(distanceM, 0, this.totalM) / 100) / 10;
        this.dispatch('pick', { detail: { distanceKm: km } });
    }

    select(id) {
        this.dispatch('select', { detail: { id } });
    }

    // ── Carte ────────────────────────────────────────────────────────────────

    buildMap() {
        const latlngs = this.points.map(p => [p[2], p[3]]);
        const map = L.map(this.mapTarget, { zoomControl: true, attributionControl: true });
        map.attributionControl.setPrefix(false);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap',
        }).addTo(map);

        const track = L.polyline(latlngs, { color: ORANGE, weight: 3.5, opacity: 0.9, interactive: false }).addTo(map);
        map.fitBounds(track.getBounds(), { padding: [20, 20] });

        const dot = (cls) => L.divIcon({ className: '', html: `<div class="sp-map-dot ${cls}"></div>`, iconSize: [12, 12], iconAnchor: [6, 6] });
        L.marker(latlngs[0], { icon: dot('sp-map-dot-start'), keyboard: false })
            .bindTooltip(esc(this.labels.start), { direction: 'top', offset: [0, -6] }).addTo(map);
        L.marker(latlngs[latlngs.length - 1], { icon: dot('sp-map-dot-end'), keyboard: false })
            .bindTooltip(esc(this.labels.finish), { direction: 'top', offset: [0, -6] }).addTo(map);

        // Repères numérotés (ravitaillements dans une autre couleur)
        this.marks.forEach(cp => {
            const pos = interpolate(this.points, cp.distanceM);
            const icon = L.divIcon({
                className: '',
                html: `<div class="rp-cp-pin rp-cp-pin--${esc(cp.type)}">${cp.number}</div>`,
                iconSize: [22, 22],
                iconAnchor: [11, 11],
            });
            L.marker([pos.lat, pos.lon], { icon, riseOnHover: true })
                .bindTooltip(`${cp.number}. ${esc(cp.name)} · ${this.nf1.format(cp.distanceM / 1000)} km`, { direction: 'top', offset: [0, -10] })
                .on('click', () => this.select(cp.id))
                .addTo(map);
        });

        // Survol / clic près du tracé : synchronisation avec le profil + ajout de repère
        map.on('mousemove', (e) => {
            const i = this.nearestIndex(e.latlng, e.containerPoint);
            this.mapTarget.classList.toggle('rp-map--near', i !== null);
            if (i === null) this.clearHover('map'); else this.showHover(i, 'map');
        });
        map.on('mouseout', () => {
            this.mapTarget.classList.remove('rp-map--near');
            this.clearHover('map');
        });
        map.on('click', (e) => {
            const i = this.nearestIndex(e.latlng, e.containerPoint);
            if (i !== null) this.pick(this.points[i][0]);
        });

        this.map = map;
    }

    // Point du tracé le plus proche (projection équirectangulaire), null si trop loin à l'écran
    nearestIndex(latlng, containerPoint) {
        const k = Math.cos(latlng.lat * Math.PI / 180);
        let best = -1;
        let bestD = Infinity;
        for (let i = 0; i < this.points.length; i++) {
            const dLat = this.points[i][2] - latlng.lat;
            const dLon = (this.points[i][3] - latlng.lng) * k;
            const d = dLat * dLat + dLon * dLon;
            if (d < bestD) { bestD = d; best = i; }
        }
        if (best < 0) return null;
        const p = this.map.latLngToContainerPoint([this.points[best][2], this.points[best][3]]);
        return p.distanceTo(containerPoint) <= HIT_PX ? best : null;
    }

    // ── Survol synchronisé carte ↔ profil ────────────────────────────────────

    showHover(i, source) {
        const p = this.points[i];
        if (this.map) {
            if (this.posMarker) {
                this.posMarker.setLatLng([p[2], p[3]]);
            } else {
                this.posMarker = L.circleMarker([p[2], p[3]], {
                    radius: 7, color: '#fff', fillColor: ORANGE, fillOpacity: 1, weight: 2.5, interactive: false,
                }).addTo(this.map);
            }
        }
        if (source !== 'chart' && this.chart) {
            const el = this.chart.getDatasetMeta(0).data[i];
            if (!el) return;
            const active = p[1] != null ? [{ datasetIndex: 0, index: i }] : [];
            this.chart._crosshairX = el.x;
            this.chart.setActiveElements(active);
            this.chart.tooltip.setActiveElements(active, { x: el.x, y: Number.isFinite(el.y) ? el.y : 0 });
            this.chart.update('none');
        }
    }

    clearHover(source) {
        if (this.posMarker) { this.posMarker.remove(); this.posMarker = null; }
        if (source !== 'chart' && this.chart && this.chart._crosshairX !== undefined) {
            delete this.chart._crosshairX;
            this.chart.setActiveElements([]);
            this.chart.tooltip.setActiveElements([], { x: 0, y: 0 });
            this.chart.update('none');
        }
    }

    // ── Profil altimétrique ──────────────────────────────────────────────────

    gradeAt(distanceM) {
        const from = clamp(distanceM - GRADE_WINDOW_M, 0, this.totalM);
        const to = clamp(distanceM + GRADE_WINDOW_M, 0, this.totalM);
        if (to - from < 20) return null;
        const a = interpolate(this.points, from).ele;
        const b = interpolate(this.points, to).ele;
        if (a === null || b === null) return null;
        return (b - a) / (to - from) * 100;
    }

    // Bulle de repère sous le pointeur (dessinée par le plugin), sinon null.
    // Parcours en ordre inverse du dessin : la bulle visible (dessinée en dernier) l'emporte.
    markAt(x, y) {
        for (let i = this.marks.length - 1; i >= 0; i--) {
            const cp = this.marks[i];
            if (cp._px !== undefined && Math.abs(x - cp._px) <= 10 && Math.abs(y - cp._py) <= 10) return cp;
        }
        return null;
    }

    buildChart() {
        const ctrl = this;
        const totalKm = this.totalM / 1000;
        const data = this.points.map(p => ({ x: p[0] / 1000, y: p[1] }));

        // Plugin : lignes verticales + bulles numérotées des repères
        const checkpointsPlugin = {
            id: 'rpCheckpoints',
            afterDatasetsDraw(chart) {
                const { ctx, chartArea: { top, bottom, left, right }, scales: { x } } = chart;
                const lastPx = [];
                ctx.save();
                ctrl.marks.forEach(cp => {
                    const px = x.getPixelForValue(cp.distanceM / 1000);
                    if (px < left - 1 || px > right + 1) { cp._px = undefined; return; }
                    const color = ctrl.colors[cp.type] || ctrl.colors.point;

                    ctx.globalAlpha = 0.55;
                    ctx.strokeStyle = color;
                    ctx.lineWidth = 1.5;
                    ctx.setLineDash([4, 3]);
                    ctx.beginPath();
                    ctx.moveTo(px, top);
                    ctx.lineTo(px, bottom);
                    ctx.stroke();

                    // Décale les bulles qui se chevauchent sur plusieurs rangées
                    let level = 0;
                    while (level < 2 && lastPx[level] !== undefined && px - lastPx[level] < 20) level++;
                    lastPx[level] = px;
                    const py = top - 11 + level * 21;

                    ctx.globalAlpha = 1;
                    ctx.setLineDash([]);
                    ctx.fillStyle = color;
                    ctx.beginPath();
                    ctx.arc(px, py, 9, 0, Math.PI * 2);
                    ctx.fill();
                    ctx.lineWidth = 1.5;
                    ctx.strokeStyle = '#fff';
                    ctx.stroke();
                    ctx.fillStyle = '#fff';
                    ctx.font = '700 10px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillText(String(cp.number), px, py + 0.5);
                    cp._px = px;
                    cp._py = py;
                });
                ctx.restore();
            },
        };

        // Plugin : crosshair vertical au survol
        const crosshairPlugin = {
            id: 'rpCrosshair',
            afterDraw(chart) {
                if (chart._crosshairX === undefined) return;
                const { ctx, chartArea: { top, bottom } } = chart;
                ctx.save();
                ctx.beginPath();
                ctx.moveTo(chart._crosshairX, top);
                ctx.lineTo(chart._crosshairX, bottom);
                ctx.strokeStyle = 'rgba(0,0,0,0.25)';
                ctx.lineWidth = 1;
                ctx.setLineDash([4, 3]);
                ctx.stroke();
                ctx.restore();
            },
        };

        this.chartTarget.style.cursor = 'crosshair';

        // Chart.js n'appelle onClick / onHover que dans la zone du graphe ; les bulles des repères
        // sont dessinées au-dessus (marge haute) : clic et curseur sont donc gérés sur le canvas.
        this.onCanvasClick = (e) => {
            const mark = this.markAt(e.offsetX, e.offsetY);
            if (mark) this.select(mark.id);
        };
        this.onCanvasMove = (e) => {
            this.chartTarget.style.cursor = this.markAt(e.offsetX, e.offsetY) ? 'pointer' : 'crosshair';
            const a = this.chart?.chartArea;
            if (a && this.chart._crosshairX !== undefined
                && (e.offsetX < a.left || e.offsetX > a.right || e.offsetY < a.top || e.offsetY > a.bottom)) {
                this.onCanvasLeave();
            }
        };
        this.onCanvasLeave = () => {
            if (!this.chart || this.chart._crosshairX === undefined) return;
            delete this.chart._crosshairX;
            this.clearHover('chart');
            this.chart.draw();
        };
        this.chartTarget.addEventListener('click', this.onCanvasClick);
        this.chartTarget.addEventListener('mousemove', this.onCanvasMove);
        this.chartTarget.addEventListener('mouseleave', this.onCanvasLeave);

        this.chart = new Chart(this.chartTarget, {
            type: 'line',
            plugins: [checkpointsPlugin, crosshairPlugin],
            data: {
                datasets: [{
                    data,
                    spanGaps: true,
                    borderColor: ORANGE,
                    backgroundColor: 'rgba(232,64,12,0.12)',
                    fill: 'start',
                    tension: 0,
                    borderWidth: 2,
                    pointRadius: 0,
                    pointHitRadius: 0,
                    pointHoverRadius: 4,
                    pointHoverBackgroundColor: ORANGE,
                    pointHoverBorderColor: '#fff',
                    pointHoverBorderWidth: 2,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                parsing: false,     // données déjà au format { x, y }
                normalized: true,   // x croissant : recherche binaire au survol
                layout: { padding: { top: 24, right: 6 } },
                interaction: { mode: 'nearest', axis: 'x', intersect: false },
                onHover(e, active, chart) {
                    if (active.length && e.type !== 'mouseout') {
                        chart._crosshairX = active[0].element.x;
                        ctrl.showHover(active[0].index, 'chart');
                    } else {
                        delete chart._crosshairX;
                        ctrl.clearHover('chart');
                    }
                    chart.draw();
                },
                onClick(e, _active, chart) {
                    const { chartArea: a, scales: { x } } = chart;
                    // Clic sur une bulle : géré par onCanvasClick (les bulles débordent au-dessus de la zone du graphe)
                    if (ctrl.markAt(e.x, e.y)) return;
                    if (e.x < a.left || e.x > a.right || e.y > a.bottom) return;
                    ctrl.pick(clamp(x.getValueForPixel(e.x), 0, totalKm) * 1000);
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
                        callbacks: {
                            title: items => `${ctrl.nf1.format(items[0].parsed.x)} km`,
                            label: item => `${ctrl.nf0.format(item.parsed.y)} m`,
                            afterLabel: item => {
                                const g = ctrl.gradeAt(item.parsed.x * 1000);
                                if (g === null) return '';
                                const sign = g > 0.05 ? '+' : '';
                                return `${ctrl.labels.grade || ''} ${sign}${ctrl.nf1.format(g)} %`.trim();
                            },
                        },
                    },
                },
                scales: {
                    x: {
                        type: 'linear',
                        min: 0,
                        max: totalKm,
                        grid: { display: false },
                        border: { display: false },
                        ticks: {
                            color: '#6B7280',
                            font: { size: 10 },
                            maxTicksLimit: 8,
                            maxRotation: 0,
                            callback: v => `${ctrl.nf0.format(v)} km`,
                        },
                    },
                    y: {
                        type: 'linear',
                        grace: '8%',
                        grid: { color: 'rgba(0,0,0,0.05)' },
                        border: { display: false },
                        ticks: {
                            color: '#6B7280',
                            font: { size: 10 },
                            maxTicksLimit: 5,
                            callback: v => `${ctrl.nf0.format(v)} m`,
                        },
                    },
                },
            },
        });
    }
}
