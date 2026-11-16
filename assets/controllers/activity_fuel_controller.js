import { Controller } from '@hotwired/stimulus';
import { itemCarbs, summarize } from './activity_fuel_calculator.js';

/*
 * Carte « Ravitaillement » de la page d'une activité (partial activities/_fuel.html.twig).
 * Le serveur rend la carte en lecture seule (contrôles désactivés) ; ce controller l'active, recalcule le bilan
 * en direct (activity_fuel_calculator.js, miroir exact d'ActivityFuelCalculator) et enregistre TOUT l'état en JSON
 * sur app_activity_fuel : 600 ms après la dernière modification, et tout de suite quand la page est masquée ou
 * quittée. Requêtes en fetch keepalive (elles aboutissent même si la page est quittée pendant l'envoi) ; une seule
 * à la fois, les modifications faites pendant l'envoi partent ensuite.
 *
 * Valeurs : url, token (CSRF 'activity_fuel', en-tête X-CSRF-Token), duration (s, 0 si inconnue),
 * config (cible, échelle, limites : ActivityFuelView::config()), format (séparateurs), labels (textes traduits).
 * Sécurité : les noms saisis ne passent que par textContent / setAttribute, jamais par innerHTML.
 */

const SAVE_DELAY_MS = 600;
const CUSTOM_PREFIX = 'custom_';

/** Remplace les %paramètres% d'un texte traduit (split / join : aucun motif « $ » interprété dans les noms saisis). */
function fill(text, params) {
    return Object.entries(params).reduce((out, [key, value]) => out.split(key).join(String(value)), text);
}

export default class extends Controller {
    static targets = ['row', 'list', 'rowTemplate', 'total', 'rate', 'gauge', 'marker', 'status', 'statusIcon',
        'statusText', 'foodCarbs', 'drinkCarbs', 'liquid', 'hydration', 'chip', 'save', 'retry', 'announce',
        'addButton', 'addForm'];

    static values = { url: String, token: String, duration: Number, config: Object, format: Object, labels: Object };

    connect() {
        this.cfg = this.configValue;
        this.L = this.labelsValue;
        this.items = this.rowTargets.map((row) => this.readRow(row));
        this.timer = null;
        this.inflight = false;
        this.dirty = false;
        this.saveState = '';

        this.rowTargets.forEach((row) => this.enable(row));
        this.syncAddButtons();
        this.items.forEach((item) => this.renderRow(item));
        this.render();

        this.onPageHide = () => this.flush();
        this.onVisibility = () => { if (document.visibilityState === 'hidden') this.flush(); };
        window.addEventListener('pagehide', this.onPageHide);
        document.addEventListener('visibilitychange', this.onVisibility);
    }

    disconnect() {
        window.removeEventListener('pagehide', this.onPageHide);
        document.removeEventListener('visibilitychange', this.onVisibility);
        this.flush();
    }

    // ── Actions ─────────────────────────────────────────────

    increment(event) {
        this.step(event.currentTarget, 1);
    }

    decrement(event) {
        this.step(event.currentTarget, -1);
    }

    carbsInput(event) {
        const input = event.currentTarget;
        const item = this.itemFor(input);
        const value = this.parseCarbs(input.value);
        input.setAttribute('aria-invalid', value === null ? 'true' : 'false');
        if (!item || value === null || value === item.carbs) return;
        item.carbs = value;
        this.renderRow(item, false);
        this.render();
        this.changed();
    }

    /** Fin de saisie : valeur normalisée affichée (ou dernière valeur valide si la saisie est invalide). */
    carbsChange(event) {
        const input = event.currentTarget;
        const item = this.itemFor(input);
        if (!item) return;
        input.value = String(item.carbs);
        input.removeAttribute('aria-invalid');
    }

    openAdd(event) {
        const kind = event.currentTarget.dataset.kind;
        const form = this.formFor(kind);
        form.hidden = false;
        this.syncAddButtons();
        form.elements.namedItem('label').focus();
    }

    closeAdd(event) {
        const form = event.currentTarget.closest('form');
        this.resetForm(form);
        form.hidden = true;
        this.syncAddButtons();
        this.buttonFor(form.dataset.kind)?.focus();
    }

    addCustom(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const kind = form.dataset.kind;
        const nameInput = form.elements.namedItem('label');
        const carbsInput = form.elements.namedItem('carbs');
        [nameInput, carbsInput].forEach((input) => { input.setCustomValidity(''); input.removeAttribute('aria-invalid'); });

        const label = nameInput.value.replace(/\s+/g, ' ').trim();
        if (label === '' || [...label].length > this.cfg.labelMax) {
            this.invalid(nameInput, this.L.errorName);
            return;
        }
        const carbs = this.parseCarbs(carbsInput.value);
        if (carbs === null) {
            this.invalid(carbsInput, this.L.errorCarbs);
            return;
        }
        if (this.customCount(kind) >= this.cfg.maxCustom) return;

        const item = this.createCustomRow(kind, label, carbs);
        this.resetForm(form);
        form.hidden = true;
        this.syncAddButtons();
        this.render();
        this.changed();
        this.part(item.row, 'plus').focus();
    }

    removeCustom(event) {
        const item = this.itemFor(event.currentTarget);
        if (!item) return;
        item.row.remove();
        this.items = this.items.filter((other) => other !== item);
        this.syncAddButtons();
        this.render();
        this.changed();
        const button = this.buttonFor(item.kind);
        if (button && !button.hidden) button.focus();
        else this.formFor(item.kind).elements.namedItem('label').focus();
    }

    retry() {
        this.dirty = true;
        this.save();
    }

    // ── État ────────────────────────────────────────────────

    step(button, direction) {
        const item = this.itemFor(button);
        if (!item) return;
        const food = item.kind === 'food';
        const max = food ? this.cfg.maxFoodUnits : this.cfg.maxDrinkMl;
        const next = Math.min(max, Math.max(0, item.quantity + direction * (food ? 1 : this.cfg.drinkStep)));
        if (next === item.quantity) return;
        item.quantity = next;
        this.renderRow(item);
        this.render();
        this.announceTarget.textContent = fill(this.L.announce, {
            '%name%': this.part(item.row, 'name').textContent,
            '%value%': this.quantityText(item),
        });
        this.changed();
    }

    readRow(row) {
        const { kind, key, label, quantity, carbs } = row.dataset;
        const custom = key.startsWith(CUSTOM_PREFIX);
        return { row, kind, key, custom, label: custom ? label : null, quantity: parseInt(quantity, 10) || 0, carbs: Number(carbs) || 0 };
    }

    createCustomRow(kind, label, carbs) {
        const template = this.rowTemplateTargets.find((tpl) => tpl.dataset.kind === kind);
        const row = template.content.querySelector('li').cloneNode(true);
        const food = kind === 'food';
        const params = { '%name%': label };
        row.dataset.key = `${CUSTOM_PREFIX}${this.nextCustomNumber()}`;
        row.dataset.label = label;
        row.dataset.quantity = '0';
        row.dataset.carbs = String(carbs);
        this.part(row, 'name').textContent = label;
        this.part(row, 'delete').setAttribute('aria-label', fill(this.L.deleteCustom, params));
        this.part(row, 'minus').setAttribute('aria-label', fill(food ? this.L.removeUnit : this.L.removeMl, params));
        this.part(row, 'plus').setAttribute('aria-label', fill(food ? this.L.addUnit : this.L.addMl, params));
        const input = this.part(row, 'carbs');
        input.value = String(carbs);
        input.setAttribute('aria-label', fill(food ? this.L.carbsFood : this.L.carbsDrink, params));
        this.listTargets.find((list) => list.dataset.kind === kind).append(row);
        this.enable(row);

        const item = this.readRow(row);
        this.items.push(item);
        this.renderRow(item);
        return item;
    }

    // ── Rendu ───────────────────────────────────────────────

    renderRow(item, withInput = true) {
        const { row } = item;
        const max = item.kind === 'food' ? this.cfg.maxFoodUnits : this.cfg.maxDrinkMl;
        row.dataset.quantity = String(item.quantity);
        row.dataset.carbs = String(item.carbs);
        this.part(row, 'count').textContent = this.quantityText(item);
        this.part(row, 'minus').setAttribute('aria-disabled', item.quantity <= 0 ? 'true' : 'false');
        this.part(row, 'plus').setAttribute('aria-disabled', item.quantity >= max ? 'true' : 'false');
        if (withInput) this.part(row, 'carbs').value = String(item.carbs);
        const grams = itemCarbs(item);
        const total = this.part(row, 'total');
        total.textContent = `${this.int(grams)} g`;
        total.classList.toggle('is-zero', grams === 0);
    }

    render() {
        const target = { min: this.cfg.targetMin, max: this.cfg.targetMax };
        const s = summarize(this.items, this.durationValue, target);
        const liters = this.tenths(s.liquidDl);

        this.totalTarget.textContent = this.int(s.totalCarbs);
        this.rateTarget.textContent = s.carbsPerHour === null ? '-' : this.int(s.carbsPerHour);
        this.foodCarbsTarget.textContent = this.int(s.foodCarbs);
        this.drinkCarbsTarget.textContent = this.int(s.drinkCarbs);
        this.liquidTarget.textContent = liters;
        this.hydrationTarget.textContent = s.hydrationMlPerHour === null ? '-' : this.int(s.hydrationMlPerHour);
        this.chipTarget.textContent = `${this.unitsText(s.foodUnits)} · ${liters} L`;

        // Statut affiché : neutre ("empty") tant que rien n'est saisi, "none" sans durée
        const status = s.status === 'none' || s.foodUnits > 0 || s.liquidMl > 0 ? s.status : 'empty';
        this.statusTarget.className = `sp-fuel-status sp-fuel-status--${status}`;
        this.statusIconTarget.className = `bi ${this.L.icons[status]}`;
        this.statusTextTarget.textContent = this.L.status[status];

        const marker = this.markerTarget;
        marker.className = `sp-fuel-gauge-marker sp-fuel-gauge-marker--${status}`;
        if (s.carbsPerHour === null) {
            marker.hidden = true;
            this.gaugeTarget.setAttribute('aria-label', this.L.gaugeNone);
        } else {
            marker.hidden = false;
            marker.style.left = `${Math.round(Math.min(s.carbsPerHour, this.cfg.gaugeMax) / this.cfg.gaugeMax * 10000) / 100}%`;
            this.gaugeTarget.setAttribute('aria-label', fill(this.L.gauge, { '%rate%': this.int(s.carbsPerHour) }));
        }
    }

    syncAddButtons() {
        for (const button of this.addButtonTargets) {
            const kind = button.dataset.kind;
            button.hidden = !this.formFor(kind).hidden || this.customCount(kind) >= this.cfg.maxCustom;
        }
    }

    showSave(state) {
        if (state === this.saveState) return; // role="status" : pas de nouvelle annonce pour le même état
        this.saveState = state;
        const texts = { saving: this.L.saving, saved: this.L.saved, error: this.L.error, session: this.L.session };
        this.saveTarget.textContent = texts[state] ?? '';
        this.saveTarget.className = `sp-fuel-save is-${state}`;
        this.retryTarget.hidden = state !== 'error';
    }

    // ── Enregistrement ──────────────────────────────────────

    changed() {
        this.dirty = true;
        this.showSave('saving');
        clearTimeout(this.timer);
        this.timer = setTimeout(() => { this.timer = null; this.save(); }, SAVE_DELAY_MS);
    }

    async save() {
        if (this.inflight || !this.dirty) return; // relancé à la fin de la requête en cours
        clearTimeout(this.timer);
        this.timer = null;
        this.dirty = false;
        this.inflight = true;
        this.showSave('saving');

        let state = 'error';
        try {
            const response = await this.post();
            const data = await response.json().catch(() => null);
            if (response.redirected) state = 'session'; // session expirée : redirection vers la connexion
            else if (response.ok && data?.saved === true) state = 'saved';
            else if (response.status === 403 && data?.error === 'invalid_csrf_token') state = 'session';
        } catch {
            // Réseau indisponible : état "error", les données restent à envoyer
        }
        this.inflight = false;

        if (state !== 'saved') {
            this.dirty = true;
            this.showSave(state);
        } else if (this.dirty) {
            if (this.timer === null) this.save(); // modifié pendant l'envoi : on renvoie l'état courant
        } else {
            this.showSave('saved');
        }
    }

    /** Page masquée ou quittée : envoi immédiat de ce qui n'est pas encore parti (sans attendre la requête en cours). */
    flush() {
        if (!this.dirty) return;
        if (!this.inflight) {
            this.save();
            return;
        }
        clearTimeout(this.timer);
        this.timer = null;
        this.dirty = false;
        this.post().catch(() => {});
    }

    /** keepalive : la requête aboutit même si la page est quittée pendant l'envoi (corps de quelques Ko). */
    post() {
        return fetch(this.urlValue, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': this.tokenValue },
            body: JSON.stringify(this.payload()),
        });
    }

    /** Contrat : FuelPayloadValidator (tout l'état de la carte, lignes à 0 comprises). */
    payload() {
        return {
            items: this.items.map((item) => {
                const line = { kind: item.kind, key: item.key, quantity: item.quantity, carbs: item.carbs };
                if (item.custom) line.label = item.label;
                return line;
            }),
        };
    }

    // ── Utilitaires ─────────────────────────────────────────

    itemFor(element) {
        const row = element.closest('[data-activity-fuel-target="row"]');
        return this.items.find((item) => item.row === row) ?? null;
    }

    part(row, role) {
        return row.querySelector(`[data-role="${role}"]`);
    }

    formFor(kind) {
        return this.addFormTargets.find((form) => form.dataset.kind === kind);
    }

    buttonFor(kind) {
        return this.addButtonTargets.find((button) => button.dataset.kind === kind);
    }

    enable(row) {
        row.querySelectorAll('button, input').forEach((control) => { control.disabled = false; });
    }

    customCount(kind) {
        return this.items.filter((item) => item.custom && item.kind === kind).length;
    }

    /** Clé libre : numéro suivant le plus grand utilisé (aliments et boissons confondus, unique dans l'activité). */
    nextCustomNumber() {
        return 1 + this.items.reduce((max, item) => (item.custom ? Math.max(max, parseInt(item.key.slice(CUSTOM_PREFIX.length), 10) || 0) : max), 0);
    }

    parseCarbs(raw) {
        const text = String(raw).trim().replace(',', '.');
        const value = text === '' ? NaN : Number(text);
        if (!Number.isFinite(value) || value < 0 || value > this.cfg.maxCarbs) return null;
        return Math.round(value * 10) / 10;
    }

    invalid(input, message) {
        input.setAttribute('aria-invalid', 'true');
        input.setCustomValidity(message);
        input.reportValidity();
        input.addEventListener('input', () => { input.setCustomValidity(''); input.removeAttribute('aria-invalid'); }, { once: true });
    }

    resetForm(form) {
        form.reset();
        [...form.elements].forEach((el) => { if (el.setCustomValidity) { el.setCustomValidity(''); el.removeAttribute('aria-invalid'); } });
    }

    quantityText(item) {
        return item.kind === 'food' ? String(item.quantity) : `${this.int(item.quantity)} ml`;
    }

    unitsText(count) {
        if (count === 0) return this.L.unitsZero;
        if (count === 1) return this.L.unitsOne;
        return fill(this.L.unitsOther, { '%count%': this.int(count) });
    }

    /** Entier avec séparateur de milliers (identique à number_format de Twig). */
    int(value) {
        return String(value).replace(/\B(?=(\d{3})+(?!\d))/g, this.formatValue.ts);
    }

    /** Dixièmes -> « 1,0 » (1 décimale, sans arrondi flottant). */
    tenths(value) {
        return `${this.int(Math.floor(value / 10))}${this.formatValue.dp}${value % 10}`;
    }
}
