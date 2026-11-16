import { Controller } from '@hotwired/stimulus';

/*
 * Page publique d'un plan de course partagé (race/public.html.twig, /p/{token}) : le jour de la course, met en avant
 * le prochain passage PRÉVU d'après l'horloge de l'appareil (ce n'est pas un suivi en direct).
 *
 * Chaque point de la frise (cible point) porte data-at = heure locale "flottante" du passage prévu
 * ("YYYY-MM-DDTHH:MM:SS", calculée par le serveur à partir de la date de la course), data-name et data-time
 * (heure affichée) ; data-kind = start | checkpoint | finish. Sans data-at (course sans date, sans heure de départ
 * ou sans horaires), rien n'est mis en avant.
 * "Jour de la course" = de minuit le jour du premier passage à minuit après le dernier. Un passage reste "prochain"
 * pendant sa minute. Points passés : classe is-past ; prochain : classe is-next + aria-current="step".
 * Bannière (cibles banner et bannerText) : textes de la valeur labels { next, start, done } (%name%, %time%),
 * insérés via textContent. Rafraîchi toutes les 30 s.
 */
const parseAt = (value) => {
    const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})$/.exec(value || '');
    return m ? new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6]) : null;
};

export default class extends Controller {
    static targets = ['point', 'banner', 'bannerText'];

    static values = { labels: Object };

    connect() {
        this.refresh();
        this.timer = setInterval(() => this.refresh(), 30000);
    }

    disconnect() {
        clearInterval(this.timer);
    }

    refresh() {
        this.pointTargets.forEach((el) => {
            el.classList.remove('is-next', 'is-past');
            el.removeAttribute('aria-current');
        });
        const timed = this.pointTargets
            .map((el) => ({ el, at: parseAt(el.dataset.at) }))
            .filter((p) => p.at !== null);
        const now = new Date();
        if (!timed.length || !this.isRaceDay(timed, now)) {
            this.bannerTarget.hidden = true;
            return;
        }

        const next = timed.find((p) => p.at.getTime() + 60000 > now.getTime()) || null;
        timed.forEach((p) => {
            if (p !== next && p.at.getTime() <= now.getTime()) {
                p.el.classList.add('is-past');
            }
        });

        const labels = this.labelsValue;
        let text;
        if (next) {
            next.el.classList.add('is-next');
            next.el.setAttribute('aria-current', 'step');
            const template = next.el.dataset.kind === 'start' ? labels.start : labels.next;
            text = this.fill(template, next.el.dataset.name, next.el.dataset.time);
        } else {
            const last = timed[timed.length - 1];
            text = this.fill(labels.done, last.el.dataset.name, last.el.dataset.time);
        }
        this.bannerTextTarget.textContent = text;
        this.bannerTarget.hidden = false;
    }

    isRaceDay(timed, now) {
        const first = timed[0].at;
        const last = timed[timed.length - 1].at;
        const from = new Date(first.getFullYear(), first.getMonth(), first.getDate());
        const to = new Date(last.getFullYear(), last.getMonth(), last.getDate() + 1);
        return now >= from && now < to;
    }

    fill(template, name, time) {
        return String(template || '').replace('%name%', name || '').replace('%time%', time || '');
    }
}
