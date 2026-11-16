import { Controller } from '@hotwired/stimulus';

/*
 * Bibliothèque de l'espace Santé (/sante) : filtres en direct (catégorie, zone, niveau, matériel, recherche,
 * exercices masqués). Le serveur rend toutes les cartes et masque déjà celles qui ne correspondent pas aux
 * paramètres de l'URL (la page marche sans JavaScript) ; ce controller applique les MÊMES règles à chaque
 * modification (match() : miroir de HealthLibraryFilter::matches(), fold() : miroir de TextFold::fold()), met à
 * jour le compteur (role="status", annoncé à chaque changement de résultat, pas à chaque frappe identique) et
 * l'URL (history.replaceState) sans recharger la page.
 */

export function fold(text) {
    return String(text).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
        .replace(/œ/g, 'oe').replace(/æ/g, 'ae').replace(/\s+/g, ' ').trim();
}

function fill(text, params) {
    return Object.entries(params).reduce((out, [key, value]) => out.split(key).join(String(value)), text);
}

export default class extends Controller {
    static targets = ['form', 'query', 'card', 'group', 'count', 'empty', 'reset'];
    static values = { labels: Object };

    connect() {
        this.lastCount = null;
        this.apply();
    }

    submit(event) {
        event.preventDefault();
        this.apply();
    }

    reset(event) {
        event.preventDefault();
        const form = this.formTarget;
        this.queryTarget.value = '';
        form.querySelectorAll('select').forEach((select) => { select.value = ''; });
        form.querySelectorAll('input[type="radio"][name="category"]').forEach((radio) => { radio.checked = radio.value === ''; });
        this.apply();
        this.queryTarget.focus();
    }

    apply() {
        const filter = this.state();
        let count = 0;
        this.cardTargets.forEach((card) => {
            const ok = this.match(card, filter);
            card.hidden = !ok;
            if (ok) count++;
        });
        this.groupTargets.forEach((group) => {
            group.hidden = !this.cardTargets.some((card) => !card.hidden && group.contains(card));
        });

        const L = this.labelsValue;
        if (count !== this.lastCount) {
            this.countTarget.textContent = count === 0 ? L.countNone : fill(count === 1 ? L.countOne : L.countMany, { '%count%': count });
            this.lastCount = count;
        }
        this.emptyTarget.hidden = count > 0;
        const active = filter.category !== '' || filter.area !== '' || filter.level !== '' || filter.equipment !== '' || filter.words.length > 0;
        this.resetTarget.hidden = !active;
        this.updateUrl();
    }

    state() {
        const data = new FormData(this.formTarget);
        const get = (name) => String(data.get(name) || '');
        return {
            category: get('category'),
            area: get('area'),
            level: get('level'),
            equipment: get('equipment'),
            words: fold(get('q')).split(' ').filter((w) => w !== ''),
            showHidden: data.get('hidden') !== null,
        };
    }

    /** Miroir de HealthLibraryFilter::matches(). */
    match(card, f) {
        const d = card.dataset;
        if (d.hidden === '1' && !f.showHidden) return false;
        if (f.category !== '' && d.category !== f.category) return false;
        if (f.area !== '' && !d.areas.split(' ').includes(f.area)) return false;
        if (f.level !== '' && d.level !== f.level) return false;
        if (f.equipment === 'none' && d.equipment !== 'none') return false;
        if (f.equipment !== '' && f.equipment !== 'none' && !d.equipment.split(' ').includes(f.equipment)) return false;
        return f.words.every((word) => d.search.includes(word));
    }

    updateUrl() {
        const params = new URLSearchParams();
        new FormData(this.formTarget).forEach((value, key) => {
            const text = String(value).trim();
            if (text !== '') params.set(key, text);
        });
        const query = params.toString();
        try {
            window.history.replaceState(window.history.state, '', window.location.pathname + (query ? `?${query}` : ''));
        } catch (e) {
            // URL non modifiable (contexte restreint) : le filtre reste appliqué
        }
    }
}
