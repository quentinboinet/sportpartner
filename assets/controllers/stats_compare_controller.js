import { Controller } from '@hotwired/stimulus';
import {
    Chart,
    LineController, LineElement, PointElement,
    BarController, BarElement,
    CategoryScale, LinearScale,
    Tooltip,
} from 'chart.js';

Chart.register(LineController, LineElement, PointElement, BarController, BarElement, CategoryScale, LinearScale, Tooltip);

const COLOR_A = '#E8400C';
const COLOR_B = '#9CA3AF';
const ICONS = { up: 'bi-arrow-up-right', down: 'bi-arrow-down-right', flat: 'bi-arrow-right' };

/**
 * Statistiques, vue Comparer (/stats/comparer) : cumul jour par jour (A orange plein, B gris pointillé),
 * barres côte à côte par tranche alignées par indice, barres groupées par sport.
 *
 * Le sélecteur d'indicateur (boutons radio : distance / temps / D+ / séances) met à jour les trois graphiques,
 * leur description (aria-label avec les chiffres clés), le tableau de données des tranches, et annonce le
 * changement (role="status"). Les séries et les textes du tableau sont calculés côté serveur
 * (ComparisonPresenter::chart) ; tout est inséré via textContent.
 */
export default class extends Controller {
    static targets = ['cumulative', 'buckets', 'sports', 'metric', 'bucketRows', 'bucketCaption', 'status'];
    static values = {
        data: Object,    // { locale, cumulative: {days, dates: {a, b}, values}, buckets: {granularity, labels, ranges, values, rows}, sports, totals }
        labels: Object,  // textes traduits (périodes, indicateurs, descriptions avec %metric%, %a%, %b%, %count%)
    };

    connect() {
        this.metric = this.metricTargets.find((input) => input.checked)?.value || 'distance';
        this.locale = this.dataValue.locale || document.documentElement.lang || 'fr';
        this.charts = [];
        if (this.hasCumulativeTarget) this.charts.push(this.cumulativeChart = this.buildCumulative());
        if (this.hasBucketsTarget) this.charts.push(this.bucketChart = this.buildBuckets());
        if (this.hasSportsTarget && this.dataValue.sports) this.charts.push(this.sportChart = this.buildSports());
        this.refresh(false);
    }

    disconnect() {
        this.charts.forEach((chart) => chart.destroy());
        this.charts = [];
    }

    switch(event) {
        this.metric = event.target.value;
        this.refresh(true);
    }

    // ── Graphiques ────────────────────────────────────────────

    buildCumulative() {
        const cumul = this.dataValue.cumulative;
        const labels = Array.from({ length: cumul.days }, (_, i) => `${this.labelsValue.dayPrefix}${i + 1}`);

        return new Chart(this.cumulativeTarget, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    this.lineDataset(this.labelsValue.periodA, COLOR_A, false),
                    this.lineDataset(this.labelsValue.periodB, COLOR_B, true),
                ],
            },
            options: this.options({
                title: (items) => labels[items[0].dataIndex] ?? '',
                label: (ctx) => {
                    const date = cumul.dates[ctx.datasetIndex === 0 ? 'a' : 'b'][ctx.dataIndex];
                    return ` ${ctx.dataset.label}${date ? ` (${this.shortDate(date)})` : ''}${this.sep()}${this.format(ctx.raw)}`;
                },
            }, { maxTicksLimit: 10 }),
        });
    }

    buildBuckets() {
        const buckets = this.dataValue.buckets;

        return new Chart(this.bucketsTarget, {
            type: 'bar',
            data: {
                labels: buckets.labels,
                datasets: [
                    this.barDataset(this.labelsValue.periodA, COLOR_A),
                    this.barDataset(this.labelsValue.periodB, COLOR_B),
                ],
            },
            options: this.options({
                title: (items) => buckets.labels[items[0].dataIndex] ?? '',
                label: (ctx) => {
                    const range = buckets.ranges[ctx.dataIndex]?.[ctx.datasetIndex === 0 ? 'a' : 'b'];
                    return ` ${ctx.dataset.label}${range ? ` (${range})` : ''}${this.sep()}${this.format(ctx.raw)}`;
                },
            }),
        });
    }

    buildSports() {
        const sports = this.dataValue.sports;

        return new Chart(this.sportsTarget, {
            type: 'bar',
            data: {
                labels: sports.labels,
                datasets: [
                    this.barDataset(this.labelsValue.periodA, COLOR_A),
                    this.barDataset(this.labelsValue.periodB, COLOR_B),
                ],
            },
            options: this.options({
                title: (items) => sports.labels[items[0].dataIndex] ?? '',
                label: (ctx) => ` ${ctx.dataset.label}${this.sep()}${this.format(ctx.raw)}`,
            }),
        });
    }

    lineDataset(label, color, dashed) {
        return {
            label,
            data: [],
            borderColor: color,
            backgroundColor: color,
            borderWidth: dashed ? 2 : 2.5,
            borderDash: dashed ? [6, 4] : [],
            pointRadius: 0,
            pointHoverRadius: 4,
            tension: 0.15,
            spanGaps: false,
        };
    }

    barDataset(label, color) {
        return { label, data: [], backgroundColor: color, borderRadius: 4, borderSkipped: false, maxBarThickness: 28 };
    }

    options(callbacks, xTicks = {}) {
        return {
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 250 },
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#16172A',
                    titleColor: 'rgba(255,255,255,.7)',
                    bodyColor: '#fff',
                    padding: 10,
                    cornerRadius: 8,
                    callbacks,
                },
            },
            scales: {
                x: {
                    grid: { display: false },
                    border: { display: false },
                    ticks: { color: '#6B7280', font: { size: 11 }, maxRotation: 0, autoSkip: true, ...xTicks },
                },
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(0,0,0,.05)' },
                    border: { display: false },
                    ticks: { color: '#6B7280', font: { size: 11 }, callback: (v) => this.tick(v) },
                },
            },
        };
    }

    // ── Mise à jour (indicateur choisi) ───────────────────────

    refresh(announce) {
        const metric = this.metric;
        const toChart = (values) => values.map((v) => (v === null || v === undefined ? null : (metric === 'time' ? v / 60 : v)));
        const name = this.labelsValue.metrics[metric] ?? metric;

        if (this.cumulativeChart) {
            const series = this.dataValue.cumulative.values[metric];
            this.setData(this.cumulativeChart, [toChart(series.a), toChart(series.b)]);
            const totals = this.dataValue.totals[metric] ?? { a: '-', b: '-' };
            this.cumulativeTarget.setAttribute('aria-label', this.fill(this.labelsValue.ariaCumulative, { metric: name, a: totals.a, b: totals.b }));
        }
        if (this.bucketChart) {
            const series = this.dataValue.buckets.values[metric];
            this.setData(this.bucketChart, [toChart(series.a), toChart(series.b)]);
            this.bucketsTarget.setAttribute('aria-label', this.fill(this.labelsValue.ariaBuckets, { metric: name, count: this.dataValue.buckets.labels.length }));
        }
        if (this.sportChart) {
            const series = this.dataValue.sports.values[metric];
            this.setData(this.sportChart, [toChart(series.a), toChart(series.b)]);
            this.sportsTarget.setAttribute('aria-label', this.fill(this.labelsValue.ariaSports, { metric: name, count: this.dataValue.sports.labels.length }));
        }
        this.renderTable(metric, name);

        this.element.dataset.metric = metric;
        if (announce && this.hasStatusTarget) {
            this.statusTarget.textContent = this.fill(this.labelsValue.status, { metric: name });
        }
    }

    setData(chart, series) {
        series.forEach((data, i) => { chart.data.datasets[i].data = data; });
        chart.update();
    }

    /** Tableau de données des tranches (alternative textuelle des barres), reconstruit sans innerHTML. */
    renderTable(metric, name) {
        if (!this.hasBucketRowsTarget) return;
        const buckets = this.dataValue.buckets;
        const rows = buckets.rows[metric] ?? [];
        const body = this.bucketRowsTarget;
        body.replaceChildren();
        rows.forEach((row, i) => {
            const tr = document.createElement('tr');
            const range = buckets.ranges[i] ?? {};
            const th = document.createElement('th');
            th.scope = 'row';
            th.append(buckets.labels[i] ?? '');
            if (range.a) th.append(' ', this.span('sp-cmp-cell-sub', range.a));
            const a = document.createElement('td');
            a.textContent = row.a;
            const b = document.createElement('td');
            b.append(row.b);
            if (range.b) b.append(' ', this.span('sp-cmp-cell-sub', range.b));
            const d = document.createElement('td');
            d.append(this.delta(row));
            tr.append(th, a, b, d);
            body.append(tr);
        });
        if (this.hasBucketCaptionTarget) {
            const base = this.bucketCaptionTarget.textContent.split(' · ')[0];
            this.bucketCaptionTarget.textContent = `${base} · ${name}`;
        }
    }

    /** Même rendu que templates/stats/_compare_delta.html.twig (flèche, signe, texte masqué). */
    delta(row) {
        const out = this.span(`sp-cmp-delta sp-cmp-tone--${row.tone} sp-cmp-delta--compact`);
        out.dataset.tone = row.tone;
        out.dataset.direction = row.direction;
        const sr = this.labelsValue.sr ?? {};
        if (row.direction === 'none') {
            const dash = this.span('sp-cmp-delta-abs', '-');
            dash.setAttribute('aria-hidden', 'true');
            out.append(dash, this.span('visually-hidden', sr.none ?? ''));
            return out;
        }
        const icon = document.createElement('i');
        icon.className = `bi ${ICONS[row.direction] ?? ''}`;
        icon.setAttribute('aria-hidden', 'true');
        out.append(icon, this.span('sp-cmp-delta-abs', row.delta));
        if (row.percent !== null && row.percent !== undefined) out.append(' ', this.span('sp-cmp-delta-pct', `(${row.percent})`));
        out.append(' ', this.span('visually-hidden', sr[row.direction] ?? ''));
        return out;
    }

    span(className, text = '') {
        const el = document.createElement('span');
        el.className = className;
        el.textContent = text;
        return el;
    }

    // ── Mise en forme ─────────────────────────────────────────

    /** Valeur affichée (temps tracé en heures, affiché en h/min). */
    format(value) {
        if (value === null || value === undefined) return '-';
        switch (this.metric) {
            case 'distance': return `${this.number(value, 1)} km`;
            case 'time': return this.duration(Math.round(value * 60));
            case 'elevation': return `${this.number(value, 0)} m`;
            default: {
                // fr : 0 et 1 au singulier ; en : 1 seulement
                const one = this.isFrench() ? value <= 1 : value === 1;
                return `${this.number(value, 0)} ${(one ? this.labelsValue.sessionOne : this.labelsValue.sessionMany) ?? ''}`.trim();
            }
        }
    }

    tick(value) {
        switch (this.metric) {
            case 'distance': return `${this.number(value, 0)} km`;
            case 'time': return `${this.number(value, value % 1 === 0 ? 0 : 1)} h`;
            case 'elevation': return `${this.number(value, 0)} m`;
            default: return Number.isInteger(value) ? this.number(value, 0) : '';
        }
    }

    number(value, digits) {
        return new Intl.NumberFormat(this.locale, { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(value);
    }

    /** « 45 min », « 2h », « 1h05 » (fr) / « 1h 05m » (en), comme ComparisonFormat::duration(). */
    duration(minutes) {
        if (minutes < 60) return `${minutes} min`;
        const h = Math.floor(minutes / 60);
        const m = minutes % 60;
        if (m === 0) return `${this.number(h, 0)}h`;
        const mm = String(m).padStart(2, '0');
        return this.isFrench() ? `${this.number(h, 0)}h${mm}` : `${this.number(h, 0)}h ${mm}m`;
    }

    shortDate(day) {
        const [y, m, d] = day.split('-').map(Number);
        return new Intl.DateTimeFormat(this.locale, { day: 'numeric', month: 'short', timeZone: 'UTC' }).format(new Date(Date.UTC(y, m - 1, d)));
    }

    sep() {
        return this.isFrench() ? ' : ' : ': ';
    }

    isFrench() {
        return !String(this.locale).startsWith('en');
    }

    fill(template, params) {
        return Object.entries(params).reduce((text, [key, value]) => text.split(`%${key}%`).join(String(value)), template ?? '');
    }
}
