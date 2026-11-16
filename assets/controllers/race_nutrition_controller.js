import { Controller } from '@hotwired/stimulus';
import { compute, carryList } from './race_nutrition_calculator.js';

/*
 * Plan de course : section Nutrition (templates/race/_nutrition.html.twig), un panneau par scénario.
 *
 *  - le panneau affiché suit l'onglet du scénario dans « Temps de passage » (événement race-splits:select émis par
 *    race_splits_controller.js, ou onglet actif au chargement) ; le choix « Scénario » de l'en-tête sélectionne cet
 *    onglet (sans faire défiler la page) ; #nutrition-{id} dans l'URL (retour de « Copier le plan nutrition de… »)
 *    ouvre ce scénario, ramène les messages flash dans la section et la fait défiler à l'écran ;
 *  - recalcul EN DIRECT à chaque modification (cibles, répartition, solide en début de course, produits, lignes) avec
 *    race_nutrition_calculator.js, miroir exact de RaceNutritionCalculator : le rendu serveur reste la référence ;
 *  - lignes auto / manuelles : un +/- ou un ajout sur une ligne auto la rend manuelle (ses apports actuels deviennent
 *    la saisie) ; "Auto" la remet en calcul ; "Tout recalculer" retire toutes les lignes manuelles (confirmation) ;
 *  - enregistrement automatique de TOUT l'état du scénario en JSON (contrat RaceNutritionPayloadValidator), 600 ms
 *    après la dernière modification, tout de suite si la page est masquée ou quittée ou si un autre formulaire de la
 *    page est envoyé ; fetch keepalive, une requête à la fois par scénario, statut role="status" ;
 *  - « Copier le plan nutrition de… » : formulaire classique (confirmation si le scénario a des lignes manuelles) ; un
 *    enregistrement en cours est attendu, un enregistrement en attente est abandonné (la copie remplace tout).
 * Sécurité : noms des produits et des repères insérés par textContent / setAttribute uniquement, jamais innerHTML.
 */

const SAVE_DELAY_MS = 600;
const CUSTOM_PREFIX = 'custom_';
const NUTRITION_HASH = /^#nutrition-(\d+)$/;

/** Remplace les %paramètres% d'un texte traduit (split / join : aucun motif « $ » interprété dans les noms saisis). */
function fill(text, params) {
    return Object.entries(params).reduce((out, [key, value]) => out.split(key).join(String(value)), String(text ?? ''));
}

function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
}

function icon(name) {
    const i = el('i', `bi ${name}`);
    i.setAttribute('aria-hidden', 'true');
    return i;
}

export default class extends Controller {
    static targets = ['panel', 'picker', 'flash', 'announce'];

    static values = { token: String, config: Object, format: Object, labels: Object };

    connect() {
        this.cfg = this.configValue;
        this.L = this.labelsValue;
        this.fmt = this.formatValue;
        this.panels = new Map();
        this.panelTargets.forEach((element) => this.initPanel(element));
        if (this.hasPickerTarget) this.pickerTarget.disabled = false;

        this.onSelect = (event) => this.show(String(event.detail?.id ?? ''));
        this.onPageHide = () => this.flushAll();
        this.onVisibility = () => { if (document.visibilityState === 'hidden') this.flushAll(); };
        // Autre formulaire de la page envoyé (scénario, repère...) : ce qui attend part tout de suite
        this.onSubmit = (event) => { if (!this.element.contains(event.target)) this.flushAll(); };
        window.addEventListener('race-splits:select', this.onSelect);
        window.addEventListener('pagehide', this.onPageHide);
        document.addEventListener('visibilitychange', this.onVisibility);
        document.addEventListener('submit', this.onSubmit, true);

        const match = NUTRITION_HASH.exec(window.location.hash);
        if (match && this.panels.has(match[1])) {
            this.selectScenario(match[1]);
            this.moveFlashes();
            this.scrollToSection();
        } else {
            const tab = document.querySelector('[role="tab"][aria-selected="true"][data-scenario-id]');
            if (tab) this.show(tab.dataset.scenarioId);
        }
    }

    disconnect() {
        window.removeEventListener('race-splits:select', this.onSelect);
        window.removeEventListener('pagehide', this.onPageHide);
        document.removeEventListener('visibilitychange', this.onVisibility);
        document.removeEventListener('submit', this.onSubmit, true);
        if (this.onLoadScroll) window.removeEventListener('load', this.onLoadScroll);
        this.flushAll();
    }

    // ── Panneaux, scénario affiché ──────────────────────────────────────────

    initPanel(element) {
        let state;
        try {
            state = JSON.parse(element.dataset.state);
        } catch {
            return;
        }
        const manual = new Map(Object.entries(state.manual ?? {}).map(([key, lines]) => [key, lines.map((l) => ({ key: l.key, qty: l.qty }))]));
        const panel = {
            el: element,
            id: String(state.scenarioId),
            url: state.url,
            settings: { ...state.settings },
            products: state.products.map((p) => ({ ...p })),
            points: state.points,
            segments: state.segments,
            manual,
            result: null,
            timer: null,
            inflight: false,
            dirty: false,
            saveState: '',
            afterSave: null,
        };
        this.panels.set(panel.id, panel);
        element.querySelectorAll('input, select, button, textarea').forEach((control) => { control.disabled = false; });
        this.render(panel);
    }

    show(id) {
        if (!this.panels.has(id)) return;
        this.panelTargets.forEach((element) => { element.hidden = element.dataset.scenarioId !== id; });
        if (this.hasPickerTarget && this.pickerTarget.value !== id) this.pickerTarget.value = id;
    }

    /** Choix « Scénario » de la section : même scénario dans « Temps de passage » (onglet), sans défilement. */
    pick(event) {
        this.selectScenario(event.currentTarget.value);
    }

    selectScenario(id) {
        const tab = document.getElementById(`scenario-tab-${id}`);
        if (tab) {
            const x = window.scrollX;
            const y = window.scrollY;
            tab.click();
            window.scrollTo(x, y);
        }
        this.show(id);
    }

    moveFlashes() {
        const alerts = Array.from(document.querySelectorAll('.sp-content > .alert'));
        if (this.hasFlashTarget && alerts.length) this.flashTarget.append(...alerts);
    }

    scrollToSection() {
        const scroll = () => requestAnimationFrame(() => this.element.scrollIntoView({ block: 'start' }));
        scroll();
        if (document.readyState !== 'complete') {
            this.onLoadScroll = scroll;
            window.addEventListener('load', scroll, { once: true });
        }
    }

    panelFor(node) {
        const element = node.closest('[data-race-nutrition-target="panel"]');
        return element ? this.panels.get(element.dataset.scenarioId) ?? null : null;
    }

    // ── Lignes : steppers, ajout, retour en auto ────────────────────────────

    increment(event) {
        this.step(event.currentTarget, 1);
    }

    decrement(event) {
        this.step(event.currentTarget, -1);
    }

    step(button, direction) {
        if (button.getAttribute('aria-disabled') === 'true') return;
        const panel = this.panelFor(button);
        const rowEl = button.closest('[data-row-key]');
        const key = button.closest('[data-key]')?.dataset.key;
        if (!panel || !rowEl || !key) return;
        const product = this.product(panel, key);
        if (!product) return;

        const lines = this.editableLines(panel, rowEl.dataset.rowKey);
        const line = lines.find((l) => l.key === key);
        if (!line) return;
        const drink = product.kind === 'drink';
        const max = drink ? this.cfg.maxDrinkMl : this.cfg.maxFoodUnits;
        line.qty = Math.min(max, Math.max(0, line.qty + direction * (drink ? this.cfg.drinkStep : 1)));
        const kept = lines.filter((l) => l.qty > 0);
        panel.manual.set(rowEl.dataset.rowKey, kept);

        this.update(panel);
        this.say(fill(this.L.announce, { '%name%': this.name(product), '%value%': line.qty > 0 ? this.qtyText(product, line.qty) : '0', '%row%': rowEl.dataset.label }));
        // Focus gardé sur le même bouton, ou sur « Ajouter » si la ligne a disparu
        const again = rowEl.querySelector(`.rn-item[data-key="${CSS.escape(key)}"] [data-role="${direction > 0 ? 'plus' : 'minus'}"]`);
        (again ?? rowEl.querySelector('[data-role="add"]'))?.focus();
    }

    addProduct(event) {
        const select = event.currentTarget;
        const key = select.value;
        select.value = '';
        const panel = this.panelFor(select);
        const rowEl = select.closest('[data-row-key]');
        const product = panel ? this.product(panel, key) : null;
        if (!panel || !rowEl || !product) return;

        const lines = this.editableLines(panel, rowEl.dataset.rowKey);
        const drink = product.kind === 'drink';
        const add = drink ? this.cfg.drinkStep : 1;
        const max = drink ? this.cfg.maxDrinkMl : this.cfg.maxFoodUnits;
        const line = lines.find((l) => l.key === key);
        if (line) line.qty = Math.min(max, line.qty + add);
        else lines.push({ key, qty: add });
        panel.manual.set(rowEl.dataset.rowKey, lines);

        this.update(panel);
        const qty = (line ?? lines[lines.length - 1]).qty;
        this.say(fill(this.L.announce, { '%name%': this.name(product), '%value%': this.qtyText(product, qty), '%row%': rowEl.dataset.label }));
        select.focus();
    }

    resetRow(event) {
        const panel = this.panelFor(event.currentTarget);
        const rowEl = event.currentTarget.closest('[data-row-key]');
        if (!panel || !rowEl) return;
        panel.manual.delete(rowEl.dataset.rowKey);
        this.update(panel);
        this.say(fill(this.L.announceReset, { '%row%': rowEl.dataset.label }));
        rowEl.querySelector('[data-role="add"]')?.focus();
    }

    resetAll(event) {
        const panel = this.panelFor(event.currentTarget);
        if (!panel || panel.manual.size === 0) return;
        if (!window.confirm(this.L.confirmReset)) return;
        panel.manual.clear();
        this.update(panel);
    }

    /** Lignes d'une ligne du plan, copiées pour être modifiées (une ligne auto devient manuelle avec ses apports). */
    editableLines(panel, rowKey) {
        if (panel.manual.has(rowKey)) return panel.manual.get(rowKey).map((l) => ({ ...l }));
        const row = panel.result?.rows.find((r) => r.key === rowKey);
        return (row?.items ?? []).map((l) => ({ key: l.key, qty: l.qty }));
    }

    // ── Réglages ────────────────────────────────────────────────────────────

    setting(event) {
        const input = event.currentTarget;
        const panel = this.panelFor(input);
        if (!panel) return;
        const name = input.dataset.setting;
        const s = panel.settings;

        if (name === 'solidEarly') {
            s.solidEarly = input.checked;
        } else if (['gel', 'solid', 'drink'].includes(name)) {
            s[name] = input.value === '' ? null : input.value;
        } else if (name === 'flaskMl') {
            const value = parseInt(input.value, 10);
            if (!Number.isInteger(value)) return;
            s.flaskMl = value;
        } else if (name === 'mixDrink' || name === 'mixSolid') {
            // Saisie en cours invalide : rien n'est appliqué, l'erreur s'affiche en fin de saisie (settingDone)
            if (!this.applyMix(panel, false)) return;
        } else {
            const value = this.parseSetting(name, input.value);
            if (value === null) return;
            input.removeAttribute('aria-invalid');
            s[name] = value;
        }
        this.update(panel);
    }

    /**
     * Fin de saisie d'un réglage numérique : répartition invalide signalée (somme > 100) ; cible invalide remplacée
     * par la dernière valeur valide ; sinon valeur normalisée.
     */
    settingDone(event) {
        const input = event.currentTarget;
        const panel = this.panelFor(input);
        if (!panel) return;
        const name = input.dataset.setting;
        if (name === 'mixDrink' || name === 'mixSolid') {
            this.applyMix(panel, true);
            return;
        }
        const value = this.parseSetting(name, input.value);
        input.value = String(value ?? panel.settings[name]);
        input.removeAttribute('aria-invalid');
    }

    /**
     * Répartition lue dans les deux champs (boisson, solide) ; gels = le reste. Appliquée si elle est valide.
     * reveal : erreur affichée si elle ne l'est pas (fin de saisie) ; elle s'efface dès que la saisie redevient valide.
     */
    applyMix(panel, reveal) {
        const drink = this.parseSetting('mixDrink', panel.el.querySelector('[data-setting="mixDrink"]')?.value ?? '');
        const solid = this.parseSetting('mixSolid', panel.el.querySelector('[data-setting="mixSolid"]')?.value ?? '');
        const ok = drink !== null && solid !== null && drink + solid <= 100;
        if (ok || reveal) this.mixError(panel, !ok);
        if (!ok) return false;
        const s = panel.settings;
        s.mixDrink = drink;
        s.mixSolid = solid;
        s.mixGel = 100 - drink - solid;
        return true;
    }

    /** Valeur entière bornée (null si invalide) : glucides, hydratation (multiple de 50), parts de la répartition. */
    parseSetting(name, raw) {
        const text = String(raw).trim();
        if (!/^\d{1,4}$/.test(text)) return null;
        const value = parseInt(text, 10);
        if (name === 'carbsPerHour') return value <= this.cfg.maxCarbsPerHour ? value : null;
        if (name === 'fluidPerHour') return value <= this.cfg.maxFluidPerHour && value % this.cfg.fluidMultiple === 0 ? value : null;
        return value <= 100 ? value : null;
    }

    mixError(panel, visible) {
        const slot = panel.el.querySelector('[data-role="mix-error"]');
        if (slot) slot.textContent = visible ? this.L.mixError : '';
        panel.el.querySelectorAll('[data-setting="mixDrink"], [data-setting="mixSolid"]').forEach((input) => {
            input.setAttribute('aria-invalid', visible ? 'true' : 'false');
        });
    }

    // ── Produits ────────────────────────────────────────────────────────────

    productCarbs(event) {
        const input = event.currentTarget;
        const panel = this.panelFor(input);
        const product = panel ? this.product(panel, input.closest('[data-key]')?.dataset.key) : null;
        if (!product) return;
        const value = this.parseCarbs(input.value);
        input.setAttribute('aria-invalid', value === null ? 'true' : 'false');
        if (value === null || value === product.carbs) return;
        product.carbs = value;
        this.update(panel);
    }

    productCarbsDone(event) {
        const input = event.currentTarget;
        const panel = this.panelFor(input);
        const product = panel ? this.product(panel, input.closest('[data-key]')?.dataset.key) : null;
        if (!product) return;
        input.value = String(product.carbs);
        input.removeAttribute('aria-invalid');
    }

    parseCarbs(raw) {
        const text = String(raw).trim().replace(',', '.');
        const value = text === '' ? NaN : Number(text);
        if (!Number.isFinite(value) || value < 0 || value > this.cfg.maxCarbs) return null;
        return Math.round(value * 10) / 10;
    }

    addCustom(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const panel = this.panelFor(form);
        if (!panel) return;
        const error = form.querySelector('[data-role="custom-error"]');
        const label = form.elements.namedItem('label').value.replace(/\s+/g, ' ').trim();
        const cat = form.elements.namedItem('cat').value;
        const carbs = this.parseCarbs(form.elements.namedItem('carbs').value);
        const kind = cat === 'drink' ? 'drink' : 'food';
        const count = panel.products.filter((p) => p.label !== null && p.kind === kind).length;
        if (label === '' || [...label].length > this.cfg.labelMax || carbs === null || !['gel', 'solid', 'drink'].includes(cat) || count >= this.cfg.maxCustom) {
            error.textContent = this.L.customError;
            return;
        }
        error.textContent = '';
        const number = 1 + panel.products.reduce((max, p) => (p.key.startsWith(CUSTOM_PREFIX) ? Math.max(max, parseInt(p.key.slice(CUSTOM_PREFIX.length), 10) || 0) : max), 0);
        const product = { key: `${CUSTOM_PREFIX}${number}`, kind, cat, label, carbs };
        // Même ordre que le serveur : aliments (catalogue puis personnalisés), boissons (idem)
        const firstDrink = panel.products.findIndex((p) => p.kind === 'drink');
        if (kind === 'food' && firstDrink >= 0) panel.products.splice(firstDrink, 0, product);
        else panel.products.push(product);

        form.reset();
        this.renderProducts(panel);
        this.update(panel);
        form.elements.namedItem('label').focus();
    }

    deleteProduct(event) {
        const panel = this.panelFor(event.currentTarget);
        const key = event.currentTarget.closest('[data-key]')?.dataset.key;
        const product = panel ? this.product(panel, key) : null;
        if (!product || product.label === null) return;
        const used = [...panel.manual.values()].some((lines) => lines.some((l) => l.key === key));
        if (used && !window.confirm(fill(this.L.confirmDeleteProduct, { '%name%': product.label }))) return;

        panel.products = panel.products.filter((p) => p.key !== key);
        panel.manual.forEach((lines, rowKey) => panel.manual.set(rowKey, lines.filter((l) => l.key !== key)));
        ['gel', 'solid', 'drink'].forEach((cat) => { if (panel.settings[cat] === key) panel.settings[cat] = null; });
        this.renderProducts(panel);
        this.update(panel);
        panel.el.querySelector('.rn-custom [name="label"]')?.focus();
    }

    // ── Calcul et rendu ─────────────────────────────────────────────────────

    update(panel) {
        this.render(panel);
        this.changed(panel);
    }

    input(panel) {
        const s = panel.settings;
        return {
            settings: {
                carbsPerHour: s.carbsPerHour, fluidPerHour: s.fluidPerHour, mixDrink: s.mixDrink, mixGel: s.mixGel,
                mixSolid: s.mixSolid, solidEarly: s.solidEarly === true, gel: s.gel ?? null, solid: s.solid ?? null, drink: s.drink ?? null,
            },
            products: panel.products.map((p) => ({ key: p.key, kind: p.kind, cat: p.cat, tenths: Math.round(p.carbs * 10) })),
            points: panel.points,
            segments: panel.segments,
            manual: Object.fromEntries(panel.manual),
        };
    }

    render(panel) {
        const input = this.input(panel);
        const result = compute(input);
        panel.result = result;
        const rows = new Map(result.rows.map((row) => [row.key, row]));
        panel.el.querySelectorAll('[data-row-key]').forEach((rowEl) => {
            const row = rows.get(rowEl.dataset.rowKey);
            if (row) this.renderRow(panel, rowEl, row);
        });
        this.renderTotals(panel, result);
        this.renderCarry(panel, carryList(input, result, panel.settings.flaskMl));
        this.renderSettings(panel);
        const resetAll = panel.el.querySelector('[data-role="reset-all"]');
        if (resetAll) resetAll.disabled = panel.manual.size === 0;
        panel.el.dataset.hasManual = panel.manual.size > 0 ? '1' : '0';
    }

    renderRow(panel, rowEl, row) {
        rowEl.classList.toggle('is-manual', row.manual);
        const label = rowEl.dataset.label;
        const list = rowEl.querySelector('[data-role="items"]');
        if (list) {
            list.replaceChildren(...row.items.map((line) => this.product(panel, line.key)).map((product, i) => (product ? this.itemElement(product, row.items[i].qty, label) : null)).filter(Boolean));
        }
        const nothing = rowEl.querySelector('[data-role="nothing"]');
        if (nothing) nothing.hidden = row.items.length > 0;

        const carbs = rowEl.querySelector('[data-role="carbs"]');
        if (carbs) this.fig(carbs, row.carbs, ' g');
        const fluid = rowEl.querySelector('[data-role="fluid"]');
        if (fluid) this.fig(fluid, row.fluidMl, ' ml');
        if (row.place === 'segment') {
            this.rate(rowEl.querySelector('[data-role="rate"]'), row.carbsPerHour, row.status, 'g/h', fill(this.L.rateTitle, { '%target%': panel.settings.carbsPerHour }));
            this.rate(rowEl.querySelector('[data-role="frate"]'), row.fluidPerHour, row.fluidStatus, 'ml/h', fill(this.L.fluidTitle, { '%target%': panel.settings.fluidPerHour }));
        }

        const badge = rowEl.querySelector('[data-role="badge"]');
        if (badge) {
            badge.textContent = row.manual ? this.L.stateManual : this.L.stateAuto;
            badge.className = `rn-badge rn-badge--${row.manual ? 'manual' : 'auto'}`;
        }
        const reset = rowEl.querySelector('[data-role="reset"]');
        if (reset) reset.hidden = !row.manual;
    }

    itemElement(product, qty, rowLabel) {
        const drink = product.kind === 'drink';
        const name = this.name(product);
        const params = { '%name%': name, '%row%': rowLabel };
        const item = el('li', 'rn-item');
        item.dataset.key = product.key;
        const ico = el('span', `rn-item-ico rn-item-ico--${drink ? 'drink' : 'food'}`);
        ico.setAttribute('aria-hidden', 'true');
        ico.append(icon(this.cfg.icons?.[product.key] ?? (drink ? this.cfg.iconDrink : this.cfg.iconFood)));
        const nameEl = el('span', 'rn-item-name', name);
        nameEl.dataset.role = 'name';

        const stepper = el('span', 'rn-stepper');
        const minus = el('button', 'rn-step');
        minus.type = 'button';
        minus.dataset.role = 'minus';
        minus.dataset.action = 'race-nutrition#decrement';
        minus.setAttribute('aria-label', fill(drink ? this.L.removeMl : this.L.removeUnit, params));
        minus.append(icon('bi-dash-lg'));
        const count = el('span', 'rn-count', drink ? `${this.int(qty)} ml` : String(qty));
        count.dataset.role = 'count';
        const plus = el('button', 'rn-step');
        plus.type = 'button';
        plus.dataset.role = 'plus';
        plus.dataset.action = 'race-nutrition#increment';
        plus.setAttribute('aria-label', fill(drink ? this.L.addMl : this.L.addUnit, params));
        plus.setAttribute('aria-disabled', qty >= (drink ? this.cfg.maxDrinkMl : this.cfg.maxFoodUnits) ? 'true' : 'false');
        plus.append(icon('bi-plus-lg'));
        stepper.append(minus, count, plus);

        item.append(ico, nameEl, stepper);
        return item;
    }

    fig(node, value, unit) {
        node.dataset.value = String(value);
        node.replaceChildren(el('strong', '', this.int(value)), document.createTextNode(unit));
    }

    rate(node, value, status, unit, title) {
        if (!node) return;
        node.className = `rn-rate rn-rate--${status}`;
        node.setAttribute('title', title);
        const hidden = el('span', 'visually-hidden', status !== 'none' ? ` ${this.L.status[status]}` : '');
        hidden.dataset.role = `${node.dataset.role}-status`;
        node.replaceChildren(document.createTextNode(value === null ? '-' : `${this.int(value)} ${unit}`), hidden);
    }

    renderTotals(panel, result) {
        const t = result.totals;
        const q = (role) => panel.el.querySelector(`[data-role="${role}"]`);
        const rateLabel = q('t-rate-label');
        if (rateLabel) rateLabel.textContent = fill(this.L.totalRate, { '%target%': panel.settings.carbsPerHour });
        const fluidLabel = q('t-frate-label');
        if (fluidLabel) fluidLabel.textContent = fill(this.L.totalFluidRate, { '%target%': panel.settings.fluidPerHour });
        const carbs = q('t-carbs');
        if (carbs) carbs.textContent = this.int(t.carbs);
        const fluid = q('t-fluid');
        if (fluid) fluid.textContent = this.liters(t.fluidMl);
        this.rate(q('t-rate'), t.carbsPerHour, t.status, 'g/h', q('t-rate')?.getAttribute('title') ?? '');
        this.rate(q('t-frate'), t.fluidPerHour, t.fluidStatus, 'ml/h', q('t-frate')?.getAttribute('title') ?? '');
        const products = q('t-products');
        if (products) {
            products.textContent = t.products.length
                ? t.products.map((line) => this.lineText(panel, line)).join(' · ')
                : this.L.nothing;
        }
    }

    renderCarry(panel, stops) {
        const list = panel.el.querySelector('[data-role="carry"]');
        if (!list) return;
        const names = new Map([...panel.el.querySelectorAll('[data-row-key^="p:"]')].map((rowEl) => [rowEl.dataset.rowKey.slice(2), rowEl.dataset.label]));
        list.replaceChildren(...stops.map((stop) => {
            const li = el('li', 'rn-stop');
            li.dataset.anchor = stop.anchor;
            const name = el('span', 'rn-stop-name', names.get(stop.anchor) ?? '');
            if (stop.type === 'aid_station') name.append(' ', el('span', 'rn-tag rn-tag--aid', this.L.tagAid ?? ''));
            if (stop.assistance) name.append(' ', el('span', 'rn-tag rn-tag--assist', this.L.tagAssist ?? ''));
            li.append(name);
            const line = (title, text) => {
                const span = el('span', 'rn-stop-line');
                span.append(el('strong', '', title), ` ${text}`);
                li.append(' ', span); // même texte que le rendu serveur (espace entre les éléments)
            };
            if (stop.carry !== null) {
                line(stop.type === 'start' ? this.L.stopCarry : this.L.stopPickup,
                    stop.carry.length ? stop.carry.map((l) => this.lineText(panel, l)).join(', ') : this.L.empty);
            }
            const here = stop.eatHere.filter((l) => stop.carry === null || this.product(panel, l.key)?.kind === 'drink');
            if (here.length) line(stop.carry === null ? this.L.stopOnSite : this.L.stopEat, here.map((l) => this.lineText(panel, l)).join(', '));
            if (stop.fill !== null && stop.fill.length) {
                line(this.L.stopFill, stop.fill.map((f) => fill(f.flasks === 1 ? this.L.flasksOne : this.L.flasks, {
                    '%count%': f.flasks,
                    '%flask%': panel.settings.flaskMl,
                    '%what%': this.lineText(panel, { key: f.key, qty: f.ml }),
                })).join(', '));
            }
            return li;
        }));
    }

    renderSettings(panel) {
        const s = panel.settings;
        const gel = panel.el.querySelector('[data-role="mix-gel"]');
        if (gel) gel.textContent = fill(this.L.mixGel, { '%v%': s.mixGel });
        [['drink', s.mixDrink], ['gel', s.mixGel], ['solid', s.mixSolid]].forEach(([name, value]) => {
            const bar = panel.el.querySelector(`[data-role="bar-${name}"]`);
            if (bar) bar.style.width = `${value}%`;
        });
    }

    /** Liste des produits, choix des produits auto et menus « Ajouter » après un ajout / une suppression. */
    renderProducts(panel) {
        const list = panel.el.querySelector('[data-role="products"]');
        if (list) {
            const existing = new Map([...list.querySelectorAll('[data-key]')].map((li) => [li.dataset.key, li]));
            list.replaceChildren(...panel.products.map((product) => existing.get(product.key) ?? this.productElement(product)));
        }
        panel.el.querySelectorAll('select[data-cat]').forEach((select) => {
            const cat = select.dataset.cat;
            const first = select.options[0];
            const options = panel.products.filter((p) => p.cat === cat).map((p) => {
                const option = el('option', '', this.name(p));
                option.value = p.key;
                option.selected = panel.settings[cat] === p.key;
                return option;
            });
            first.selected = panel.settings[cat] === null;
            select.replaceChildren(first, ...options);
        });
        panel.el.querySelectorAll('select[data-role="add"]').forEach((select) => {
            const first = select.options[0];
            const groups = ['food', 'drink'].map((kind) => {
                const group = el('optgroup');
                group.setAttribute('label', kind === 'food' ? this.L.optFood : this.L.optDrink);
                group.append(...panel.products.filter((p) => p.kind === kind).map((p) => {
                    const option = el('option', '', this.name(p));
                    option.value = p.key;
                    return option;
                }));
                return group;
            });
            select.replaceChildren(first, ...groups);
            select.value = '';
        });
    }

    productElement(product) {
        const drink = product.kind === 'drink';
        const li = el('li', 'rn-product');
        li.dataset.key = product.key;
        const ico = el('span', `rn-item-ico rn-item-ico--${drink ? 'drink' : 'food'}`);
        ico.setAttribute('aria-hidden', 'true');
        ico.append(icon(drink ? this.cfg.iconDrink : this.cfg.iconFood));
        const name = el('span', 'rn-product-name', this.name(product));
        name.append(' ', el('small', '', { gel: this.L.catGel, solid: this.L.catSolid, drink: this.L.catDrink }[product.cat] ?? ''));
        const carbs = el('span', 'rn-product-carbs');
        const input = el('input', 'rn-carbs-input');
        input.type = 'number';
        input.min = '0';
        input.max = String(this.cfg.maxCarbs);
        input.step = '0.1';
        input.inputMode = 'decimal';
        input.value = String(product.carbs);
        input.dataset.role = 'product-carbs';
        input.dataset.action = 'input->race-nutrition#productCarbs change->race-nutrition#productCarbsDone';
        input.setAttribute('aria-label', fill(drink ? this.L.carbsDrink : this.L.carbsFood, { '%name%': this.name(product) }));
        const unit = el('span', '', drink ? this.L.per500 : this.L.perUnit);
        unit.setAttribute('aria-hidden', 'true');
        carbs.append(input, unit);
        const remove = el('button', 'rn-icon-btn');
        remove.type = 'button';
        remove.dataset.role = 'delete-product';
        remove.dataset.action = 'race-nutrition#deleteProduct';
        remove.setAttribute('aria-label', fill(this.L.deleteProduct, { '%name%': product.label }));
        remove.append(icon('bi-x-lg'));
        li.append(ico, name, carbs, remove);
        return li;
    }

    // ── Enregistrement ──────────────────────────────────────────────────────

    changed(panel) {
        panel.dirty = true;
        this.showSave(panel, 'saving');
        clearTimeout(panel.timer);
        panel.timer = setTimeout(() => { panel.timer = null; this.save(panel); }, SAVE_DELAY_MS);
    }

    async save(panel) {
        if (panel.inflight || !panel.dirty) return;
        clearTimeout(panel.timer);
        panel.timer = null;
        panel.dirty = false;
        panel.inflight = true;
        this.showSave(panel, 'saving');

        let state = 'error';
        try {
            const response = await this.post(panel);
            const data = await response.json().catch(() => null);
            if (response.redirected) state = 'session';
            else if (response.ok && data?.saved === true) state = 'saved';
            else if (response.status === 403 && data?.error === 'invalid_csrf_token') state = 'session';
        } catch {
            // Réseau indisponible : les données restent à envoyer
        }
        panel.inflight = false;

        if (panel.afterSave) {
            const next = panel.afterSave;
            panel.afterSave = null;
            next();
            return;
        }
        if (state !== 'saved') {
            panel.dirty = true;
            this.showSave(panel, state);
        } else if (panel.dirty) {
            if (panel.timer === null) this.save(panel);
        } else {
            this.showSave(panel, 'saved');
        }
    }

    flushAll() {
        this.panels.forEach((panel) => {
            if (!panel.dirty) return;
            if (!panel.inflight) {
                this.save(panel);
                return;
            }
            clearTimeout(panel.timer);
            panel.timer = null;
            panel.dirty = false;
            this.post(panel).catch(() => {});
        });
    }

    retry(event) {
        const panel = this.panelFor(event.currentTarget);
        if (!panel) return;
        panel.dirty = true;
        this.save(panel);
    }

    post(panel) {
        return fetch(panel.url, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': this.tokenValue },
            body: JSON.stringify(this.payload(panel)),
        });
    }

    /** Contrat : RaceNutritionPayloadValidator (tout l'état du plan du scénario). */
    payload(panel) {
        const s = panel.settings;
        return {
            settings: {
                carbsPerHour: s.carbsPerHour, fluidPerHour: s.fluidPerHour, mixDrink: s.mixDrink, mixGel: s.mixGel,
                mixSolid: s.mixSolid, solidEarly: s.solidEarly === true, flaskMl: s.flaskMl,
                gel: s.gel ?? null, solid: s.solid ?? null, drink: s.drink ?? null,
            },
            products: panel.products.map((p) => (p.label === null
                ? { key: p.key, carbs: p.carbs }
                : { key: p.key, kind: p.kind, cat: p.cat, label: p.label, carbs: p.carbs })),
            manual: [...panel.manual].map(([key, items]) => ({
                place: key.startsWith('p:') ? 'point' : 'segment',
                anchor: key.slice(2),
                items: items.filter((l) => l.qty > 0).map((l) => ({ key: l.key, qty: l.qty })),
            })),
        };
    }

    showSave(panel, state) {
        if (state === panel.saveState) return; // role="status" : pas de nouvelle annonce pour le même état
        panel.saveState = state;
        const node = panel.el.querySelector('[data-role="save"]');
        if (node) {
            node.textContent = { saving: this.L.saving, saved: this.L.saved, error: this.L.error, session: this.L.session }[state] ?? '';
            node.className = `rn-save is-${state}`;
        }
        const retry = panel.el.querySelector('[data-role="retry"]');
        if (retry) retry.hidden = state !== 'error';
    }

    /** « Copier le plan nutrition de… » : confirmation, puis envoi après l'enregistrement en cours éventuel. */
    copySubmit(event) {
        const form = event.currentTarget;
        const panel = this.panelFor(form);
        if (!panel) return;
        if (panel.manual.size > 0 && !window.confirm(this.L.confirmCopy)) {
            event.preventDefault();
            return;
        }
        // La copie remplace tout : rien à enregistrer pour ce scénario
        clearTimeout(panel.timer);
        panel.timer = null;
        panel.dirty = false;
        if (panel.inflight) {
            event.preventDefault();
            panel.afterSave = () => form.submit();
        }
    }

    // ── Utilitaires ─────────────────────────────────────────────────────────

    product(panel, key) {
        return panel.products.find((p) => p.key === key) ?? null;
    }

    name(product) {
        return product.label ?? this.L.names?.[product.key] ?? product.key;
    }

    /** Texte court d'une ligne (même rendu que la macro line_text de _nutrition.html.twig). */
    lineText(panel, line) {
        const product = this.product(panel, line.key);
        if (!product) return String(line.qty);
        if (product.kind === 'drink') {
            return fill(this.L.lineDrink, { '%ml%': this.int(line.qty), '%name%': product.label ?? this.L.drinkNames?.[product.key] ?? product.key });
        }
        if (product.label !== null) return fill(this.L.lineCustom, { '%count%': line.qty, '%name%': product.label });
        const unit = this.L.units?.[product.key]?.[line.qty > 1 ? 'other' : 'one'] ?? product.key;
        return fill(this.L.lineFood, { '%count%': line.qty, '%unit%': unit });
    }

    qtyText(product, qty) {
        return product.kind === 'drink' ? `${this.int(qty)} ml` : String(qty);
    }

    say(text) {
        if (this.hasAnnounceTarget) this.announceTarget.textContent = text;
    }

    /** Entier avec séparateur de milliers (identique à number_format de Twig). */
    int(value) {
        return String(value).replace(/\B(?=(\d{3})+(?!\d))/g, this.fmt.ts);
    }

    /** ml -> litres au dixième, arrondi au plus proche en entiers (même calcul que le gabarit). */
    liters(ml) {
        const dl = Math.floor((2 * ml + 100) / 200);
        return `${this.int(Math.floor(dl / 10))}${this.fmt.dp}${dl % 10}`;
    }
}
