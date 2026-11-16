import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

/**
 * Calendrier général (menu "Calendrier") : activités réalisées + courses prévues (optionnel).
 *
 * Usage :
 *   <div data-controller="training-calendar"
 *        data-training-calendar-events-url-value="/calendar/events"
 *        data-training-calendar-show-races-value="false"
 *        data-training-calendar-initial-date-value="2026-11-15"
 *        data-training-calendar-locale-value="fr"
 *        data-training-calendar-labels-value='{"sessions": "...", ...}'>
 *     <div data-training-calendar-target="calendar"></div>
 *     <input type="checkbox" data-training-calendar-target="toggle" data-action="training-calendar#toggleRaces">
 *     <div data-training-calendar-target="summary"></div>
 *   </div>
 *
 * Clic sur une activité ou une course : vue rapide en modale (target quickView).
 *   - activité : stats + mini-carte si tracé GPS, bouton vers la fiche ;
 *   - course : statut, type, compte à rebours, distance / D+ / lieu, notes, site, boutons plan de course / mes courses.
 * Les événements gardent leur url (clic molette, Ctrl+clic, ouverture dans un onglet).
 * Les événements sont chargés par période (GET eventsUrl?start=&end=&races=0|1).
 * FullCalendar (+ preact, ~450 Ko) est importé dynamiquement : il n'est téléchargé que sur cette page.
 * Tout le contenu issu des données (noms d'activités / de courses) est inséré via textContent.
 */
export default class extends Controller {
    static targets = ['calendar', 'toggle', 'summary', 'summaryTitle', 'summaryHeadline',
        'quickView', 'qvIcon', 'qvTitle', 'qvSub', 'qvStats', 'qvMapSlot', 'qvLink', 'qvLinkLabel',
        'qvLinkSecondary', 'qvBadges', 'qvNotes', 'qvWebsite'];
    static values = {
        eventsUrl: String,
        showRaces: Boolean,
        initialDate: String,
        locale: { type: String, default: 'fr' },
        labels: Object,
    };

    async connect() {
        this.restorePanels();

        const [{ Calendar }, { default: dayGridPlugin }, { default: frLocale }] = await Promise.all([
            import('@fullcalendar/core'),
            import('@fullcalendar/daygrid'),
            import('@fullcalendar/core/locales/fr'),
        ]);
        if (!this.element.isConnected) return;

        if (this.hasToggleTarget) this.toggleTarget.checked = this.showRacesValue;

        this.calendar = new Calendar(this.calendarTarget, {
            plugins: [dayGridPlugin],
            locales: [frLocale],
            locale: this.localeValue,
            initialView: 'dayGridMonth',
            initialDate: this.initialDateValue || undefined,
            headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,dayGridWeek,dayGridYear' },
            height: 'auto',
            fixedWeekCount: false,
            dayMaxEvents: 3,
            eventDisplay: 'block',
            displayEventEnd: false,
            eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
            events: (info, success, failure) => this.fetchEvents(info, success, failure),
            eventContent: (arg) => ({ domNodes: [this.renderEvent(arg)] }),
            eventDidMount: (info) => { info.el.title = this.tooltip(info.event); },
            eventClick: (info) => {
                if (!this.hasQuickViewTarget) return;
                // Ctrl/Cmd/Maj + clic : comportement natif du lien (nouvel onglet)
                const e = info.jsEvent;
                if (e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1) return;
                e.preventDefault();
                this.openQuickView(info.event, info.el);
            },
            datesSet: () => this.syncUrl(),
            eventsSet: () => this.renderSummary(),
        });
        this.calendar.render();
    }

    // ── Blocs repliables (Bilan, Prochaines courses) : état mémorisé dans le navigateur ──
    // Commodité par visiteur uniquement : si le stockage est indisponible, les blocs restent repliés.
    static PANELS_KEY = 'sp.calendar.panels';

    readPanels() {
        try { return JSON.parse(window.localStorage.getItem(this.constructor.PANELS_KEY) || '{}') || {}; } catch { return {}; }
    }

    restorePanels() {
        const state = this.readPanels();
        this.element.querySelectorAll('[data-cal-panel]').forEach((btn) => {
            const panel = this.element.querySelector(btn.dataset.bsTarget);
            if (!panel) return;
            if (state[btn.dataset.calPanel]) {
                // Ouvert d'emblée, sans animation
                panel.classList.add('show');
                btn.setAttribute('aria-expanded', 'true');
            }
            const save = (open) => {
                const s = this.readPanels();
                s[btn.dataset.calPanel] = open;
                try { window.localStorage.setItem(this.constructor.PANELS_KEY, JSON.stringify(s)); } catch { /* stockage indisponible */ }
            };
            panel.addEventListener('shown.bs.collapse', () => save(true));
            panel.addEventListener('hidden.bs.collapse', () => save(false));
        });
    }

    disconnect() {
        this.abort?.abort();
        this.qvModal?.dispose();
        this.qvModal = null;
        this.calendar?.destroy();
        this.calendar = null;
    }

    // ── Vue rapide d'une activité ───────────────────────────────────────────
    openQuickView(event, trigger) {
        const p = event.extendedProps;
        const L = this.labelsValue;
        const isRace = p.kind === 'race';
        this.qvTrigger = trigger;

        this.qvIconTarget.className = `bi ${p.icon || 'bi-lightning-fill'}`;
        this.qvIconTarget.parentElement.style.background = isRace ? p.color : event.backgroundColor;
        this.qvTitleTarget.textContent = event.title;
        const when = new Intl.DateTimeFormat(this.localeValue, isRace
            ? { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }
            : { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' },
        ).format(event.start);
        this.qvSubTarget.textContent = `${isRace ? p.intentLabel : p.sportLabel} · ${when}`;

        // Badges (course) : compte à rebours, type, faite
        const badges = isRace ? [
            p.countdown && ['sp-qv-badge--countdown', 'bi-hourglass-split', p.countdown],
            p.typeLabel && ['', p.typeIcon, p.typeLabel],
            p.isDone && ['sp-qv-badge--done', 'bi-check-circle-fill', L.done],
        ].filter(Boolean) : [];
        this.qvBadgesTarget.replaceChildren(...badges.map(([cls, icon, text]) => {
            const b = document.createElement('span');
            b.className = `sp-qv-badge ${cls}`;
            const i = document.createElement('i');
            i.className = `bi ${icon}`;
            b.append(i, document.createTextNode(text));
            return b;
        }));
        this.qvBadgesTarget.hidden = badges.length === 0;

        // Tuiles : seulement les valeurs connues ; vélo -> vitesse, sinon allure
        const tiles = isRace ? [
            [L.distance, p.distanceKm ? `${p.distanceKm} km` : null],
            [L.elevation, p.elevationGain ? `${p.elevationGain} m` : null],
            [L.location, p.location || null, true],
        ] : [
            [L.distance, p.distanceKm ? `${p.distanceKm} km` : null],
            [L.duration, p.seconds ? this.hms(p.seconds) : null],
            p.sport === 'ride'
                ? [L.speed, p.speedKmh ? `${p.speedKmh} km/h` : null]
                : [L.pace, p.pace ? `${p.pace} /km` : null],
            [L.elevation, p.elevationGain ? `${p.elevationGain} m` : null],
            [L.hrAvg, p.avgHr ? `${p.avgHr} bpm` : null],
            [L.hrMax, p.maxHr ? `${p.maxHr} bpm` : null],
        ];
        this.qvStatsTarget.replaceChildren(...tiles.filter(([, v]) => v).map(([label, value, isText]) => {
            const tile = document.createElement('div');
            tile.className = 'sp-qv-tile';
            const v = document.createElement('div'); v.className = `sp-qv-val${isText ? ' sp-qv-val--text' : ''}`; v.textContent = value;
            const l = document.createElement('div'); l.className = 'sp-qv-lbl'; l.textContent = label;
            tile.append(v, l);
            return tile;
        }));
        this.qvStatsTarget.hidden = this.qvStatsTarget.childElementCount === 0;

        // Notes + site officiel (course)
        this.qvNotesTarget.textContent = isRace && p.notes ? p.notes : '';
        this.qvNotesTarget.hidden = !(isRace && p.notes);
        const site = isRace && /^https?:\/\//i.test(p.website || '') ? p.website : null;
        if (site) this.qvWebsiteTarget.setAttribute('href', site); else this.qvWebsiteTarget.removeAttribute('href');
        this.qvWebsiteTarget.hidden = !site;

        // Boutons : activité -> fiche ; course -> plan de course (+ mes courses)
        this.qvLinkTarget.href = isRace ? p.planUrl : event.url;
        this.qvLinkLabelTarget.textContent = isRace ? L.plan : L.details;
        this.qvLinkSecondaryTarget.hidden = !isRace;
        if (isRace) this.qvLinkSecondaryTarget.href = p.listUrl;

        this.qvMapSlotTarget.replaceChildren();
        this.qvMapSlotTarget.hidden = isRace || !p.polyline;

        if (!this.qvModal) {
            this.qvModal = Modal.getOrCreateInstance(this.quickViewTarget);
            // La carte n'est créée qu'une fois la modale visible (Leaflet a besoin de dimensions)
            this.quickViewTarget.addEventListener('shown.bs.modal', () => this.mountQuickViewMap());
            this.quickViewTarget.addEventListener('hidden.bs.modal', () => {
                this.qvMapSlotTarget.replaceChildren(); // -> map_controller#disconnect -> map.remove()
                this.qvTrigger?.focus();
            });
        }
        this.qvPolyline = isRace ? null : p.polyline;
        this.qvModal.show();
    }

    mountQuickViewMap() {
        if (!this.qvPolyline) return;
        const map = document.createElement('div');
        map.className = 'sp-qv-map';
        map.setAttribute('data-controller', 'map');
        map.setAttribute('data-map-polyline-value', this.qvPolyline);
        this.qvMapSlotTarget.replaceChildren(map);
    }

    hms(seconds) {
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = seconds % 60;
        return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}` : `${m}:${String(s).padStart(2, '0')}`;
    }

    toggleRaces() {
        this.showRacesValue = this.toggleTarget.checked;
        this.syncUrl();
        this.calendar?.refetchEvents();
    }

    async fetchEvents(info, success, failure) {
        this.abort?.abort();
        this.abort = new AbortController();
        const url = new URL(this.eventsUrlValue, window.location.origin);
        url.searchParams.set('start', info.startStr.slice(0, 10));
        url.searchParams.set('end', info.endStr.slice(0, 10));
        url.searchParams.set('races', this.showRacesValue ? '1' : '0');
        try {
            const res = await fetch(url, { headers: { Accept: 'application/json' }, signal: this.abort.signal });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            success(await res.json());
        } catch (e) {
            if (e.name !== 'AbortError') failure(e);
        }
    }

    // Icône + (heure) + titre + distance, construits en DOM (pas d'innerHTML)
    renderEvent(arg) {
        const p = arg.event.extendedProps;
        const wrap = document.createElement('div');
        wrap.className = 'sp-fc-ev';

        const icon = document.createElement('i');
        icon.className = `bi ${p.icon || 'bi-circle-fill'}`;
        wrap.appendChild(icon);

        if (p.kind === 'activity' && arg.timeText) {
            const time = document.createElement('span');
            time.className = 'sp-fc-ev-time';
            time.textContent = arg.timeText;
            wrap.appendChild(time);
        }

        const title = document.createElement('span');
        title.className = 'sp-fc-ev-title';
        title.textContent = arg.event.title;
        wrap.appendChild(title);

        if (p.distanceKm) {
            const km = document.createElement('span');
            km.className = 'sp-fc-ev-km';
            km.textContent = `${Math.round(p.distanceKm)} km`;
            wrap.appendChild(km);
        }
        return wrap;
    }

    tooltip(event) {
        const p = event.extendedProps;
        const parts = [event.title];
        if (p.kind === 'activity') {
            parts.push(p.sportLabel);
            if (p.distanceKm) parts.push(`${p.distanceKm} km`);
            if (p.seconds) parts.push(this.duration(p.seconds));
        } else {
            parts.push(p.intentLabel);
            if (p.typeLabel) parts.push(p.typeLabel);
            if (p.distanceKm) parts.push(`${p.distanceKm} km`);
            if (p.elevationGain) parts.push(`${p.elevationGain} m D+`);
        }
        return parts.filter(Boolean).join(' · ');
    }

    // Bilan de la période affichée (mois / semaine / année), activités uniquement
    renderSummary() {
        if (!this.calendar || !this.hasSummaryTarget) return;
        const view = this.calendar.view;
        const L = this.labelsValue;
        const bySport = new Map();
        let count = 0, km = 0, seconds = 0;

        for (const ev of this.calendar.getEvents()) {
            const p = ev.extendedProps;
            if (p.kind !== 'activity' || ev.start < view.currentStart || ev.start >= view.currentEnd) continue;
            count++;
            km += p.distanceKm || 0;
            seconds += p.seconds || 0;
            const s = bySport.get(p.sport) || { label: p.sportLabel, icon: p.icon, color: ev.backgroundColor, count: 0, km: 0 };
            s.count++;
            s.km += p.distanceKm || 0;
            bySport.set(p.sport, s);
        }

        if (this.hasSummaryTitleTarget) this.summaryTitleTarget.textContent = view.title;
        // Résumé visible même quand le bloc Bilan est replié
        if (this.hasSummaryHeadlineTarget) {
            this.summaryHeadlineTarget.textContent = count === 0
                ? L.empty
                : `${count} ${L.sessions} · ${Math.round(km)} km · ${this.duration(seconds)}`;
        }

        const root = this.summaryTarget;
        root.replaceChildren();

        const totals = document.createElement('div');
        totals.className = 'sp-cal-totals';
        for (const [value, label] of [[String(count), L.sessions], [`${Math.round(km)}`, 'km'], [this.duration(seconds), L.duration]]) {
            const cell = document.createElement('div');
            const v = document.createElement('div'); v.className = 'sp-cal-total-val'; v.textContent = value;
            const l = document.createElement('div'); l.className = 'sp-cal-total-lbl'; l.textContent = label;
            cell.append(v, l);
            totals.appendChild(cell);
        }
        root.appendChild(totals);

        if (count === 0) {
            const empty = document.createElement('p');
            empty.className = 'sp-cal-summary-empty';
            empty.textContent = L.empty;
            root.appendChild(empty);
            return;
        }

        const list = document.createElement('ul');
        list.className = 'sp-cal-sport-list';
        for (const s of [...bySport.values()].sort((a, b) => b.count - a.count)) {
            const li = document.createElement('li');
            const icon = document.createElement('i');
            icon.className = `bi ${s.icon}`;
            icon.style.color = s.color;
            const name = document.createElement('span');
            name.className = 'sp-cal-sport-name';
            name.textContent = s.label;
            const meta = document.createElement('span');
            meta.className = 'sp-cal-sport-meta';
            meta.textContent = s.km >= 0.5 ? `${s.count} · ${Math.round(s.km)} km` : String(s.count);
            li.append(icon, name, meta);
            list.appendChild(li);
        }
        root.appendChild(list);
    }

    duration(seconds) {
        const h = Math.floor(seconds / 3600);
        const m = Math.round((seconds % 3600) / 60);
        return h > 0 ? `${h}h${String(m).padStart(2, '0')}` : `${m} min`;
    }

    // Garde la période affichée et l'option "courses" dans l'URL (rechargement / partage)
    syncUrl() {
        if (!this.calendar) return;
        const url = new URL(window.location.href);
        const d = this.calendar.getDate();
        const iso = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        url.searchParams.set('date', iso);
        if (this.showRacesValue) url.searchParams.set('races', '1'); else url.searchParams.delete('races');
        window.history.replaceState(null, '', url);
    }
}
