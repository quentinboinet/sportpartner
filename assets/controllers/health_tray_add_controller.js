import { Controller } from '@hotwired/stimulus';
import { addToTray, loadTray } from './health_tray_store.js';

/*
 * Espace Santé : boutons « Ajouter à la séance » (bibliothèque, fiche d'un exercice) et état du plateau
 * (health_tray_store.js) : nombre d'exercices dans l'onglet « Séance » et la barre du bas, repère « Dans la
 * séance » sur les cartes. Suit les changements faits dans la page (événement health-tray:change) et dans un
 * autre onglet (événement storage). Annonce chaque ajout dans une zone role="status".
 *
 * Valeurs : user (id, pour la clé de stockage), labels (textes traduits avec %count% / %name%).
 * Paramètres du bouton : id, sets, value, rest (nombres) ; nom de l'exercice dans data-health-name (lu tel quel,
 * sans la conversion de type des paramètres Stimulus).
 */

function fill(text, params) {
    return Object.entries(params).reduce((out, [key, value]) => out.split(key).join(String(value)), text);
}

export default class extends Controller {
    static targets = ['bar', 'barText', 'status', 'badge', 'count'];
    static values = { user: Number, labels: Object };

    connect() {
        this.onChange = () => this.render();
        this.onStorage = (event) => { if (event.key === null || event.key === `sp-health-tray:${this.userValue}`) this.render(); };
        window.addEventListener('health-tray:change', this.onChange);
        window.addEventListener('storage', this.onStorage);
        this.render();
    }

    disconnect() {
        window.removeEventListener('health-tray:change', this.onChange);
        window.removeEventListener('storage', this.onStorage);
    }

    add(event) {
        const { params } = event;
        const L = this.labelsValue;
        const ok = addToTray(this.userValue, {
            id: Number(params.id), sets: Number(params.sets), value: Number(params.value), rest: Number(params.rest),
        });
        const count = loadTray(this.userValue).length;
        const name = event.currentTarget.dataset.healthName || '';
        this.say(ok ? fill(L.added, { '%name%': name, '%count%': count }) : L.full);
    }

    render() {
        const items = loadTray(this.userValue);
        const ids = new Set(items.map((item) => item.id));
        const L = this.labelsValue;
        const text = fill(items.length === 1 ? L.countOne : L.countMany, { '%count%': items.length });

        if (this.hasBarTarget) {
            this.barTarget.hidden = items.length === 0;
            this.barTextTarget.textContent = text;
        }
        this.countTargets.forEach((badge) => {
            badge.textContent = String(items.length);
            badge.hidden = items.length === 0;
        });
        this.badgeTargets.forEach((badge) => {
            badge.hidden = !ids.has(Number(badge.dataset.id));
        });
    }

    say(text) {
        if (!this.hasStatusTarget) return;
        this.statusTarget.textContent = '';
        window.setTimeout(() => { this.statusTarget.textContent = text; }, 50);
    }
}
