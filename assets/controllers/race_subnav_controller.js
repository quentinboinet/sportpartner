import { Controller } from '@hotwired/stimulus';

/*
 * Plan de course : sous-navigation (Parcours, Repères, Temps de passage, Nutrition, À emporter), collante sous la barre
 * du haut sur grand écran (templates/race/plan.html.twig, .rp-subnav).
 *
 *  - scroll-spy par IntersectionObserver : une « ligne de lecture » d'un pixel juste sous la barre et la
 *    sous-navigation (sous le haut de la fenêtre quand elles ne sont pas collantes) ; la section qui la traverse
 *    devient active (aria-current="true" sur son lien). Entre deux sections, la précédente reste active. En bas de page
 *    (cible end visible), la dernière section, trop courte pour atteindre la ligne, devient active. Observateurs
 *    recréés au redimensionnement (hauteur de la fenêtre, position collante ou non) ;
 *  - saut vers une section (liens, bouton « Temps de passage » de l'en-tête, lien de la nutrition sans scénario) :
 *    défilement doux, immédiat avec prefers-reduced-motion, sans changer l'URL ; focus ensuite sur le titre (h2) de la
 *    section (tabindex="-1") pour le clavier et les lecteurs d'écran ; le lien actif est fixé pendant le défilement ;
 *  - le lien actif reste visible dans la barre quand elle défile horizontalement (mobile) ;
 *  - sans JS : liens d'ancre ordinaires (scroll-margin-top des sections dans race_plan.css).
 */

const LOCK_MS = 1500;
const READING_MARGIN = 48; // px sous la sous-navigation (scroll-margin-top des sections : 18 px de moins, voir race_plan.css)

export default class extends Controller {
    static targets = ['nav', 'link', 'end'];

    connect() {
        this.entries = this.linkTargets
            .map(link => ({ link, section: document.getElementById((link.getAttribute('href') || '').slice(1)) }))
            .filter(entry => entry.section);
        this.current = null;
        this.atEnd = false;
        this.lockUntil = 0;
        this.observe();
        this.onResize = () => {
            clearTimeout(this.resizeTimer);
            this.resizeTimer = setTimeout(() => this.observe(), 150);
        };
        window.addEventListener('resize', this.onResize);
    }

    disconnect() {
        this.observer?.disconnect();
        this.endObserver?.disconnect();
        window.removeEventListener('resize', this.onResize);
        if (this.onScrollEnd) window.removeEventListener('scrollend', this.onScrollEnd);
        clearTimeout(this.resizeTimer);
        clearTimeout(this.unlockTimer);
    }

    /**
     * Hauteur de la ligne de lecture : sous la barre du haut + la sous-navigation quand celle-ci est collante, avec une
     * marge plus grande que le scroll-margin-top des sections (une section atteinte par un lien la traverse).
     */
    readingLine() {
        if (!this.hasNavTarget) return READING_MARGIN;
        const style = getComputedStyle(this.navTarget);
        if (style.position !== 'sticky') return READING_MARGIN;
        return Math.round((parseFloat(style.top) || 0) + this.navTarget.offsetHeight + READING_MARGIN);
    }

    observe() {
        if (typeof IntersectionObserver === 'undefined' || this.entries.length === 0) return;
        this.observer?.disconnect();
        this.endObserver?.disconnect();
        const top = this.readingLine();
        const bottom = Math.max(0, window.innerHeight - top - 1);
        this.observer = new IntersectionObserver((records) => {
            records.forEach((record) => { if (record.isIntersecting) this.current = record.target; });
            this.refresh();
        }, { rootMargin: `-${top}px 0px -${bottom}px 0px`, threshold: 0 });
        this.entries.forEach(entry => this.observer.observe(entry.section));
        if (this.hasEndTarget) {
            this.endObserver = new IntersectionObserver((records) => {
                this.atEnd = records.some(record => record.isIntersecting);
                this.refresh();
            });
            this.endObserver.observe(this.endTarget);
        }
    }

    refresh() {
        if (Date.now() < this.lockUntil) return;
        const last = this.entries[this.entries.length - 1];
        const entry = this.atEnd ? last : (this.entries.find(e => e.section === this.current) ?? this.entries[0]);
        if (entry) this.setActive(entry.link);
    }

    setActive(link) {
        this.linkTargets.forEach((other) => {
            if (other === link) other.setAttribute('aria-current', 'true');
            else other.removeAttribute('aria-current');
        });
        // Barre défilante (mobile) : le lien actif reste visible, sans faire défiler la page
        const list = link.closest('ul');
        if (list && list.scrollWidth > list.clientWidth) {
            const left = link.offsetLeft;
            const right = left + link.offsetWidth;
            if (left < list.scrollLeft || right > list.scrollLeft + list.clientWidth) list.scrollLeft = Math.max(0, left - 12);
        }
    }

    /** Lien vers une section du plan : défilement (doux sauf préférence contraire) puis focus sur son titre. */
    jump(event) {
        const href = event.currentTarget.getAttribute('href') || '';
        const section = href.startsWith('#') ? document.getElementById(href.slice(1)) : null;
        if (!section) return;
        event.preventDefault();
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const link = this.linkTargets.find(l => l.getAttribute('href') === href);
        if (link) {
            this.setActive(link);
            // Pendant le défilement doux, les sections traversées ne changent pas le lien actif (fin : scrollend, ou délai)
            this.lockUntil = reduce ? 0 : Date.now() + LOCK_MS;
            clearTimeout(this.unlockTimer);
            window.removeEventListener('scrollend', this.onScrollEnd);
            this.onScrollEnd = () => {
                clearTimeout(this.unlockTimer);
                window.removeEventListener('scrollend', this.onScrollEnd);
                this.lockUntil = 0;
                this.refresh();
            };
            window.addEventListener('scrollend', this.onScrollEnd);
            this.unlockTimer = setTimeout(this.onScrollEnd, reduce ? 50 : LOCK_MS + 50);
        }
        section.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
        const heading = section.querySelector('h2');
        if (heading) {
            if (!heading.hasAttribute('tabindex')) heading.setAttribute('tabindex', '-1');
            heading.focus({ preventScroll: true });
        }
    }
}
