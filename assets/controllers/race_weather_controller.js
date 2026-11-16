import { Controller } from '@hotwired/stimulus';
import { WMO_ICONS, WMO_LABELS } from './weather_constants.js';

/*
 * Carte « Météo du jour J » (page d'une course, partial race/_race_weather.html.twig).
 * Charge app_race_weather après l'affichage de la page (squelette pendant le chargement),
 * puis affiche l'état renvoyé :
 *  - forecast : prévision du jour (J à J+15) + créneaux de 3 h ;
 *  - observed : météo observée le jour de la course (course passée) ;
 *  - climate  : tendance des 10 dernières années (jour de la course ±3 jours), pas une prévision ;
 *  - no_date / no_location / unavailable : gabarits <template> du partial (textes traduits par Twig).
 *
 * Valeurs : url (endpoint JSON), locale ('fr' | 'en'), labels (libellés traduits, paramètres %nom%).
 * Sécurité : les données (API, lieu saisi) ne passent que par textContent / setAttribute.
 */

const FETCH_TIMEOUT_MS = 20000;
const COMPASS = ['n', 'ne', 'e', 'se', 's', 'sw', 'w', 'nw'];
const RISK_ICONS = { low: 'bi-droplet', moderate: 'bi-droplet-half', high: 'bi-droplet-fill' };
const BADGES = { forecast: 'badge_forecast', observed: 'badge_observed', climate: 'badge_climate' };

/** Crée un élément : attributs (class, text, aria-*, title…) puis enfants (nœuds ou texte brut). */
function h(tag, attrs = {}, ...children) {
    const el = document.createElement(tag);
    for (const [name, value] of Object.entries(attrs)) {
        if (value === null || value === undefined || value === false) continue;
        if (name === 'class') el.className = value;
        else if (name === 'text') el.textContent = value;
        else el.setAttribute(name, value === true ? '' : String(value));
    }
    for (const child of children.flat()) {
        if (child === null || child === undefined || child === false || child === '') continue;
        el.append(child); // une chaîne devient un nœud texte, jamais du HTML
    }
    return el;
}

const icon = (cls) => h('i', { class: `bi ${cls}`, 'aria-hidden': 'true' });
const isNum = (v) => typeof v === 'number' && Number.isFinite(v);
/** « 2027-05-19 » -> « 19/05/2027 » (même format que la date de la course sur la page). */
const dmy = (iso) => (typeof iso === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(iso) ? iso.split('-').reverse().join('/') : '');
const dm = (iso) => dmy(iso).slice(0, 5);

export default class extends Controller {
    static targets = ['skeleton', 'content', 'status', 'badge', 'day', 'credit', 'geoCredit',
        'tplNoDate', 'tplNoLocation', 'tplNotFound', 'tplUnavailable'];

    static values = { url: String, locale: { type: String, default: 'fr' }, labels: Object };

    connect() {
        const loc = this.localeValue;
        this.L = this.labelsValue || {};
        this.wmo = WMO_LABELS[loc] ?? WMO_LABELS.fr;
        this.nf = {
            temp: new Intl.NumberFormat(loc, { style: 'unit', unit: 'celsius', maximumFractionDigits: 0 }),
            mm: new Intl.NumberFormat(loc, { style: 'unit', unit: 'millimeter', maximumFractionDigits: 1 }),
            kmh: new Intl.NumberFormat(loc, { style: 'unit', unit: 'kilometer-per-hour', maximumFractionDigits: 0 }),
            pct: new Intl.NumberFormat(loc, { style: 'percent', maximumFractionDigits: 0 }),
            dec: new Intl.NumberFormat(loc, { maximumFractionDigits: 1 }),
            hour: new Intl.DateTimeFormat(loc, { hour: 'numeric' }),
            clock: new Intl.DateTimeFormat(loc, { hour: '2-digit', minute: '2-digit' }),
            day: new Intl.DateTimeFormat(loc, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }),
        };
        this.load(false);
    }

    disconnect() {
        const pending = this.request;
        this.request = null;
        pending?.abort();
    }

    /** Bouton « Réessayer » (état indisponible) : force un nouvel appel côté serveur. */
    retry(event) {
        event?.preventDefault();
        this.load(true);
    }

    async load(retry) {
        this.request?.abort();
        const request = new AbortController();
        this.request = request;
        const timer = setTimeout(() => request.abort(), FETCH_TIMEOUT_MS);
        this.setLoading(true);

        let data = null;
        try {
            const url = new URL(this.urlValue, window.location.href);
            if (retry) url.searchParams.set('retry', '1');
            const res = await fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: request.signal,
            });
            if (res.ok) data = await res.json();
        } catch {
            // Réseau, délai dépassé ou JSON invalide : état « indisponible » (sauf requête remplacée)
        } finally {
            clearTimeout(timer);
        }
        if (this.request !== request) return; // remplacée par une autre requête, ou controller déconnecté
        this.request = null;
        this.render(data !== null && typeof data === 'object' ? data : { state: 'unavailable' });
    }

    setLoading(loading) {
        this.element.setAttribute('aria-busy', loading ? 'true' : 'false');
        this.skeletonTarget.hidden = !loading;
        this.contentTarget.hidden = loading;
        if (loading) {
            this.badgeTarget.hidden = true;
            this.dayTarget.hidden = true;
            this.creditTarget.hidden = true;
        }
        this.statusTarget.textContent = loading ? (this.L.loading ?? '') : '';
    }

    render(data) {
        let nodes = null;
        if (data.state === 'forecast' && data.forecast?.daily) nodes = this.renderDay(data, data.forecast, false);
        else if (data.state === 'observed' && data.observed?.daily) nodes = this.renderDay(data, data.observed, true);
        else if (data.state === 'climate' && data.climate) nodes = this.renderClimate(data, data.climate);

        const body = this.contentTarget;
        if (nodes) {
            body.replaceChildren(...nodes.filter(Boolean));
            this.badgeTarget.className = `rs-wx-badge rs-wx-badge--${data.state}`;
            this.badgeTarget.textContent = this.L[BADGES[data.state]] ?? '';
            this.badgeTarget.hidden = false;
            this.showDay(data.date);
            this.creditTarget.hidden = false;
            this.geoCreditTarget.hidden = data.place?.source !== 'location';
        } else {
            const tpl = {
                no_date: this.tplNoDateTarget,
                no_location: data.reason === 'not_found' ? this.tplNotFoundTarget : this.tplNoLocationTarget,
            }[data.state] ?? this.tplUnavailableTarget;
            body.replaceChildren(tpl.content.cloneNode(true));
        }
        this.setLoading(false);
    }

    // ── Prévision / météo observée ────────────────────────────────────────

    renderDay(data, payload, observed) {
        const d = payload.daily;
        const L = this.L;
        const code = d.weatherCode;
        const desc = this.wmo[code] ?? '';

        const now = h('div', { class: 'rs-wx-now' },
            h('i', { class: `bi ${WMO_ICONS[code] ?? 'bi-cloud'} rs-wx-icon`, 'aria-hidden': 'true' }),
            h('div', { class: 'rs-wx-now-main' },
                h('div', { class: 'rs-wx-temps' },
                    this.tempBlock(d.tempMax, L.max, 'rs-wx-temp--max'),
                    this.tempBlock(d.tempMin, L.min, 'rs-wx-temp--min')),
                desc ? h('p', { class: 'rs-wx-desc', text: desc }) : null,
                (isNum(d.apparentMax) || isNum(d.apparentMin))
                    ? h('p', { class: 'rs-wx-feel', text: this.t('feels_like', { max: this.temp(d.apparentMax), min: this.temp(d.apparentMin) }) })
                    : null));

        const rain = observed ? this.observedRain(d) : this.riskLine(d.rainRisk, [
            isNum(d.precipitationProbability) ? this.nf.pct.format(d.precipitationProbability / 100) : null,
            isNum(d.precipitation) ? this.nf.mm.format(d.precipitation) : null,
        ]);

        const facts = h('dl', { class: 'rs-wx-facts' });
        if (isNum(d.windSpeedMax)) {
            facts.append(this.fact('bi-wind', L.wind,
                this.windDirection(d.windDirection),
                this.nf.kmh.format(d.windSpeedMax),
                isNum(d.windGustsMax) ? h('span', { class: 'rs-wx-muted', text: this.t('gusts', { speed: this.nf.kmh.format(d.windGustsMax) }) }) : null));
        }
        if (isNum(d.uvIndexMax)) facts.append(this.fact('bi-brightness-high', L.uv, this.nf.dec.format(d.uvIndexMax)));
        if (d.sunrise && d.sunset) {
            facts.append(this.fact('bi-sunrise', L.sun,
                h('span', { class: 'rs-wx-dir', title: L.sunrise }, icon('bi-sunrise'), h('span', { class: 'visually-hidden', text: L.sunrise }), d.sunrise),
                h('span', { class: 'rs-wx-dir', title: L.sunset }, icon('bi-sunset'), h('span', { class: 'visually-hidden', text: L.sunset }), d.sunset)));
        }

        return [
            now,
            rain,
            facts.childElementCount ? facts : null,
            Array.isArray(payload.slots) && payload.slots.length ? this.hours(payload.slots, observed) : null,
            this.meta(data, !observed),
        ];
    }

    tempBlock(value, label, cls) {
        return h('span', { class: `rs-wx-temp ${cls}` },
            h('span', { class: 'rs-wx-temp-val', text: this.temp(value) }),
            h('span', { class: 'rs-wx-temp-lbl', text: label ?? '' }));
    }

    /**
     * Niveau de risque en texte + icône (la couleur n'est qu'un renfort).
     * stacked : un détail par ligne (phrases longues de la tendance historique).
     */
    riskLine(level, details, stacked = false) {
        if (!RISK_ICONS[level]) return null;
        const parts = details.filter(Boolean);
        const lines = stacked ? parts : (parts.length ? [parts.join(' · ')] : []);
        return h('p', { class: `rs-wx-risk rs-wx-risk--${level}${stacked ? ' rs-wx-risk--stacked' : ''}` },
            icon(RISK_ICONS[level]),
            h('span', { class: 'rs-wx-risk-body' },
                h('strong', { text: this.L[`risk_${level}`] ?? '' }),
                lines.map((text) => h('span', { class: 'rs-wx-risk-detail', text }))));
    }

    observedRain(d) {
        if (!isNum(d.precipitation)) return null;
        const wet = d.rainyDay === true;
        return h('p', { class: `rs-wx-risk rs-wx-risk--${wet ? 'high' : 'dry'}` },
            icon(wet ? 'bi-cloud-rain' : 'bi-cloud-sun'),
            h('span', { class: 'rs-wx-risk-body' },
                h('strong', { text: wet ? this.L.observed_wet : this.L.observed_dry }),
                h('span', { class: 'rs-wx-risk-detail', text: this.nf.mm.format(d.precipitation) })));
    }

    /** Flèche orientée vers où va le vent (direction d'origine + 180°), avec un texte accessible. */
    windDirection(deg) {
        if (!isNum(deg)) return null;
        const norm = ((deg % 360) + 360) % 360;
        const sector = COMPASS[Math.round(norm / 45) % 8];
        const label = this.L.wind_from?.[sector] ?? '';
        const arrow = icon('bi-arrow-up rs-wx-arrow');
        arrow.style.transform = `rotate(${Math.round((norm + 180) % 360)}deg)`;
        return h('span', { class: 'rs-wx-dir', title: label },
            arrow,
            h('span', { class: 'visually-hidden', text: label }),
            h('span', { 'aria-hidden': 'true', text: this.L.compass?.[sector] ?? '' }));
    }

    fact(iconCls, label, ...values) {
        return h('div', {},
            h('dt', {}, icon(iconCls), label ?? ''),
            h('dd', {}, ...values));
    }

    hours(slots, observed) {
        const L = this.L;
        const list = h('ol', { class: 'rs-wx-hours', 'aria-label': L.hours_title, tabindex: '0' });
        for (const s of slots) {
            const hour = Number.parseInt(String(s.time ?? '').slice(0, 2), 10);
            if (!Number.isInteger(hour)) continue;
            const desc = this.wmo[s.weatherCode] ?? '';
            let rain = null;
            if (!observed && isNum(s.precipitationProbability)) {
                rain = [L.rain_probability, this.nf.pct.format(s.precipitationProbability / 100)];
            } else if (isNum(s.precipitation)) {
                rain = [L.precipitation, this.nf.mm.format(s.precipitation)];
            }
            list.append(h('li', { class: 'rs-wx-hour' },
                h('span', { class: 'rs-wx-hour-time', text: this.nf.hour.format(new Date(2000, 0, 1, hour)) }),
                h('i', { class: `bi ${WMO_ICONS[s.weatherCode] ?? 'bi-cloud'} rs-wx-hour-icon`, 'aria-hidden': 'true', title: desc || null }),
                desc ? h('span', { class: 'visually-hidden', text: desc }) : null,
                h('span', { class: 'rs-wx-hour-temp', text: this.temp(s.temperature) }),
                rain ? h('span', { class: 'rs-wx-hour-rain', title: rain[0] },
                    icon('bi-droplet'), h('span', { class: 'visually-hidden', text: rain[0] }), rain[1]) : null,
                isNum(s.windSpeed) ? h('span', { class: 'rs-wx-hour-wind', title: L.wind },
                    icon('bi-wind'), h('span', { class: 'visually-hidden', text: L.wind }), this.nf.kmh.format(s.windSpeed)) : null));
        }
        if (!list.childElementCount) return null;
        return h('div', { class: 'rs-wx-hours-wrap' }, h('p', { class: 'rs-wx-sub', 'aria-hidden': 'true', text: L.hours_title }), list);
    }

    // ── Tendance historique ───────────────────────────────────────────────

    renderClimate(data, c) {
        const L = this.L;
        const share = isNum(c.rainyShare) ? this.nf.pct.format(c.rainyShare) : null;

        const stats = h('div', { class: 'rs-wx-stats' },
            this.stat(L.climate_tmax, c.tempMax),
            this.stat(L.climate_tmin, c.tempMin));

        const facts = h('dl', { class: 'rs-wx-facts' });
        if (isNum(c.windMaxAvg)) facts.append(this.fact('bi-wind', L.climate_wind, this.nf.kmh.format(c.windMaxAvg)));
        if (isNum(c.dominantCode)) {
            facts.append(this.fact(WMO_ICONS[c.dominantCode] ?? 'bi-cloud', L.climate_dominant, this.wmo[c.dominantCode] ?? ''));
        }
        if (isNum(c.snowDays) && c.snowDays > 0) {
            facts.append(this.fact('bi-snow', L.climate_snow, this.t('climate_snow_value', { n: c.snowDays, days: c.days })));
        }

        return [
            h('p', { class: 'rs-wx-note' }, icon('bi-info-circle'), h('span', {
                text: this.t('climate_note', { from: dm(c.windowFrom), to: dm(c.windowTo), years: c.years, fromYear: c.fromYear, toYear: c.toYear }),
            })),
            stats,
            this.riskLine(c.rainRisk, [
                share ? this.t('climate_rain_days', { share }) : null,
                isNum(c.rainyYears) ? this.t('climate_rain_years', { n: c.rainyYears, total: c.years }) : null,
            ], true),
            facts.childElementCount ? facts : null,
            c.availableFrom ? h('p', { class: 'rs-wx-soon' }, icon('bi-calendar-check'),
                h('span', { text: this.t('climate_available', { date: dmy(c.availableFrom) }) })) : null,
            this.meta(data, false),
        ];
    }

    stat(label, summary) {
        if (!summary || !isNum(summary.avg)) return null;
        return h('div', { class: 'rs-wx-stat' },
            h('span', { class: 'rs-wx-stat-lbl', text: label ?? '' }),
            h('span', { class: 'rs-wx-stat-val', text: this.temp(summary.avg) }),
            h('span', { class: 'rs-wx-stat-range', text: this.t('climate_range', { min: this.temp(summary.min), max: this.temp(summary.max) }) }));
    }

    // ── Commun ────────────────────────────────────────────────────────────

    /** Lieu utilisé (et sa source), plus l'heure de mise à jour pour une prévision. */
    meta(data, showUpdated) {
        const L = this.L;
        const p = data.place ?? {};
        let text;
        if (p.source === 'gpx') {
            text = p.name && p.name !== L.gpx_start ? `${p.name} · ${L.source_gpx}` : `${L.gpx_start} (GPX)`;
        } else {
            text = `${[p.name, p.region].filter(Boolean).join(', ')} · ${L.source_location}`;
        }
        const meta = h('div', { class: 'rs-wx-meta' }, h('p', {}, icon('bi-geo-alt'), h('span', { text })));
        if (showUpdated && data.fetchedAt) {
            const at = new Date(data.fetchedAt);
            if (!Number.isNaN(at.getTime())) {
                meta.append(h('p', {}, icon('bi-clock'), h('span', { text: this.t('updated_at', { time: this.nf.clock.format(at) }) })));
            }
        }
        return meta;
    }

    showDay(iso) {
        const m = typeof iso === 'string' ? /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso) : null;
        if (!m) return;
        const label = this.nf.day.format(new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])));
        this.dayTarget.textContent = label.charAt(0).toUpperCase() + label.slice(1);
        this.dayTarget.hidden = false;
    }

    temp(value) {
        if (!isNum(value)) return '-';
        const rounded = Math.round(value);
        return this.nf.temp.format(rounded === 0 ? 0 : rounded); // évite « -0 °C »
    }

    /** Libellé traduit avec ses paramètres %nom%. */
    t(key, params = {}) {
        return String(this.L[key] ?? '').replace(/%(\w+)%/g, (all, name) => (name in params ? String(params[name]) : all));
    }
}
