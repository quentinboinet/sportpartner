import { Controller } from '@hotwired/stimulus';

/**
 * Calendrier des courses (FullCalendar 6, chargé à la demande).
 *
 * Usage :
 *   <div data-controller="race-calendar"
 *        data-race-calendar-events-value="{{ events|json_encode }}"
 *        data-race-calendar-initial-date-value="2026-01-01"
 *        data-race-calendar-locale-value="fr"></div>
 *
 * Un clic sur une course émet l'événement DOM `race-calendar:select`
 * (detail = données de la course) - la page ouvre alors la modale d'édition.
 *
 * FullCalendar (+ preact) pèse ~450 Ko : il est importé dynamiquement pour
 * n'être téléchargé que sur les pages qui affichent un calendrier.
 */
export default class extends Controller {
    static values = {
        events: Array,
        initialDate: String,
        locale: { type: String, default: 'fr' },
    };

    async connect() {
        const [{ Calendar }, { default: dayGridPlugin }, { default: frLocale }] = await Promise.all([
            import('@fullcalendar/core'),
            import('@fullcalendar/daygrid'),
            import('@fullcalendar/core/locales/fr'),
        ]);

        // L'élément a pu être retiré du DOM pendant le chargement
        if (!this.element.isConnected) return;

        this.calendar = new Calendar(this.element, {
            plugins: [dayGridPlugin],
            locales: [frLocale],
            locale: this.localeValue,
            initialView: 'dayGridMonth',
            initialDate: this.initialDateValue || undefined,
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,dayGridYear',
            },
            height: 'auto',
            fixedWeekCount: false,
            dayMaxEvents: 3,
            events: this.eventsValue,
            eventDisplay: 'block',
            eventClick: (info) => {
                info.jsEvent.preventDefault();
                this.dispatch('select', { detail: info.event.extendedProps });
            },
            eventDidMount: (info) => {
                const p = info.event.extendedProps;
                const meta = [
                    p.location,
                    p.distanceKm ? `${p.distanceKm} km` : null,
                    p.elevationGain ? `${p.elevationGain} m D+` : null,
                ].filter(Boolean).join(' · ');
                info.el.title = meta ? `${info.event.title} - ${meta}` : info.event.title;
            },
        });
        this.calendar.render();
    }

    disconnect() {
        this.calendar?.destroy();
        this.calendar = null;
    }
}
