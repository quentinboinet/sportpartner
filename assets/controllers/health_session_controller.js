import { Controller } from '@hotwired/stimulus';
import { loadTray, saveTray } from './health_tray_store.js';
import {
    buildSteps, clampInt, clock, estimateSeconds, hasCountdown, performed, previousWorkIndex, sessionKind, sportFor,
    stepDurationMs, workBefore, workCount,
} from './health_session_logic.js';

/*
 * Séance guidée de l'espace Santé (/sante/seance, template health/session.html.twig), trois vues :
 *  1. plateau : exercices de la « séance en cours » (health_tray_store.js, localStorage), séries, répétitions ou
 *     secondes et repos par exercice (préremplis avec le dosage par défaut), ordre (Monter / Descendre), retrait ;
 *  2. lecteur : étapes de health_session_logic.js ; compte à rebours pour une série en secondes, bouton « Fait »
 *     pour une série en répétitions, repos avec « +15 s » et « Passer », gauche puis droite pour un exercice
 *     « chaque côté », pause / reprise, précédent / suivant, progression. Bips courts (Web Audio, aucun fichier
 *     son) et vibration si disponible, coupables ; écran maintenu allumé (Screen Wake Lock) pendant la séance,
 *     relâché en pause et à la fin ;
 *  3. fin : résumé, ressenti (RPE 1 à 10), notes, nom, « Ajouter à mes activités » et sport, enregistrement
 *     (fetch JSON vers app_health_session_save, CSRF dans l'en-tête X-CSRF-Token).
 *
 * La séance en cours est gardée dans sessionStorage (sp-health-player:<id>) à chaque étape et chaque seconde : un
 * rechargement accidentel la reprend, en pause, à la même étape. Tout accès au stockage est protégé (try/catch).
 * Le temps est mesuré par différences de performance.now() (robuste au ralentissement des onglets) ; l'heure de
 * début vient de Date.now().
 *
 * Accessibilité : vrais boutons (Entrée / Espace), annonces role="status" aux transitions seulement (jamais à
 * chaque seconde : le compte à rebours a role="timer", non annoncé), focus déplacé sur l'action principale quand
 * le bouton qui l'avait disparaît, confirmations dans la page (pas de confirm() natif).
 * Sécurité : noms et consignes (exercices personnels) insérés uniquement par textContent / setAttribute.
 */

const TICK_MS = 250;
const PERSIST_MS = 1000;
const ADD_REST_MS = 15000;
const STATE_VERSION = 1;
const MUTE_KEY = 'sp-health-muted';

function fill(text, params) {
    return Object.entries(params).reduce((out, [key, value]) => out.split(key).join(String(value)), text);
}

export default class extends Controller {
    static targets = [
        'status', 'notice', 'noticeText', 'confirm', 'confirmText', 'confirmYes', 'confirmNo',
        'trayView', 'trayList', 'trayEmpty', 'itemTemplate', 'estimate', 'startButton', 'clearButton',
        'playerView', 'progressText', 'progressBar', 'progressFill', 'phase', 'name', 'dose', 'side', 'timer',
        'reps', 'repsValue', 'doneButton', 'addRestButton', 'skipButton', 'pausedBanner', 'prevButton',
        'pauseButton', 'pauseIcon', 'pauseLabel', 'nextButton', 'finishButton', 'muteButton', 'muteIcon',
        'muteLabel', 'cues', 'breathing', 'sheetLink',
        'endView', 'endTitle', 'sumDuration', 'sumSets', 'sumExercises', 'summaryList', 'saveForm',
        'saveError', 'saveButton', 'backButton', 'sportRow', 'addActivity',
    ];

    static values = {
        user: Number, catalog: Object, replay: Object, limits: Object, labels: Object, saveUrl: String, token: String,
    };

    connect() {
        this.L = this.labelsValue;
        this.lim = this.limitsValue;
        this.muted = this.readMuted();
        this.audio = null;
        this.wakeLock = null;
        this.interval = null;
        this.lastTick = performance.now();
        this.lastPersist = 0;
        this.saving = false;
        this.cuesFor = null;
        this.player = null;
        this.view = 'tray';
        this.items = this.loadItems();

        this.onVisibility = () => {
            if (document.visibilityState === 'visible' && this.isRunning()) this.requestWakeLock();
            if (document.visibilityState === 'hidden') this.persist(true);
        };
        this.onKeydown = (event) => {
            if (event.key === 'Escape' && !this.confirmTarget.hidden) this.confirmNo();
        };
        document.addEventListener('visibilitychange', this.onVisibility);
        this.element.addEventListener('keydown', this.onKeydown);

        if (this.restore()) {
            if (this.view === 'player') this.notice(this.L.restored);
            if (this.hasReplayValue && this.replayValue.items) this.notice(this.L.playerBusy);
            this.startInterval();
        } else {
            this.applyReplay();
        }
        this.render();
        this.renderMute();
    }

    disconnect() {
        this.persist(true);
        this.stopInterval();
        this.releaseWakeLock();
        document.removeEventListener('visibilitychange', this.onVisibility);
        this.element.removeEventListener('keydown', this.onKeydown);
    }

    // ── Plateau ─────────────────────────────────────────────────────────

    /** Plateau du stockage, borné et réduit aux exercices encore disponibles. */
    loadItems() {
        const raw = loadTray(this.userValue);
        const items = raw.map((item) => this.clampItem(item)).filter((item) => item !== null);
        if (items.length !== raw.length) {
            saveTray(this.userValue, items);
            const dropped = raw.length - items.length;
            this.notice(fill(dropped === 1 ? this.L.unavailableOne : this.L.unavailableMany, { '%count%': dropped }));
        }
        return items;
    }

    clampItem(item) {
        const ex = this.catalogValue[String(item.id)];
        if (!ex) return null;
        const [min, max] = this.lim[ex.mode];
        const bound = (v, lo, hi) => Math.min(hi, Math.max(lo, Number.isInteger(v) ? v : lo));
        return {
            id: item.id,
            sets: bound(item.sets, this.lim.setsMin, this.lim.setsMax),
            value: bound(item.value, min, max),
            rest: bound(item.rest, this.lim.restMin, this.lim.restMax),
        };
    }

    planItem(item) {
        const ex = this.catalogValue[String(item.id)];
        return {
            id: item.id, sets: item.sets, value: item.value, rest: item.rest,
            name: ex.name, lang: ex.lang, category: ex.category, mode: ex.mode, unilateral: ex.unilateral,
            steps: ex.steps, breathing: ex.breathing, url: ex.url,
        };
    }

    saveItems() {
        this.items = saveTray(this.userValue, this.items);
    }

    applyReplay() {
        if (!this.hasReplayValue || !Array.isArray(this.replayValue.items)) return;
        const replay = this.replayValue;
        const items = replay.items.map((item) => this.clampItem(item)).filter((item) => item !== null);
        const date = this.formatDate(replay.date);
        const load = () => {
            this.items = items;
            this.saveItems();
            this.renderTray();
            let text = fill(this.L.replayDone, { '%date%': date });
            const missing = (replay.missing || 0) + (replay.items.length - items.length);
            if (missing > 0) text += ` ${fill(missing === 1 ? this.L.replayMissingOne : this.L.replayMissingMany, { '%count%': missing })}`;
            this.notice(text);
        };
        try {
            const url = new URL(window.location.href);
            url.searchParams.delete('refaire');
            window.history.replaceState(window.history.state, '', url.pathname + url.search);
        } catch (e) {
            // URL non modifiable : sans conséquence
        }
        if (this.items.length > 0) {
            this.ask(fill(this.L.replayConfirm, { '%date%': date }), this.L.replayYes, load, this.L.replayNo);
        } else {
            load();
        }
    }

    trayInput(event) {
        const input = event.currentTarget;
        const index = this.indexOf(input);
        const item = this.items[index];
        if (!item) return;
        const [min, max] = this.rangeFor(input.dataset.field, item);
        const value = clampInt(input.value, min, max);
        const valid = value !== null && String(value) === String(input.value).trim();
        input.setAttribute('aria-invalid', valid ? 'false' : 'true');
        if (!valid) return;
        item[input.dataset.field] = value;
        this.saveItems();
        this.renderEstimate();
    }

    trayChange(event) {
        const input = event.currentTarget;
        const index = this.indexOf(input);
        const item = this.items[index];
        if (!item) return;
        const [min, max] = this.rangeFor(input.dataset.field, item);
        const value = clampInt(input.value, min, max);
        if (value !== null) item[input.dataset.field] = value;
        input.value = String(item[input.dataset.field]);
        input.setAttribute('aria-invalid', 'false');
        this.saveItems();
        this.renderEstimate();
    }

    rangeFor(field, item) {
        if (field === 'sets') return [this.lim.setsMin, this.lim.setsMax];
        if (field === 'rest') return [this.lim.restMin, this.lim.restMax];
        return this.lim[this.catalogValue[String(item.id)].mode];
    }

    moveUp(event) {
        this.move(this.indexOf(event.currentTarget), -1, 'up');
    }

    moveDown(event) {
        this.move(this.indexOf(event.currentTarget), 1, 'down');
    }

    move(index, delta, direction) {
        const target = index + delta;
        if (index < 0 || target < 0 || target >= this.items.length) return;
        [this.items[index], this.items[target]] = [this.items[target], this.items[index]];
        this.saveItems();
        this.renderTray();
        const li = this.trayListTarget.children[target];
        const preferred = li?.querySelector(`[data-move="${direction}"]`);
        const fallback = li?.querySelector(`[data-move="${direction === 'up' ? 'down' : 'up'}"]`);
        (preferred && !preferred.disabled ? preferred : fallback)?.focus();
        this.say(fill(this.L.moved, { '%name%': this.catalogValue[String(this.items[target].id)].name, '%pos%': target + 1 }));
    }

    remove(event) {
        const index = this.indexOf(event.currentTarget);
        const item = this.items[index];
        if (!item) return;
        this.items.splice(index, 1);
        this.saveItems();
        this.renderTray();
        const next = this.trayListTarget.children[Math.min(index, this.items.length - 1)];
        (next?.querySelector('[data-move="remove"]') || this.startButtonTarget).focus();
        this.say(fill(this.L.removed, { '%name%': this.catalogValue[String(item.id)].name }));
    }

    clearTray() {
        this.ask(this.L.clearConfirm, this.L.clearYes, () => {
            this.items = [];
            this.saveItems();
            this.renderTray();
            this.say(this.L.cleared);
        });
    }

    indexOf(element) {
        return Number(element.closest('[data-index]')?.dataset.index ?? -1);
    }

    renderTray() {
        const list = this.trayListTarget;
        list.replaceChildren();
        this.items.forEach((item, index) => {
            const ex = this.catalogValue[String(item.id)];
            const li = this.itemTemplateTarget.content.firstElementChild.cloneNode(true);
            li.dataset.index = String(index);
            const set = (role, text) => { const el = li.querySelector(`[data-role="${role}"]`); if (el) el.textContent = text; return el; };
            set('pos', String(index + 1));
            const link = set('name', ex.name);
            link.setAttribute('href', ex.url);
            if (ex.lang !== document.documentElement.lang) link.setAttribute('lang', ex.lang);
            set('category', this.L.categories[ex.category] || '');
            li.querySelector('[data-role="side"]').hidden = !ex.unilateral;
            set('valueLabel', ex.mode === 'time' ? this.L.valueTime : this.L.valueReps);

            const [min, max] = this.lim[ex.mode];
            this.setupInput(li, 'sets', item.sets, this.lim.setsMin, this.lim.setsMax, this.L.setsFor, ex.name);
            this.setupInput(li, 'value', item.value, min, max, ex.mode === 'time' ? this.L.secondsFor : this.L.repsFor, ex.name);
            this.setupInput(li, 'rest', item.rest, this.lim.restMin, this.lim.restMax, this.L.restFor, ex.name);

            const up = li.querySelector('[data-move="up"]');
            const down = li.querySelector('[data-move="down"]');
            up.setAttribute('aria-label', fill(this.L.moveUp, { '%name%': ex.name }));
            down.setAttribute('aria-label', fill(this.L.moveDown, { '%name%': ex.name }));
            li.querySelector('[data-move="remove"]').setAttribute('aria-label', fill(this.L.removeItem, { '%name%': ex.name }));
            up.disabled = index === 0;
            down.disabled = index === this.items.length - 1;
            list.appendChild(li);
        });
        this.trayEmptyTarget.hidden = this.items.length > 0;
        this.startButtonTarget.disabled = this.items.length === 0;
        this.clearButtonTarget.hidden = this.items.length === 0;
        this.renderEstimate();
    }

    setupInput(li, field, value, min, max, label, name) {
        const input = li.querySelector(`input[data-field="${field}"]`);
        input.value = String(value);
        input.min = String(min);
        input.max = String(max);
        input.setAttribute('aria-label', fill(label, { '%name%': name }));
    }

    renderEstimate() {
        if (this.items.length === 0) {
            this.estimateTarget.textContent = '';
            return;
        }
        const seconds = estimateSeconds(this.items.map((item) => this.planItem(item)), { prep: this.lim.prep, switchSeconds: this.lim.switch });
        this.estimateTarget.textContent = fill(this.L.estimate, { '%duration%': this.duration(seconds) });
    }

    // ── Lecteur ─────────────────────────────────────────────────────────

    start() {
        if (this.items.length === 0 || this.player) return;
        const plan = this.items.map((item) => this.planItem(item));
        const steps = buildSteps(plan, { prep: this.lim.prep, switchSeconds: this.lim.switch });
        this.player = {
            plan, steps, index: 0, remainingMs: stepDurationMs(steps[0]), records: {}, startedAt: Date.now(),
            activeMs: 0, paused: false, finishedEarly: false, form: null,
        };
        this.ensureAudio();
        this.cuesFor = null;
        this.view = 'player';
        this.render();
        this.startInterval();
        this.requestWakeLock();
        this.pauseButtonTarget.focus();
        this.enterStep();
    }

    isRunning() {
        return this.view === 'player' && this.player !== null && !this.player.paused;
    }

    current() {
        return this.player.steps[this.player.index];
    }

    startInterval() {
        if (this.interval !== null) return;
        this.lastTick = performance.now();
        this.interval = window.setInterval(() => this.tick(), TICK_MS);
    }

    stopInterval() {
        if (this.interval !== null) window.clearInterval(this.interval);
        this.interval = null;
    }

    tick() {
        const now = performance.now();
        const delta = Math.max(0, now - this.lastTick);
        this.lastTick = now;
        if (!this.isRunning()) return;

        const p = this.player;
        p.activeMs += delta;
        const step = this.current();
        if (hasCountdown(step)) {
            const before = Math.ceil(p.remainingMs / 1000);
            p.remainingMs -= delta;
            const after = Math.ceil(p.remainingMs / 1000);
            if (p.remainingMs <= 0) {
                this.complete();
                return;
            }
            if (after < before && after >= 1 && after <= 3) this.beep('tick');
            this.renderTimer();
        }
        this.persist(false);
    }

    /** Fin du compte à rebours de l'étape en cours. */
    complete() {
        const p = this.player;
        const step = this.current();
        if (step.kind === 'work') {
            p.records[p.index] = step.value;
            this.beep('end');
        } else {
            this.beep('go');
        }
        this.advance();
    }

    advance() {
        const p = this.player;
        p.index += 1;
        if (p.index >= p.steps.length) {
            p.index = p.steps.length;
            this.end(false);
            return;
        }
        this.enterStep();
    }

    enterStep() {
        // Lu AVANT le rendu : un bouton focalisé qui devient masqué perd le focus au profit de body
        const focused = this.playerViewTarget.contains(document.activeElement) ? document.activeElement : null;
        this.player.remainingMs = stepDurationMs(this.current());
        this.renderPlayer();
        this.announceStep();
        this.keepFocus(focused);
        this.persist(true);
    }

    done() {
        const step = this.player && this.view === 'player' ? this.current() : null;
        if (!step || this.player.paused || step.kind !== 'work' || step.mode !== 'reps') return;
        this.ensureAudio();
        this.player.records[this.player.index] = step.value;
        this.beep('end');
        this.advance();
    }

    skip() {
        const step = this.player && this.view === 'player' ? this.current() : null;
        if (!step || this.player.paused || step.kind === 'work') return;
        this.advance();
    }

    addRest() {
        const step = this.player && this.view === 'player' ? this.current() : null;
        if (!step || this.player.paused || step.kind !== 'rest') return;
        this.player.remainingMs += ADD_REST_MS;
        this.renderTimer();
        this.say(this.L.sayAddedRest);
        this.persist(true);
    }

    next() {
        if (!this.player || this.view !== 'player') return;
        const p = this.player;
        const step = this.current();
        if (step.kind === 'work' && step.mode === 'time') {
            // Série en secondes interrompue : les secondes tenues comptent
            const held = Math.floor((step.value * 1000 - p.remainingMs) / 1000);
            if (held >= 1) p.records[p.index] = Math.min(step.value, held);
        }
        this.advance();
    }

    previous() {
        if (!this.player || this.view !== 'player') return;
        const p = this.player;
        const target = previousWorkIndex(p.steps, p.index);
        Object.keys(p.records).forEach((key) => { if (Number(key) >= target) delete p.records[key]; });
        p.index = target;
        this.enterStep();
    }

    togglePause() {
        if (!this.player || this.view !== 'player') return;
        const p = this.player;
        p.paused = !p.paused;
        if (p.paused) {
            this.releaseWakeLock();
            this.say(this.L.sayPaused);
        } else {
            this.ensureAudio();
            this.lastTick = performance.now();
            this.startInterval();
            this.requestWakeLock();
            this.dismissNotice();
            this.say(this.L.sayResumed);
        }
        this.renderPlayer();
        this.persist(true);
    }

    finish() {
        if (!this.player || this.view !== 'player') return;
        this.ask(this.L.finishConfirm, this.L.finishYes, () => this.end(true));
    }

    end(early) {
        const p = this.player;
        p.finishedEarly = early;
        this.view = 'end';
        this.stopInterval();
        this.releaseWakeLock();
        if (!early) this.beep('finish');
        this.render();
        this.say(early ? this.L.sayStopped : this.L.sayEnd);
        this.persist(true);
        this.endTitleTarget.focus();
    }

    /** Retour au lecteur depuis l'écran de fin d'une séance arrêtée avant la fin. */
    resume() {
        const p = this.player;
        if (!p || p.index >= p.steps.length) return;
        p.paused = true;
        this.view = 'player';
        this.render();
        this.startInterval();
        this.persist(true);
        this.pauseButtonTarget.focus();
    }

    discard() {
        this.ask(this.L.discardConfirm, this.L.discardYes, () => {
            this.clearPlayerState();
            this.player = null;
            this.view = 'tray';
            this.stopInterval();
            this.releaseWakeLock();
            this.render();
            this.say(this.L.discarded);
            this.startButtonTarget.focus();
        });
    }

    renderPlayer() {
        const p = this.player;
        const L = this.L;
        const step = this.current();
        const item = p.plan[step.ex];
        const isWork = step.kind === 'work';

        this.phaseTarget.textContent = isWork
            ? fill(L.phaseWork, { '%n%': step.ex + 1, '%total%': p.plan.length })
            : { rest: L.phaseRest, switch: L.phaseSwitch, prep: L.phasePrep }[step.kind];
        this.phaseTarget.dataset.kind = step.kind;
        this.nameTarget.textContent = item.name;
        if (item.lang !== document.documentElement.lang) this.nameTarget.setAttribute('lang', item.lang);
        else this.nameTarget.removeAttribute('lang');

        const setText = fill(L.setLine, { '%set%': step.set, '%sets%': item.sets });
        this.doseTarget.textContent = isWork
            ? `${setText} · ${this.amount(item.mode, step.value)}`
            : `${L.nextPrefix} ${setText} · ${this.amount(item.mode, item.value)}`;
        this.sideTarget.hidden = step.side === null;
        this.sideTarget.textContent = step.side === null ? '' : (step.side === 'left' ? L.left : L.right);

        const countdown = hasCountdown(step);
        this.timerTarget.hidden = !countdown;
        this.repsTarget.hidden = countdown;
        if (!countdown) this.repsValueTarget.textContent = String(step.value);
        this.renderTimer();

        this.doneButtonTarget.hidden = !(isWork && step.mode === 'reps');
        this.addRestButtonTarget.hidden = step.kind !== 'rest';
        this.skipButtonTarget.hidden = isWork;
        [this.doneButtonTarget, this.addRestButtonTarget, this.skipButtonTarget].forEach((b) => { b.disabled = p.paused; });

        this.pausedBannerTarget.hidden = !p.paused;
        this.pauseIconTarget.className = `bi ${p.paused ? 'bi-play-fill' : 'bi-pause-fill'}`;
        this.pauseLabelTarget.textContent = p.paused ? L.resume : L.pause;
        this.pauseButtonTarget.setAttribute('aria-pressed', p.paused ? 'true' : 'false');
        this.element.classList.toggle('is-paused', p.paused);

        const total = workCount(p.steps);
        const doneCount = workBefore(p.steps, p.index);
        const pct = total > 0 ? Math.round((doneCount / total) * 100) : 0;
        this.progressFillTarget.style.width = `${pct}%`;
        this.progressBarTarget.setAttribute('aria-valuenow', String(pct));
        this.progressBarTarget.setAttribute('aria-valuetext', fill(L.progressValue, { '%done%': doneCount, '%total%': total }));
        this.progressTextTarget.textContent = fill(L.progress, { '%n%': step.ex + 1, '%total%': p.plan.length, '%set%': step.set, '%sets%': item.sets });

        if (this.cuesFor !== `${step.ex}:${item.id}`) {
            this.cuesFor = `${step.ex}:${item.id}`;
            this.cuesTarget.replaceChildren(...item.steps.map((text) => {
                const li = document.createElement('li');
                li.textContent = text;
                return li;
            }));
            [this.cuesTarget, this.breathingTarget].forEach((el) => {
                if (item.lang !== document.documentElement.lang) el.setAttribute('lang', item.lang);
                else el.removeAttribute('lang');
            });
            this.breathingTarget.textContent = item.breathing || '';
            this.breathingTarget.hidden = !item.breathing;
            this.sheetLinkTarget.setAttribute('href', item.url);
        }
    }

    renderTimer() {
        if (!this.player || this.view !== 'player') return;
        const step = this.current();
        if (!hasCountdown(step)) return;
        const text = clock(this.player.remainingMs);
        if (this.timerTarget.textContent !== text) this.timerTarget.textContent = text;
    }

    announceStep() {
        const p = this.player;
        const L = this.L;
        const step = this.current();
        const item = p.plan[step.ex];
        const side = step.side === 'left' ? L.left : (step.side === 'right' ? L.right : '');
        if (step.kind === 'work') {
            this.say(fill(step.side ? L.sayWorkSide : L.sayWork, {
                '%name%': item.name, '%side%': side.toLowerCase(), '%set%': step.set, '%sets%': item.sets,
                '%amount%': this.amount(item.mode, step.value),
            }));
        } else if (step.kind === 'rest') {
            this.say(fill(L.sayRest, { '%duration%': this.seconds(step.seconds), '%name%': item.name }));
        } else if (step.kind === 'switch') {
            this.say(fill(L.saySwitch, { '%side%': side.toLowerCase() }));
        } else {
            this.say(fill(L.sayPrep, { '%name%': item.name }));
        }
    }

    /**
     * Le focus était dans le lecteur (focused, lu avant le rendu) sur un bouton qui vient de disparaître ou d'être
     * désactivé : le donner à l'action principale de la nouvelle étape (« Fait » ou « Passer »), sinon à « Pause ».
     */
    keepFocus(focused) {
        if (!focused) return;
        if (focused.isConnected && !focused.hidden && !focused.disabled && focused.offsetParent !== null) {
            if (document.activeElement !== focused) focused.focus();
            return;
        }
        const target = [this.doneButtonTarget, this.skipButtonTarget].find((b) => !b.hidden && !b.disabled) || this.pauseButtonTarget;
        target.focus();
    }

    // ── Fin de séance ───────────────────────────────────────────────────

    doneLists() {
        const p = this.player;
        return performed(p.plan, p.steps, p.records);
    }

    doneCategories(done) {
        return this.player.plan.filter((item, i) => done[i].length > 0).map((item) => item.category);
    }

    renderEnd() {
        const p = this.player;
        const L = this.L;
        const done = this.doneLists();
        const setsDone = done.reduce((total, list) => total + list.length, 0);
        const exercisesDone = done.filter((list) => list.length > 0).length;

        this.endTitleTarget.textContent = p.finishedEarly ? L.endTitleEarly : L.endTitle;
        this.sumDurationTarget.textContent = this.duration(Math.round(p.activeMs / 1000));
        this.sumSetsTarget.textContent = String(setsDone);
        this.sumExercisesTarget.textContent = fill(L.exercisesDone, { '%done%': exercisesDone, '%total%': p.plan.length });

        this.summaryListTarget.replaceChildren(...p.plan.map((item, i) => {
            const li = document.createElement('li');
            const name = document.createElement('strong');
            name.textContent = item.name;
            if (item.lang !== document.documentElement.lang) name.setAttribute('lang', item.lang);
            const planned = document.createElement('span');
            planned.className = 'sp-health-summary-plan';
            planned.textContent = this.dose(item);
            const result = document.createElement('span');
            result.className = 'sp-health-summary-done';
            const list = done[i];
            if (list.length === 0) {
                result.textContent = L.notDone;
                li.classList.add('is-skipped');
            } else {
                const total = list.reduce((a, b) => a + b, 0);
                const amount = item.mode === 'time' ? this.seconds(total) : fill(L.repsAmount, { '%value%': total });
                result.textContent = `${fill(L.setsDone, { '%done%': list.length, '%sets%': item.sets })} · ${fill(item.unilateral ? L.totalEachSide : L.total, { '%amount%': amount })}`;
            }
            li.append(name, planned, result);
            return li;
        }));

        const form = this.saveFormTarget;
        const cats = this.doneCategories(done);
        const values = p.form || {
            rpe: '', notes: '', name: L.defaultNames[sessionKind(cats)] || '', addActivity: true, sport: sportFor(cats),
        };
        form.querySelectorAll('input[name="rpe"]').forEach((radio) => { radio.checked = radio.value === String(values.rpe); });
        form.elements.namedItem('notes').value = values.notes;
        form.elements.namedItem('name').value = values.name;
        this.addActivityTarget.checked = values.addActivity !== false;
        form.elements.namedItem('sport').value = values.sport === 'other' ? 'other' : 'weight_training';
        this.sportRowTarget.hidden = !this.addActivityTarget.checked;

        this.backButtonTarget.hidden = !(p.finishedEarly && p.index < p.steps.length);
        this.saveButtonTarget.disabled = setsDone === 0 || this.saving;
        this.showSaveError(setsDone === 0 ? L.saveNothing : '');
    }

    formInput() {
        if (!this.player) return;
        const form = this.saveFormTarget;
        const data = new FormData(form);
        this.player.form = {
            rpe: String(data.get('rpe') || ''),
            notes: String(data.get('notes') || ''),
            name: String(data.get('name') || ''),
            addActivity: this.addActivityTarget.checked,
            sport: String(data.get('sport') || 'weight_training'),
        };
        this.sportRowTarget.hidden = !this.addActivityTarget.checked;
        this.persist(true);
    }

    async save(event) {
        event.preventDefault();
        if (this.saving || !this.player) return;
        const p = this.player;
        const L = this.L;
        const done = this.doneLists();
        if (done.every((list) => list.length === 0)) {
            this.showSaveError(L.saveNothing);
            return;
        }
        this.formInput();
        const values = p.form;
        const duration = Math.min(this.lim.durationMax, Math.max(1, Math.round(p.activeMs / 1000)));
        const elapsed = Math.min(this.lim.elapsedMax, Math.max(duration, Math.floor((Date.now() - p.startedAt) / 1000)));
        let timezone = null;
        try {
            timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || null;
        } catch (e) {
            timezone = null;
        }
        const payload = {
            name: values.name.replace(/\s+/g, ' ').trim() || null,
            startedAt: Math.floor(p.startedAt / 1000),
            timezone,
            durationSeconds: duration,
            elapsedSeconds: elapsed,
            rpe: values.rpe !== '' ? Number(values.rpe) : null,
            notes: values.notes.trim() || null,
            addActivity: values.addActivity,
            sport: values.addActivity ? values.sport : null,
            exercises: p.plan.map((item, i) => ({ id: item.id, sets: item.sets, value: item.value, rest: item.rest, performed: done[i] })),
        };

        this.saving = true;
        this.saveButtonTarget.disabled = true;
        this.saveButtonTarget.setAttribute('aria-busy', 'true');
        this.showSaveError('');
        this.say(L.saving);
        let message = L.saveError;
        try {
            const response = await fetch(this.saveUrlValue, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': this.tokenValue },
                body: JSON.stringify(payload),
            });
            const body = await response.json().catch(() => ({}));
            if (response.status === 201 && typeof body.url === 'string') {
                this.clearPlayerState();
                saveTray(this.userValue, []);
                this.player = null;
                window.location.assign(body.url);
                return;
            }
            message = {
                invalid_csrf_token: L.saveCsrf, nothing_done: L.saveNothing, invalid_start: L.saveTooOld,
                unknown_exercise: L.saveUnknown,
            }[body.error] || L.saveError;
        } catch (e) {
            message = L.saveError;
        }
        this.saving = false;
        this.saveButtonTarget.disabled = false;
        this.saveButtonTarget.removeAttribute('aria-busy');
        this.showSaveError(message);
    }

    showSaveError(text) {
        this.saveErrorTarget.textContent = text;
        this.saveErrorTarget.hidden = text === '';
    }

    // ── Rendu général, annonces, confirmations ──────────────────────────

    render() {
        this.trayViewTarget.hidden = this.view !== 'tray';
        this.playerViewTarget.hidden = this.view !== 'player';
        this.endViewTarget.hidden = this.view !== 'end';
        this.element.classList.toggle('is-playing', this.view === 'player');
        if (this.view === 'tray') this.renderTray();
        if (this.view === 'player') this.renderPlayer();
        if (this.view === 'end') this.renderEnd();
    }

    say(text) {
        this.statusTarget.textContent = '';
        window.setTimeout(() => { this.statusTarget.textContent = text; }, 60);
    }

    notice(text) {
        this.noticeTextTarget.textContent = text;
        this.noticeTarget.hidden = false;
    }

    dismissNotice() {
        this.noticeTarget.hidden = true;
    }

    ask(message, yesLabel, onYes, noLabel = this.L.cancel) {
        this.returnFocus = document.activeElement;
        this.pendingYes = onYes;
        this.confirmTextTarget.textContent = message;
        this.confirmYesTarget.textContent = yesLabel;
        this.confirmNoTarget.textContent = noLabel;
        this.confirmTarget.hidden = false;
        this.confirmYesTarget.focus();
    }

    confirmYes() {
        const action = this.pendingYes;
        this.pendingYes = null;
        this.confirmTarget.hidden = true;
        if (action) action();
    }

    confirmNo() {
        this.pendingYes = null;
        this.confirmTarget.hidden = true;
        if (this.returnFocus && this.returnFocus.isConnected) this.returnFocus.focus();
    }

    // ── Textes ──────────────────────────────────────────────────────────

    amount(mode, value) {
        return mode === 'time' ? this.seconds(value) : fill(this.L.repsAmount, { '%value%': value });
    }

    dose(item) {
        const text = fill(item.mode === 'time' ? this.L.doseTime : this.L.doseReps, {
            '%sets%': item.sets, '%value%': item.mode === 'time' ? this.seconds(item.value) : item.value,
        });
        return item.unilateral ? `${text} ${this.L.eachSide}` : text;
    }

    /** « 45 s », « 1 min », « 1 min 30 s » (miroir du filtre Twig health_seconds). */
    seconds(total) {
        const m = Math.floor(total / 60);
        const s = total % 60;
        if (m === 0) return fill(this.L.timeSeconds, { '%s%': s });
        if (s === 0) return fill(this.L.timeMinutes, { '%m%': m });
        return fill(this.L.timeMinutesSeconds, { '%m%': m, '%s%': s });
    }

    /** Durée de séance : « 40 s », « 25 min », « 1 h 05 » (miroir du filtre Twig health_duration). */
    duration(total) {
        if (total < 60) return fill(this.L.timeSeconds, { '%s%': Math.max(0, total) });
        const minutes = Math.floor((total + 30) / 60);
        const h = Math.floor(minutes / 60);
        const m = minutes % 60;
        return h === 0 ? fill(this.L.timeMinutes, { '%m%': m }) : fill(this.L.timeHoursMinutes, { '%h%': h, '%m%': String(m).padStart(2, '0') });
    }

    formatDate(iso) {
        try {
            const [y, mo, d] = String(iso).split('-').map(Number);
            return new Date(y, mo - 1, d).toLocaleDateString(document.documentElement.lang || undefined, { day: 'numeric', month: 'long' });
        } catch (e) {
            return String(iso);
        }
    }

    // ── Son, vibration, écran allumé ────────────────────────────────────

    readMuted() {
        try {
            return window.localStorage.getItem(MUTE_KEY) === '1';
        } catch (e) {
            return false;
        }
    }

    toggleMute() {
        this.muted = !this.muted;
        try {
            if (this.muted) window.localStorage.setItem(MUTE_KEY, '1');
            else window.localStorage.removeItem(MUTE_KEY);
        } catch (e) {
            // préférence gardée pour cette page seulement
        }
        if (!this.muted) this.ensureAudio();
        this.renderMute();
        this.say(this.muted ? this.L.sayMuted : this.L.sayUnmuted);
    }

    renderMute() {
        this.muteButtonTarget.setAttribute('aria-pressed', this.muted ? 'true' : 'false');
        this.muteIconTarget.className = `bi ${this.muted ? 'bi-volume-mute' : 'bi-volume-up'}`;
        this.muteLabelTarget.textContent = this.muted ? this.L.unmute : this.L.mute;
    }

    ensureAudio() {
        if (this.muted || this.audio) return;
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) return;
        try {
            this.audio = new AudioCtx();
        } catch (e) {
            this.audio = null;
        }
    }

    beep(kind) {
        if (this.muted) return;
        const tone = { tick: [880, 0.08], end: [1320, 0.25], go: [660, 0.2], finish: [990, 0.5] }[kind] || [880, 0.1];
        if (kind !== 'tick' && navigator.vibrate) {
            try { navigator.vibrate(kind === 'finish' ? [200, 100, 200] : 150); } catch (e) { /* vibration non disponible */ }
        }
        if (!this.audio) return;
        try {
            if (this.audio.state === 'suspended') this.audio.resume().catch(() => {});
            const t = this.audio.currentTime;
            const osc = this.audio.createOscillator();
            const gain = this.audio.createGain();
            osc.type = 'sine';
            osc.frequency.value = tone[0];
            gain.gain.setValueAtTime(0.0001, t);
            gain.gain.exponentialRampToValueAtTime(0.25, t + 0.01);
            gain.gain.exponentialRampToValueAtTime(0.0001, t + tone[1]);
            osc.connect(gain);
            gain.connect(this.audio.destination);
            osc.start(t);
            osc.stop(t + tone[1] + 0.02);
        } catch (e) {
            // son indisponible : la séance continue
        }
    }

    async requestWakeLock() {
        if (!('wakeLock' in navigator) || this.wakeLock || document.visibilityState !== 'visible') return;
        try {
            const lock = await navigator.wakeLock.request('screen');
            if (!this.isRunning()) {
                lock.release().catch(() => {});
                return;
            }
            this.wakeLock = lock;
            lock.addEventListener('release', () => { if (this.wakeLock === lock) this.wakeLock = null; });
        } catch (e) {
            this.wakeLock = null;
        }
    }

    releaseWakeLock() {
        if (!this.wakeLock) return;
        const lock = this.wakeLock;
        this.wakeLock = null;
        lock.release().catch(() => {});
    }

    // ── Reprise après rechargement (sessionStorage) ─────────────────────

    stateKey() {
        return `sp-health-player:${this.userValue}`;
    }

    persist(force) {
        if (!this.player) return;
        const now = performance.now();
        if (!force && now - this.lastPersist < PERSIST_MS) return;
        this.lastPersist = now;
        const p = this.player;
        const state = {
            v: STATE_VERSION, view: this.view, plan: p.plan, steps: p.steps, index: p.index, remainingMs: p.remainingMs,
            records: p.records, startedAt: p.startedAt, activeMs: p.activeMs, finishedEarly: p.finishedEarly, form: p.form,
        };
        try {
            window.sessionStorage.setItem(this.stateKey(), JSON.stringify(state));
        } catch (e) {
            // stockage indisponible : pas de reprise après rechargement
        }
    }

    restore() {
        let raw = null;
        try {
            raw = window.sessionStorage.getItem(this.stateKey());
        } catch (e) {
            return false;
        }
        if (!raw) return false;
        try {
            const s = JSON.parse(raw);
            const valid = s && s.v === STATE_VERSION && Array.isArray(s.plan) && s.plan.length > 0 && Array.isArray(s.steps)
                && s.steps.length > 0 && Number.isInteger(s.index) && s.index >= 0 && s.index <= s.steps.length
                && Number.isFinite(s.startedAt) && Number.isFinite(s.activeMs);
            if (!valid) {
                this.clearPlayerState();
                return false;
            }
            this.player = {
                plan: s.plan, steps: s.steps, index: s.index, remainingMs: Number(s.remainingMs) || 0,
                records: s.records && typeof s.records === 'object' ? s.records : {}, startedAt: s.startedAt,
                activeMs: s.activeMs, paused: true, finishedEarly: Boolean(s.finishedEarly), form: s.form || null,
            };
            this.view = s.view === 'end' || s.index >= s.steps.length ? 'end' : 'player';
            return true;
        } catch (e) {
            this.clearPlayerState();
            return false;
        }
    }

    clearPlayerState() {
        try {
            window.sessionStorage.removeItem(this.stateKey());
        } catch (e) {
            // rien à effacer
        }
    }
}
