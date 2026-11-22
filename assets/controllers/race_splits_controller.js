import { Controller } from '@hotwired/stimulus';

/*
 * Plan de course, phase 2 : scénarios et temps de passage (templates/race/_scenarios.html.twig).
 *
 *  - onglets accessibles (role tablist/tab/tabpanel, flèches, Début/Fin), onglet actif lu dans l'URL (#scenario-{id}) ;
 *    chaque changement d'onglet émet "race-splits:select" (detail.id), suivi par la section Nutrition ;
 *  - recalcul EN DIRECT du tableau pendant la saisie, sans aller-retour serveur : fillAuto() et compute() ci-dessous
 *    sont les miroirs exacts de App\Service\Route\SplitCalculator::fillAutoFromEfforts() et compute(),
 *    parseDuration() celui de DurationFormat::parse() ;
 *  - temps fixés / auto : un input de temps a data-sc-state="pinned" (temps fixé, name="splits[id]", posté) ou
 *    "auto" (temps calculé affiché, SANS name : jamais posté). Saisir dans un auto le fixe (son texte est sélectionné
 *    à la prise de focus : la saisie le remplace) ; vider un temps fixé ou cliquer sur son épingle (data-sc-unpin)
 *    le repasse en auto. Tous les auto sont recalculés à chaque frappe ; le temps visé (arrivée) n'est jamais
 *    modifié par le calcul ;
 *  - validation des durées et du nom : setCustomValidity à chaque frappe (bloque l'envoi), mais erreur VISIBLE
 *    (bordure, aria-invalid, message) seulement en fin de saisie (change), à l'envoi refusé (invalid) ou dès que la
 *    valeur redevient valide ; une durée incomplète ("2", "2:", "2:0") ne relance pas le calcul : le tableau garde
 *    le résultat précédent ;
 *  - stratégie d'allure (select data-sc-strategy, intensité en boutons radio data-sc-intensity, masquée pour
 *    "Régulier" mais toujours postée) : pacingMultiplier() / pacedEfforts() sont les miroirs exacts de
 *    App\Entity\PacingStrategy::multiplier() et SplitCalculator::pacedEfforts() ; changer de stratégie ou
 *    d'intensité recalcule aussitôt les temps auto (jamais les temps fixés ni le temps visé) et met à jour la phrase
 *    d'explication (data-sc-strategy-desc) ; l'écart de chaque allure de tronçon avec l'allure moyenne en mouvement
 *    (paceDeviationPct de compute()) est affiché dans la colonne des allures ;
 *  - indicateur "modifications non enregistrées", alerte avant de quitter la page avec des modifications,
 *    confirmations des actions (répartir, dupliquer, supprimer, imprimer une version non enregistrée) ;
 *  - retour d'une action (URL #scenario-{id} ou #scenarios) : les messages flash affichés en haut de page par
 *    base.html.twig (.sp-content > .alert) sont déplacés en tête de la section, là où la page défile ;
 *  - bande des tronçons (data-sc-strip, macro strip de _scenarios.html.twig, version enregistrée au rendu) : allure de
 *    chaque tronçon, teinte (stripLevels(), même formule que la macro) et heure à chaque repère mises à jour à chaque
 *    recalcul, à partir des lignes de compute() (aucun calcul de plus) ; les largeurs (distances) ne changent pas.
 *    Libellés qui se chevauchent masqués (layoutStrip, à chaque recalcul et redimensionnement, ResizeObserver).
 *    Bande décorative (aria-hidden) : le tableau donne les mêmes valeurs ;
 *
 * Valeurs :
 *  - checkpoints   : [{ id, name, type, distanceM, stopSeconds, cutoffSeconds, segmentGainM, cumulativeGainM }, …]
 *                    repères du tableau (triés par distance, sans ceux au-delà de l'arrivée)
 *  - routeDistance : distance de l'arrivée (m) - longueur du tracé, sinon distance de la course, sinon 0
 *  - efforts       : n + 1 efforts des tronçons (SplitCalculator::routeEfforts), ordre des repères triés
 *  - locale        : 'fr' | 'en' (formatage des nombres)
 *  - labels        : libellés traduits (placeholders %n%, %time%, %m%, %v%, %km%, %from%, %count%, %pace%) ;
 *                    strategyDesc : { even: '…', conservative: '…', … } phrases d'explication des stratégies
 *  - tightMargin   : marge (s) sous laquelle une barrière est "tendue" (SplitCalculator::TIGHT_MARGIN_SECONDS)
 *
 * Les données saisies ne sont jamais insérées comme HTML (textContent / setAttribute uniquement).
 */

// PCRE sans modificateur u : \s = espace, \t, \n, \v, \f, \r ; \d = [0-9]
const WS = '[\\t\\n\\v\\f\\r ]*';
const RE_COLON = new RegExp(`^(\\d{1,3})${WS}:${WS}([0-5]\\d)(?:${WS}:${WS}([0-5]\\d))?$`);
const RE_HOURS = new RegExp(`^(\\d{1,3})${WS}[hH](?:${WS}([0-5]\\d))?$`);
// Heure de départ : même règle que RaceScenarioController::parseFields()
const RE_START = new RegExp(`^([01]?\\d|2[0-3])${WS}[:hH]${WS}([0-5]\\d)$`);
// trim() de PHP : espace, \t, \n, \r, \0, \x0B
const phpTrim = (s) => String(s ?? '').replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '');

const TYPE_AID_STATION = 'aid_station';
const TYPE_FINISH = 'finish';
const SCENARIO_HASH = /^#scenario-(\d+)$/;

/**
 * DurationFormat::parse() : { ok: true, seconds: int|null } (null = vide) ou { ok: false }.
 */
export function parseDuration(value) {
    const v = phpTrim(value);
    if (v === '') return { ok: true, seconds: null };
    let m = RE_COLON.exec(v);
    if (m) return { ok: true, seconds: +m[1] * 3600 + +m[2] * 60 + +(m[3] ?? 0) };
    m = RE_HOURS.exec(v);
    if (m) return { ok: true, seconds: +m[1] * 3600 + +(m[2] ?? 0) * 60 };
    return { ok: false };
}

/** Heure de départ "HH:MM" -> secondes depuis minuit, null si vide ou invalide. */
export function parseStart(value) {
    const m = RE_START.exec(phpTrim(value));
    return m ? +m[1] * 3600 + +m[2] * 60 : null;
}

const pad2 = (n) => String(n).padStart(2, '0');

/** round() de PHP (arrondi "half away from zero"). */
const phpRound = (x, precision = 0) => {
    const f = 10 ** precision;
    return (Math.sign(x) * Math.round(Math.abs(x) * f)) / f;
};

/** SplitCalculator::round2() : arrondi à 0,01 des allures et vitesses, mêmes opérations flottantes qu'en PHP. */
const round2 = (x) => Math.floor(x * 100 + 0.5) / 100;

/**
 * number_format() de PHP (filtre Twig number_format) : arrondi PHP puis séparateurs explicites,
 * pour un affichage identique au rendu serveur (Intl.NumberFormat utiliserait une espace fine en fr).
 */
export function numberFormat(value, decimals, decPoint, thousandsSep) {
    const fixed = Math.abs(phpRound(value, decimals)).toFixed(decimals);
    const [int, frac] = fixed.split('.');
    const grouped = int.replace(/\B(?=(\d{3})+(?!\d))/g, thousandsSep);
    const sign = value < 0 && Number(fixed) !== 0 ? '-' : '';
    return sign + grouped + (frac !== undefined ? decPoint + frac : '');
}

/** DurationFormat::format() */
export function formatDuration(seconds, withSeconds = false) {
    const sign = seconds < 0 ? '-' : '';
    const abs = Math.abs(seconds);
    const h = Math.trunc(abs / 3600);
    const min = Math.trunc((abs % 3600) / 60);
    return withSeconds ? `${sign}${h}:${pad2(min)}:${pad2(abs % 60)}` : `${sign}${h}:${pad2(min)}`;
}

/** DurationFormat::formatInput() */
export const formatInput = (seconds) => formatDuration(seconds, seconds % 60 !== 0);

/** DurationFormat::formatPace() */
export function formatPace(secondsPerKm) {
    const s = phpRound(secondsPerKm);
    return `${Math.trunc(s / 60)}:${pad2(s % 60)}`;
}

/** DurationFormat::formatClock() avec une heure de départ en secondes depuis minuit. */
export function formatClock(startOfDay, elapsed) {
    const total = startOfDay + elapsed;
    const dayOffset = Math.floor(total / 86400);
    const tod = total - dayOffset * 86400;
    return { time: `${pad2(Math.trunc(tod / 3600))}:${pad2(Math.trunc((tod % 3600) / 60))}`, dayOffset };
}

function effectiveStop(cp, finishM) {
    if (cp.type !== TYPE_AID_STATION || cp.distanceM >= finishM) return 0;
    return Math.max(0, Math.trunc(Number(cp.stopSeconds) || 0));
}

const ROUNDING_SECONDS = 10;

/*
 * Stratégies d'allure : miroir exact de App\Entity\PacingStrategy (constantes, multiplier(), suggestedFor() côté
 * serveur seulement). Coefficient de chaque stratégie par intensité : a (Départ prudent), k (Negative split,
 * Prise de risque), f (Gestion ultra). Toute modification se fait des deux côtés : PacingStrategyTest compare ce
 * tableau (format "nom: { light: x, medium: y, strong: z }" à garder) et PACING_CONSERVATIVE_UNTIL à l'enum PHP.
 */
export const PACING_COEFFICIENTS = {
    conservative: { light: 0.02, medium: 0.04, strong: 0.06 },
    negative: { light: 0.03, medium: 0.06, strong: 0.10 },
    aggressive: { light: 0.02, medium: 0.04, strong: 0.08 },
    ultra: { light: 0.08, medium: 0.15, strong: 0.25 },
};
export const PACING_CONSERVATIVE_UNTIL = 0.3;
export const PACING_STRATEGIES = ['even', 'conservative', 'negative', 'aggressive', 'ultra'];
export const PACING_INTENSITIES = ['light', 'medium', 'strong'];

/**
 * PacingStrategy::multiplier() : multiplicateur d'allure au point x de la course (0 = départ, 1 = arrivée),
 * > 1 = plus lent. Mêmes opérations flottantes qu'en PHP.
 *  - even : 1 ; conservative : 1 + a * (1 - x / 0.3) si x < 0.3, sinon 1 ; negative : 1 + k * (0.5 - x) ;
 *  - aggressive : 1 - k * (0.5 - x) ; ultra : 1 + f * (x - 0.5).
 */
export function pacingMultiplier(strategy, intensity, x) {
    const c = PACING_COEFFICIENTS[strategy]?.[intensity] ?? 0;
    switch (strategy) {
        case 'conservative': return x < PACING_CONSERVATIVE_UNTIL ? 1 + c * (1 - x / PACING_CONSERVATIVE_UNTIL) : 1;
        case 'negative': return 1 + c * (0.5 - x);
        case 'aggressive': return 1 - c * (0.5 - x);
        case 'ultra': return 1 + c * (x - 0.5);
        default: return 1;
    }
}

/**
 * SplitCalculator::pacedEfforts() : poids des n + 1 tronçons = effort * m(x), x = milieu du tronçon rapporté à la
 * distance de l'arrivée ((début + fin) / (2 * arrivée), 0 si elle est nulle). "Régulier" : efforts inchangés.
 *
 * @param {Array} checkpoints  repères TRIÉS par distance
 * @param {number} finishM
 * @param {number[]} efforts   n + 1 efforts
 */
export function pacedEfforts(checkpoints, finishM, efforts, strategy, intensity) {
    if (strategy === 'even' || !PACING_COEFFICIENTS[strategy]) return efforts;
    const n = checkpoints.length;
    let previous = 0;
    return efforts.map((effort, i) => {
        const distance = i < n ? checkpoints[i].distanceM : finishM;
        const x = finishM > 0 ? (previous + distance) / (2 * finishM) : 0;
        previous = distance;
        return effort * pacingMultiplier(strategy, intensity, x);
    });
}

/** SplitCalculator::deviationPct() : écart en % entier d'une allure de tronçon avec l'allure moyenne en mouvement. */
const deviationPct = (moving, span, totalMoving, finishM) => Math.floor(moving * finishM / (span * totalMoving) * 100 - 100 + 0.5);

/**
 * Miroir exact de SplitCalculator::fillAutoFromEfforts() (mêmes règles, même ordre des opérations flottantes,
 * même arrondi floor(x / 10 + 0.5) * 10, ou floor(x + 0.5) quand l'arrondi aux 10 s sortirait de la fenêtre
 * stricte) : temps des repères auto entre les ancres (départ, temps fixés, cible), au prorata des poids
 * effort * m(x) de la stratégie d'allure (pacedEfforts). Toute modification se fait des deux côtés à la fois.
 *
 * @param {Array} checkpoints        [{ id, type, distanceM, stopSeconds }]
 * @param {number} routeDistanceM
 * @param {number[]} efforts         n + 1 efforts dans l'ordre des repères triés par distance
 * @param {Map<number, number>} pinned  checkpointId => arrivée fixée (s)
 * @param {?number} targetSeconds
 * @param {string} strategy          'even' | 'conservative' | 'negative' | 'aggressive' | 'ultra'
 * @param {string} intensity         'light' | 'medium' | 'strong'
 * @returns {{ arrivals: Map<number, ?number>, pinned: Map<number, boolean> }}
 */
export function fillAuto(checkpoints, routeDistanceM, efforts, pinned, targetSeconds, strategy = 'even', intensity = 'medium') {
    const cps = [...checkpoints].sort((a, b) => a.distanceM - b.distanceM); // tri stable
    const last = cps.length ? cps[cps.length - 1].distanceM : 0;
    const finishM = Math.max(0, routeDistanceM, last);
    const n = cps.length;
    if (efforts.length !== n + 1) throw new Error(`Expected ${n + 1} efforts, got ${efforts.length}.`);
    // Poids des tronçons : effort modulé par la stratégie d'allure
    const weights = pacedEfforts(cps, finishM, efforts, strategy, intensity);

    const arrivals = new Map();
    const isPinned = new Map();
    const stops = cps.map(cp => effectiveStop(cp, finishM));
    cps.forEach((cp) => {
        arrivals.set(cp.id, pinned.has(cp.id) ? pinned.get(cp.id) : null);
        isPinned.set(cp.id, pinned.has(cp.id));
    });

    let left = -1; // ancre de gauche (-1 = départ)
    let leftDeparture = 0;
    for (let right = 0; right <= n; right++) {
        if (right < n && !isPinned.get(cps[right].id)) continue;
        const rightArrival = right < n ? arrivals.get(cps[right].id) : targetSeconds;

        if (rightArrival !== null) {
            let stopsBetween = 0;
            for (let k = left + 1; k < right; k++) stopsBetween += stops[k];
            const available = rightArrival - leftDeparture - stopsBetween;
            let totalEffort = 0;
            for (let k = left + 1; k <= right; k++) totalEffort += weights[k];

            // Tronçons de longueur non nulle entre chaque auto et l'ancre de droite (1 s gardée pour chacun)
            const strictAfter = [];
            let nextDistance = right < n ? cps[right].distanceM : finishM;
            let strictCount = 0;
            for (let k = right - 1; k > left; k--) {
                strictCount += nextDistance > cps[k].distanceM ? 1 : 0;
                strictAfter[k] = strictCount;
                nextDistance = cps[k].distanceM;
            }

            let cumEffort = 0;
            let stopsBefore = 0;
            let previousDeparture = leftDeparture;
            let previousDistance = left >= 0 ? cps[left].distanceM : 0;
            for (let k = left + 1; k < right; k++) {
                let arrival;
                if (available <= 0) {
                    arrival = previousDeparture;
                } else {
                    cumEffort += weights[k];
                    const share = totalEffort > 0 ? cumEffort / totalEffort : 0;
                    const ideal = leftDeparture + available * share + stopsBefore;
                    arrival = Math.floor(ideal / ROUNDING_SECONDS + 0.5) * ROUNDING_SECONDS;
                    const latest = rightArrival - stopsBetween + stopsBefore;
                    // Fenêtre stricte (règle de compute() : deux temps égaux seulement à la même distance)
                    const low = previousDeparture + (cps[k].distanceM > previousDistance ? 1 : 0);
                    const high = latest - strictAfter[k];
                    if (low <= high) {
                        if (arrival < low || arrival > high) arrival = Math.min(Math.max(Math.floor(ideal + 0.5), low), high);
                    } else {
                        arrival = Math.min(Math.max(arrival, previousDeparture), latest);
                    }
                }
                arrivals.set(cps[k].id, arrival);
                stopsBefore += stops[k];
                previousDeparture = arrival + stops[k];
                previousDistance = cps[k].distanceM;
            }
        }

        if (right < n) {
            left = right;
            leftDeparture = rightArrival + stops[right];
        }
    }

    return { arrivals, pinned: isPinned };
}

/**
 * Miroir de SplitCalculator::compute() (mêmes règles de référence, d'erreurs, de barrières et d'écart d'allure).
 * D+/D- non recalculés ici (statiques, fournis par le serveur).
 *
 * @param {Array} checkpoints   [{ id, type, distanceM, stopSeconds, cutoffSeconds }]
 * @param {number} routeDistanceM
 * @param {Map<number, number>} arrivals  checkpointId => arrivée (s)
 * @param {?number} targetSeconds
 * @param {?number} startOfDay  heure de départ en secondes depuis minuit
 * @param {number} tightMargin
 * @param {?Map<number, boolean>} pinned  checkpointId => temps fixé ? (null : tout repère qui a une arrivée)
 */
export function compute(checkpoints, routeDistanceM, arrivals, targetSeconds, startOfDay, tightMargin = 900, pinned = null) {
    const cps = [...checkpoints].sort((a, b) => a.distanceM - b.distanceM); // tri stable
    const last = cps.length ? cps[cps.length - 1].distanceM : 0;
    const finishM = Math.max(0, routeDistanceM, last);
    const n = cps.length;

    const rows = [];
    const errors = [];
    let refId = null;
    let refDistance = 0;
    let refDeparture = 0;
    let totalStops = 0;
    let complete = targetSeconds !== null;

    for (let i = 0; i <= n; i++) {
        const cp = i < n ? cps[i] : null;
        const distance = cp ? cp.distanceM : finishM;
        const arrival = cp ? (arrivals.has(cp.id) ? arrivals.get(cp.id) : null) : targetSeconds;
        const stop = cp ? effectiveStop(cp, finishM) : 0;
        totalStops += stop;
        if (arrival === null) complete = false;

        const row = {
            checkpointId: cp ? cp.id : null,
            type: cp ? cp.type : TYPE_FINISH,
            pinned: cp
                ? (pinned !== null ? (pinned.get(cp.id) ?? false) : arrival !== null)
                : targetSeconds !== null,
            distanceM: distance,
            arrivalSeconds: arrival,
            departureSeconds: null,
            stopSeconds: stop,
            paceFromCheckpointId: null,
            paceSpanDistanceM: null,
            segmentMovingSeconds: null,
            segmentPaceSecPerKm: null,
            segmentSpeedKmh: null,
            avgPaceSecPerKm: null,
            paceDeviationPct: null,
            clock: null,
            departureClock: null,
            cutoffSeconds: cp && cp.cutoffSeconds !== null && cp.cutoffSeconds !== undefined ? cp.cutoffSeconds : null,
            cutoffMarginSeconds: null,
            cutoffStatus: 'none',
            errors: [],
        };

        if (arrival !== null) {
            const departure = arrival + stop;
            const span = distance - refDistance;
            const moving = arrival - refDeparture;

            row.departureSeconds = departure;
            row.paceFromCheckpointId = refId;
            row.paceSpanDistanceM = span;
            row.segmentMovingSeconds = moving >= 0 ? moving : null;
            if (moving > 0 && span > 0) {
                row.segmentPaceSecPerKm = round2(moving / (span / 1000));
                row.segmentSpeedKmh = round2((span / 1000) / (moving / 3600));
            }
            if (arrival > 0 && distance > 0) {
                row.avgPaceSecPerKm = round2(arrival / (distance / 1000));
            }
            if (startOfDay !== null) {
                row.clock = formatClock(startOfDay, arrival);
                row.departureClock = formatClock(startOfDay, departure);
            }
            if (row.cutoffSeconds !== null) {
                const margin = row.cutoffSeconds - arrival;
                row.cutoffMarginSeconds = margin;
                row.cutoffStatus = margin < 0 ? 'missed' : (margin < tightMargin ? 'tight' : 'ok');
            }

            if (moving < 0 || (moving === 0 && span > 0)) row.errors.push('not_increasing');
            if (cp && targetSeconds !== null
                && (arrival > targetSeconds || (arrival === targetSeconds && distance < finishM))) {
                row.errors.push('after_finish');
            }
            row.errors.forEach(code => errors.push({ checkpointId: row.checkpointId, code }));

            refId = row.checkpointId;
            refDistance = distance;
            refDeparture = departure;
        }

        rows.push(row);
    }

    let moving = null;
    if (targetSeconds !== null) {
        if (totalStops >= targetSeconds) errors.push({ checkpointId: null, code: 'stops_exceed_target' });
        else moving = targetSeconds - totalStops;
    }

    // Écart de chaque allure de tronçon avec l'allure moyenne en mouvement (connue seulement ici)
    if (moving !== null && finishM > 0) {
        rows.forEach((row) => {
            if (row.segmentPaceSecPerKm !== null) {
                row.paceDeviationPct = deviationPct(row.segmentMovingSeconds, row.paceSpanDistanceM, moving, finishM);
            }
        });
    }

    const finish = rows.pop();
    return {
        rows,
        finish,
        totals: {
            distanceM: finishM,
            elapsedSeconds: targetSeconds,
            movingSeconds: moving,
            stopSeconds: totalStops,
            avgPaceSecPerKm: moving !== null && finishM > 0 ? round2(moving / (finishM / 1000)) : null,
            complete,
        },
        errors,
    };
}

/**
 * Niveau de teinte de chaque tronçon de la bande (0 = sans allure, 1 = le plus lent ... 5 = le plus rapide) :
 * 1 + floor(t * 4 + 0.5), t = (max - allure) / (max - min), 0,5 si toutes les allures sont égales. Même formule que la
 * macro strip de _scenarios.html.twig (affichage seulement, pas une règle de calcul des temps).
 *
 * @param {Array} rows  lignes de compute() (repères puis arrivée)
 * @returns {number[]}
 */
export function stripLevels(rows) {
    const paces = rows.map(r => r.segmentPaceSecPerKm).filter(p => p !== null);
    if (paces.length === 0) return rows.map(() => 0);
    const min = Math.min(...paces);
    const max = Math.max(...paces);
    return rows.map((r) => {
        if (r.segmentPaceSecPerKm === null) return 0;
        const t = max === min ? 0.5 : (max - r.segmentPaceSecPerKm) / (max - min);
        return 1 + Math.floor(t * 4 + 0.5);
    });
}

const STRIP_LABEL_GAP_PX = 8;

// Remplace les placeholders %x% d'un libellé traduit
const fill =(label, params) => Object.entries(params).reduce((s, [k, v]) => s.split(k).join(String(v)), String(label ?? ''));

function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
}

export default class extends Controller {
    static targets = ['tab', 'panel'];
    static values = {
        checkpoints: Array,
        routeDistance: Number,
        efforts: Array,
        locale: { type: String, default: 'fr' },
        labels: Object,
        tightMargin: { type: Number, default: 900 },
    };

    connect() {
        this.submitting = false;
        this.labels = this.labelsValue || {};
        // Mêmes séparateurs que les templates (_dec / _th de _scenarios.html.twig)
        const fr = this.localeValue === 'fr';
        this.format1 = (v) => numberFormat(v, 1, fr ? ',' : '.', fr ? ' ' : ',');
        this.names = new Map((this.checkpointsValue || []).map(c => [c.id, c.name]));

        // Stratégie restaurée par le navigateur (formulaires de scénario et de création) : intensité et explication
        this.element.querySelectorAll('form').forEach(form => this.refreshStrategy(form));

        // Valeurs restaurées par le navigateur (retour arrière, rechargement) : on recalcule
        this.scenarioForms().forEach((form) => {
            const splits = Array.from(form.querySelectorAll('[data-sc-split]'));
            // Un temps fixé restauré vide repasse en auto
            splits.forEach((input) => { if (this.isPinned(input) && phpTrim(input.value) === '') this.setPinned(input, false); });
            const autosChanged = splits.some(input => !this.isPinned(input) && input.value !== input.defaultValue);
            if (!this.isDirty(form) && !autosChanged) return;
            const target = form.querySelector('[data-sc-target]');
            const finish = form.querySelector('[data-sc-finish-target]');
            if (target && finish) finish.value = target.value;
            form.querySelectorAll('[data-sc-duration]').forEach(input => this.validateInput(input));
            form.querySelectorAll('[data-sc-name]').forEach(input => this.validateName(input));
            this.recompute(form);
            this.refreshDirty(form);
        });

        this.onBeforeUnload = (e) => {
            if (this.submitting || !this.scenarioForms().some(f => this.isDirty(f))) return;
            e.preventDefault();
            e.returnValue = '';
        };
        this.onPageShow = () => { this.submitting = false; };
        this.onHashChange = () => this.activateFromHash(true);
        window.addEventListener('beforeunload', this.onBeforeUnload);
        window.addEventListener('pageshow', this.onPageShow);
        window.addEventListener('hashchange', this.onHashChange);

        this.addModal = this.element.querySelector('#scenarioAddModal');
        this.onModalShown = () => this.addModal.querySelector('[data-sc-name]')?.focus();
        this.addModal?.addEventListener('shown.bs.modal', this.onModalShown);

        // Bandes des tronçons : libellés recalés quand la bande prend sa taille (onglet affiché) ou en change
        const strips = Array.from(this.element.querySelectorAll('[data-sc-strip]'));
        if (typeof ResizeObserver !== 'undefined') {
            this.stripObserver = new ResizeObserver(entries => entries.forEach(entry => this.layoutStrip(entry.target)));
            strips.forEach(strip => this.stripObserver.observe(strip));
        } else {
            strips.forEach(strip => this.layoutStrip(strip));
        }

        const hash = window.location.hash;
        const moved = (hash === '#scenarios' || SCENARIO_HASH.test(hash)) && this.moveFlashes();
        this.activateFromHash(false);
        // Le navigateur ne peut pas défiler jusqu'à un panneau masqué, et déplacer les messages décale la section
        if (moved || SCENARIO_HASH.test(hash)) this.scrollToSection();
    }

    /**
     * Messages flash du haut de page déplacés en tête de la section (sinon hors de l'écran après la redirection
     * ancrée sur un scénario). Renvoie true si au moins un message a été déplacé.
     */
    moveFlashes() {
        const slot = this.element.querySelector('[data-sc-flash]');
        const alerts = Array.from(document.querySelectorAll('.sp-content > .alert'));
        if (!slot || alerts.length === 0) return false;
        slot.append(...alerts);
        return true;
    }

    /**
     * Haut de la section à l'écran. Jusqu'à la fin du chargement, le navigateur réapplique son propre défilement vers
     * l'ancre (#scenario-{id} = le panneau, plus bas : borné par la hauteur de la page, il cachait les messages) et
     * l'emporte sur un premier défilement : on se recale aussi une fois la page chargée.
     */
    scrollToSection() {
        const scroll = () => requestAnimationFrame(() => this.element.querySelector('#scenarios')?.scrollIntoView({ block: 'start' }));
        scroll();
        if (document.readyState !== 'complete') {
            if (this.onLoadScroll) window.removeEventListener('load', this.onLoadScroll);
            this.onLoadScroll = scroll;
            window.addEventListener('load', scroll, { once: true });
        }
    }

    disconnect() {
        this.stripObserver?.disconnect();
        if (this.onLoadScroll) window.removeEventListener('load', this.onLoadScroll);
        window.removeEventListener('beforeunload', this.onBeforeUnload);
        window.removeEventListener('pageshow', this.onPageShow);
        window.removeEventListener('hashchange', this.onHashChange);
        this.addModal?.removeEventListener('shown.bs.modal', this.onModalShown);
    }

    // ── Onglets ─────────────────────────────────────────────────────────────

    activateFromHash(scroll) {
        const m = SCENARIO_HASH.exec(window.location.hash);
        if (!m || !this.panelTargets.some(p => p.dataset.scenarioId === m[1])) return;
        this.activate(m[1]);
        // Le panneau ciblé était peut-être masqué : le navigateur n'a pas pu y défiler
        if (scroll) this.scrollToSection();
    }

    activate(id, focus = false) {
        let current = null;
        this.tabTargets.forEach((tab) => {
            const selected = tab.dataset.scenarioId === id;
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
            tab.tabIndex = selected ? 0 : -1;
            if (selected) current = tab;
        });
        this.panelTargets.forEach((panel) => { panel.hidden = panel.dataset.scenarioId !== id; });
        if (focus && current) current.focus();
        if (current) current.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        // Section Nutrition (race_nutrition_controller.js) : elle affiche le même scénario
        if (current) this.dispatch('select', { detail: { id } });
    }

    selectTab(event) {
        const id = event.currentTarget.dataset.scenarioId;
        this.activate(id);
        this.replaceHash(id);
    }

    tabKeydown(event) {
        const tabs = this.tabTargets;
        const index = tabs.indexOf(event.currentTarget);
        let next = null;
        switch (event.key) {
            case 'ArrowRight': next = (index + 1) % tabs.length; break;
            case 'ArrowLeft': next = (index - 1 + tabs.length) % tabs.length; break;
            case 'Home': next = 0; break;
            case 'End': next = tabs.length - 1; break;
            default: return;
        }
        event.preventDefault();
        const id = tabs[next].dataset.scenarioId;
        this.activate(id, true);
        this.replaceHash(id);
    }

    replaceHash(id) {
        try {
            history.replaceState(history.state, '', `#scenario-${id}`);
        } catch {
            // URL non modifiable (iframe sandbox…) : l'onglet reste actif
        }
    }

    // ── Saisie ──────────────────────────────────────────────────────────────

    /**
     * Formulaires de création (état vide, modale) : validation des durées (le nom y est facultatif).
     * Événements input (erreur cachée tant que la saisie est en cours) et change (erreur visible).
     */
    validate(event) {
        if (event.target.matches?.('[data-sc-strategy], [data-sc-intensity]')) this.refreshStrategy(event.currentTarget);
        const reveal = event.type !== 'input';
        if (event.target.matches?.('[data-sc-duration]')) this.validateInput(event.target, reveal);
        if (event.target.matches?.('[data-sc-name]')) this.validateName(event.target, reveal);
    }

    /**
     * Formulaire d'un scénario : synchronisation du temps visé, temps fixé / auto, validation, recalcul,
     * état "modifié". Événements input (frappe) et change (fin de saisie).
     */
    update(event) {
        const form = event.currentTarget;
        const input = event.target;
        const typing = event.type === 'input';

        if (input.matches('[data-sc-target]') || input.matches('[data-sc-finish-target]')) {
            const twin = form.querySelector(input.matches('[data-sc-target]') ? '[data-sc-finish-target]' : '[data-sc-target]');
            if (twin && twin.value !== input.value) twin.value = input.value;
            if (twin) this.validateInput(twin, !typing);
        }
        // Saisir un temps le fixe ; le vider le repasse en auto
        if (input.matches('[data-sc-split]')) this.setPinned(input, phpTrim(input.value) !== '');
        if (input.matches('[data-sc-duration]')) this.validateInput(input, !typing);
        if (input.matches('[data-sc-name]')) {
            this.updateTabName(form, input);
            this.validateName(input, !typing);
        }
        // Stratégie ou intensité : explication et intensité à jour, puis recalcul des temps auto ci-dessous
        if (input.matches('[data-sc-strategy], [data-sc-intensity]')) this.refreshStrategy(form);

        // Durée incomplète en cours de frappe ("2", "2:", "2:0") : pas de recalcul, le tableau garde le résultat
        // précédent (ni temps auto vidés, ni lignes qui sautent). Pendant la frappe, un champ que l'on vient de
        // vider n'est pas re-rempli sous le curseur (il le sera à la fin de la saisie, événement change).
        const partial = typing && input.matches('[data-sc-duration]') && !parseDuration(input.value).ok;
        if (!partial) this.recompute(form, typing ? input : null);
        this.refreshDirty(form);
    }

    /**
     * Envoi refusé par le navigateur (événement invalid, capturé sur le formulaire) : l'erreur du champ devient
     * visible, même si la saisie n'a pas été terminée (touche Entrée).
     */
    reveal(event) {
        const input = event.target;
        if (input.matches?.('[data-sc-name]')) this.validateName(input, true);
        if (!input.matches?.('[data-sc-duration]')) return;
        this.validateInput(input, true);
        const form = input.closest('form[data-sc-form]');
        if (form) this.recompute(form); // message de la ligne du tableau
    }

    /**
     * Lien "Imprimer" : la fiche est construite à partir de la version ENREGISTRÉE du scénario. Avec des
     * modifications en cours, confirmation (Annuler laisse le temps d'enregistrer).
     */
    printGuard(event) {
        const form = event.currentTarget.closest('form[data-sc-form]');
        if (form && this.isDirty(form) && !window.confirm(this.labels.confirmPrintDirty)) event.preventDefault();
    }

    /**
     * Un temps auto est une proposition : à la prise de focus (clic compris), tout son texte est sélectionné pour
     * que la saisie le remplace au lieu de s'insérer au milieu (comme la touche Tab le fait déjà).
     */
    selectAuto(event) {
        const input = event.target;
        if (!input.matches?.('[data-sc-split]') || this.isPinned(input)) return;
        // Après le traitement du clic (qui place le curseur)
        setTimeout(() => {
            if (document.activeElement === input && !this.isPinned(input)) input.select();
        }, 0);
    }

    /** Bouton épingle d'un temps fixé : le repasse en auto (temps recalculé). */
    unpin(event) {
        const button = event.currentTarget;
        const form = button.closest('form[data-sc-form]');
        const input = form?.querySelector(`[data-sc-split="${button.dataset.scUnpin}"]`);
        if (!form || !input) return;
        this.setPinned(input, false);
        this.recompute(form);
        this.refreshDirty(form);
        input.focus(); // le bouton disparaît : le focus reste sur la ligne
    }

    isPinned(input) {
        return input.dataset.scState === 'pinned';
    }

    /** Bascule un input de temps entre "fixé" (posté) et "auto" (affiché, jamais posté). */
    setPinned(input, pinned) {
        const id = input.dataset.scSplit;
        input.dataset.scState = pinned ? 'pinned' : 'auto';
        if (pinned) input.setAttribute('name', `splits[${id}]`);
        else input.removeAttribute('name');
        input.classList.toggle('rp-sc-time--auto', !pinned);
        input.setAttribute('title', (pinned ? this.labels.pinnedTitle : this.labels.autoTitle) ?? '');

        const wrap = input.closest('.rp-sc-time-wrap');
        const unpin = wrap?.querySelector('[data-sc-unpin]');
        if (unpin) unpin.hidden = !pinned;
        const badge = wrap?.querySelector('[data-sc-auto-badge]');
        if (badge) badge.hidden = pinned;
        const state = wrap?.querySelector('[data-sc-state-text]');
        if (state) state.textContent = (pinned ? this.labels.statePinned : this.labels.stateAuto) ?? '';
        input.closest('tr')?.classList.toggle('rp-sc-row--pinned', pinned);
    }

    /** Stratégie et intensité choisies dans un formulaire (valeurs inconnues : régulier / moyenne). */
    readStrategy(form) {
        const strategy = form.querySelector('[data-sc-strategy]')?.value;
        const intensity = form.querySelector('[data-sc-intensity]:checked')?.value;
        return {
            strategy: PACING_STRATEGIES.includes(strategy) ? strategy : 'even',
            intensity: PACING_INTENSITIES.includes(intensity) ? intensity : 'medium',
        };
    }

    /**
     * Bloc stratégie d'un formulaire : intensité masquée pour "Régulier" (sans effet ; elle reste postée pour être
     * retrouvée), phrase d'explication de la stratégie choisie.
     */
    refreshStrategy(form) {
        if (!form.querySelector('[data-sc-strategy]')) return;
        const { strategy } = this.readStrategy(form);
        const group = form.querySelector('[data-sc-intensity-group]');
        if (group) group.hidden = strategy === 'even';
        // Zone aria-live : texte remplacé seulement s'il change (pas d'annonce au chargement)
        const desc = form.querySelector('[data-sc-strategy-desc]');
        const text = this.labels.strategyDesc?.[strategy] ?? '';
        if (desc && desc.textContent !== text) desc.textContent = text;
    }

    /** Message d'erreur de format d'une durée (null si valide). */
    durationError(input) {
        const parsed = parseDuration(input.value);
        if (!parsed.ok) return this.labels.errInvalid;
        const isTarget = input.matches('[data-sc-target], [data-sc-finish-target]');
        if (isTarget && parsed.seconds === 0) return this.labels.errZero;
        return null;
    }

    /**
     * Validité d'une durée. setCustomValidity toujours à jour (l'envoi reste bloqué) ; erreur visible seulement
     * si reveal (fin de saisie, envoi refusé), mais effacée dès que la valeur redevient valide.
     */
    validateInput(input, reveal = true) {
        const error = this.durationError(input);
        input.setCustomValidity(error ?? '');
        if (!error || reveal) this.showError(input, error);
    }

    /** Nom obligatoire d'un scénario (champ required) : vide ou seulement des espaces, refusé comme par le serveur. */
    validateName(input, reveal = true) {
        if (!input.required) return;
        const error = input.value.trim() === '' ? this.labels.errNameBlank : null;
        input.setCustomValidity(error ?? '');
        if (!error || reveal) this.showError(input, error);
    }

    showError(input, error) {
        input.classList.toggle('rp-sc-input--invalid', !!error);
        input.setAttribute('aria-invalid', error ? 'true' : 'false');
        // Champs de l'en-tête (nom, temps visé) : message sous le champ (les lignes du tableau sont gérées par recompute)
        const slot = input.closest('.rp-sc-field')?.querySelector('[data-sc-field-error]');
        if (slot) slot.textContent = error ?? '';
    }

    updateTabName(form, input) {
        const id = form.closest('[data-race-splits-target="panel"]')?.dataset.scenarioId;
        const tab = this.tabTargets.find(t => t.dataset.scenarioId === id);
        const name = tab?.querySelector('[data-sc-tab-name]');
        if (name) name.textContent = phpTrim(input.value) || input.defaultValue;
    }

    // ── Calcul + rendu ──────────────────────────────────────────────────────

    /**
     * Recalcule les temps auto (fillAuto) puis tout le tableau (compute).
     * @param {HTMLFormElement} form
     * @param {?HTMLInputElement} editing  input en cours de frappe : s'il est auto et vide, il n'est pas re-rempli
     */
    recompute(form, editing = null) {
        const targetInput = form.querySelector('[data-sc-target]');
        const parsedTarget = parseDuration(targetInput?.value ?? '');
        const target = parsedTarget.ok && parsedTarget.seconds !== 0 ? parsedTarget.seconds : null;
        const start = parseStart(form.querySelector('[data-sc-start]')?.value ?? '');
        const checkpoints = this.checkpointsValue || [];
        const routeDistance = this.routeDistanceValue || 0;

        // Temps fixés valides = ancres ; un temps fixé invalide n'est pas une ancre et n'a pas de temps
        const pins = new Map();
        const invalid = new Map();
        const splitInputs = Array.from(form.querySelectorAll('[data-sc-split]'));
        splitInputs.forEach((input) => {
            if (!this.isPinned(input)) return;
            const id = Number(input.dataset.scSplit);
            const parsed = parseDuration(input.value);
            if (!parsed.ok) invalid.set(id, this.labels.errInvalid);
            else if (parsed.seconds !== null) pins.set(id, parsed.seconds);
        });

        let efforts = this.effortsValue || [];
        if (efforts.length !== checkpoints.length + 1) efforts = new Array(checkpoints.length + 1).fill(0); // garde-fou
        const { strategy, intensity } = this.readStrategy(form);
        const filled = fillAuto(checkpoints, routeDistance, efforts, pins, target, strategy, intensity);
        const arrivals = filled.arrivals;
        const pinnedMap = filled.pinned;
        invalid.forEach((_, id) => { arrivals.set(id, null); pinnedMap.set(id, true); });

        // Affichage des temps auto dans leurs inputs (jamais postés : pas de name)
        splitInputs.forEach((input) => {
            if (this.isPinned(input)) return;
            if (input === editing && phpTrim(input.value) === '') return;
            const seconds = arrivals.get(Number(input.dataset.scSplit)) ?? null;
            input.value = seconds !== null ? formatInput(seconds) : '';
            this.validateInput(input);
        });

        const result = compute(checkpoints, routeDistance, arrivals, target, start, this.tightMarginValue, pinnedMap);

        const byId = new Map(result.rows.map(r => [r.checkpointId, r]));
        form.querySelectorAll('tr[data-sc-row="checkpoint"]').forEach((tr) => {
            const id = Number(tr.dataset.cpId);
            const row = byId.get(id);
            if (row) this.renderRow(tr, row, invalid.get(id) ?? null, result.totals.avgPaceSecPerKm);
        });
        const finishTr = form.querySelector('tr[data-sc-row="finish"]');
        if (finishTr) {
            const finishInput = finishTr.querySelector('[data-sc-finish-target]');
            this.renderRow(finishTr, result.finish, finishInput ? this.durationError(finishInput) : null, result.totals.avgPaceSecPerKm);
        }
        const startClock = form.querySelector('tr[data-sc-row="start"] [data-sc="clock"]');
        if (startClock) {
            startClock.replaceChildren(start !== null
                ? el('span', 'rp-sc-clock', formatClock(start, 0).time)
                : el('span', 'rp-empty', '-'));
        }

        this.renderSummary(form, result, target);
        this.renderTabTarget(form, target);
        this.renderStrip(form, result, start);
    }

    /**
     * Bande des tronçons du scénario (même rendu que la macro strip) : allure et teinte de chaque tronçon, heure de
     * passage à chaque repère. Données de compute() uniquement.
     */
    renderStrip(form, result, start) {
        const strip = form.querySelector('[data-sc-strip]');
        if (!strip) return;
        const rows = [...result.rows, result.finish];
        const levels = stripLevels(rows);
        rows.forEach((row, i) => {
            const key = row.checkpointId === null ? 'finish' : String(row.checkpointId);
            const seg = strip.querySelector(`[data-strip-seg="${key}"]`);
            if (seg) {
                seg.classList.remove('rp-strip-seg--0', 'rp-strip-seg--1', 'rp-strip-seg--2', 'rp-strip-seg--3', 'rp-strip-seg--4', 'rp-strip-seg--5');
                seg.classList.add(`rp-strip-seg--${levels[i]}`);
                const pace = seg.querySelector('[data-strip-pace]');
                if (pace) pace.textContent = row.segmentPaceSecPerKm !== null ? formatPace(row.segmentPaceSecPerKm) : '-';
            }
            const clock = strip.querySelector(`[data-strip-label="${key}"] [data-strip-clock]`);
            if (clock) clock.textContent = this.stripClock(row);
        });
        const startClock = strip.querySelector('[data-strip-label="start"] [data-strip-clock]');
        if (startClock) startClock.textContent = start !== null ? formatClock(start, 0).time : '0:00';
        this.layoutStrip(strip);
    }

    /** Heure d'un repère sur la bande (macro strip_clock) : heure (+ J+n), sinon temps écoulé, sinon "-". */
    stripClock(row) {
        if (row.clock) {
            return row.clock.dayOffset > 0 ? `${row.clock.time} ${fill(this.labels.day, { '%n%': row.clock.dayOffset })}` : row.clock.time;
        }
        return row.arrivalSeconds !== null ? formatDuration(row.arrivalSeconds) : '-';
    }

    /**
     * Libellés de la bande : départ et arrivée toujours visibles, un repère masqué (visibility) s'il chevauche le libellé
     * visible précédent ou celui de l'arrivée ; texte d'un tronçon trop étroit masqué. Rien à faire si la bande est
     * masquée (onglet inactif) : le ResizeObserver relance la mise en page quand elle s'affiche.
     */
    layoutStrip(strip) {
        if (!strip || strip.offsetWidth === 0) return;
        const labels = Array.from(strip.querySelectorAll('[data-strip-label]'));
        labels.forEach(label => label.classList.remove('is-hidden'));
        const rects = labels.map(label => label.getBoundingClientRect());
        const last = labels.length - 1;
        let right = labels.length ? rects[0].right : 0;
        for (let i = 1; i < last; i++) {
            if (rects[i].left < right + STRIP_LABEL_GAP_PX || rects[i].right > rects[last].left - STRIP_LABEL_GAP_PX) {
                labels[i].classList.add('is-hidden');
            } else {
                right = rects[i].right;
            }
        }
        strip.querySelectorAll('[data-strip-seg]').forEach((seg) => {
            const inner = seg.firstElementChild;
            if (inner) seg.classList.toggle('is-narrow', inner.scrollWidth > inner.clientWidth + 1);
        });
    }

    /**
     * @param {HTMLTableRowElement} tr
     * @param {object} row             ligne de compute()
     * @param {?string} invalidMessage
     * @param {?number} avgPace        allure moyenne en mouvement du scénario (titre de l'écart d'allure)
     */
    renderRow(tr, row, invalidMessage, avgPace = null) {
        tr.classList.toggle('rp-sc-row--error', row.errors.length > 0);

        const errorCell = tr.querySelector('[data-sc="error"]');
        if (errorCell) {
            const messages = [];
            if (invalidMessage) messages.push(invalidMessage);
            row.errors.forEach((code) => {
                if (code === 'not_increasing') messages.push(this.labels.errNotIncreasing);
                else if (code === 'after_finish') messages.push(this.labels.errAfterFinish);
            });
            errorCell.replaceChildren(...messages.map(m => el('span', '', m)));
        }

        const clock = tr.querySelector('[data-sc="clock"]');
        if (clock) this.renderClock(clock, row.clock);

        const dep = tr.querySelector('[data-sc="dep"]');
        if (dep) dep.textContent = row.departureClock ? fill(this.labels.departure, { '%time%': row.departureClock.time }) : '';

        const pace = tr.querySelector('[data-sc="pace"]');
        if (pace) {
            if (row.segmentPaceSecPerKm !== null) {
                const from = row.paceFromCheckpointId === null ? this.labels.start : (this.names.get(row.paceFromCheckpointId) ?? '');
                const main = el('span', 'rp-sc-pace', formatPace(row.segmentPaceSecPerKm));
                main.append(el('small', '', ` ${this.labels.perKm}`));
                main.setAttribute('title', fill(this.labels.paceTitle, {
                    '%time%': row.segmentMovingSeconds !== null ? formatDuration(row.segmentMovingSeconds) : '',
                    '%km%': this.format1(row.paceSpanDistanceM / 1000),
                    '%from%': from,
                }));
                const speed = el('span', 'rp-sc-sub', fill(this.labels.speed, { '%v%': this.format1(row.segmentSpeedKmh) }));
                if (row.paceDeviationPct !== null) speed.append(' ', this.deviationBadge(row.paceDeviationPct, avgPace));
                pace.replaceChildren(main, speed);
            } else {
                pace.replaceChildren(el('span', 'rp-empty', '-'));
            }
        }

        const avg = tr.querySelector('[data-sc="avg"]');
        if (avg) {
            if (row.avgPaceSecPerKm !== null) {
                avg.replaceChildren(document.createTextNode(formatPace(row.avgPaceSecPerKm)), el('small', '', ` ${this.labels.perKm}`));
            } else {
                avg.replaceChildren(el('span', 'rp-empty', '-'));
            }
        }

        const margin = tr.querySelector('[data-sc="margin"]');
        if (margin) {
            const status = row.cutoffStatus;
            margin.className = `rp-sc-margin rp-sc-margin--${status}`;
            if (status === 'none') {
                margin.textContent = '';
                margin.removeAttribute('title');
            } else {
                const key = { ok: 'marginOk', tight: 'marginTight', missed: 'marginMissed' }[status];
                const statusKey = { ok: 'statusOk', tight: 'statusTight', missed: 'statusMissed' }[status];
                margin.textContent = fill(this.labels[key], { '%m%': formatDuration(Math.abs(row.cutoffMarginSeconds)) });
                margin.setAttribute('title', this.labels[statusKey] ?? '');
            }
        }
    }

    /** Écart d'allure "+3 %" / "-2 %" / "0 %" (même rendu que la macro dev de _scenarios.html.twig). */
    deviationBadge(pct, avgPace) {
        const kind = pct > 0 ? 'slow' : (pct < 0 ? 'fast' : 'even');
        const badge = el('span', `rp-sc-dev rp-sc-dev--${kind}`, fill(this.labels.devValue, { '%v%': pct > 0 ? `+${pct}` : String(pct) }));
        badge.setAttribute('title', fill(this.labels.devTitle, { '%pace%': avgPace !== null ? formatPace(avgPace) : '' }));
        return badge;
    }

    renderClock(container, clock) {
        if (!clock) {
            container.replaceChildren(el('span', 'rp-empty', '-'));
            return;
        }
        const nodes = [el('span', 'rp-sc-clock', clock.time)];
        if (clock.dayOffset > 0) {
            const day = el('span', 'rp-sc-day', fill(this.labels.day, { '%n%': clock.dayOffset }));
            day.setAttribute('title', fill(this.labels.dayTitle, { '%n%': clock.dayOffset }));
            nodes.push(document.createTextNode(' '), day);
        }
        container.replaceChildren(...nodes);
    }

    renderSummary(form, result, target) {
        const sum = (key) => form.querySelector(`[data-sc-sum="${key}"]`);
        const t = result.totals;

        const targetEl = sum('target');
        if (targetEl) targetEl.textContent = target !== null ? formatInput(target) : '-';

        const arrival = sum('arrival');
        if (arrival) this.renderClock(arrival, result.finish.clock);

        const pace = sum('pace');
        if (pace) {
            if (t.avgPaceSecPerKm !== null) {
                pace.replaceChildren(document.createTextNode(formatPace(t.avgPaceSecPerKm)), el('small', '', ` ${this.labels.perKm}`));
            } else {
                pace.textContent = '-';
            }
        }

        const stops = sum('stops');
        if (stops) {
            stops.textContent = t.stopSeconds < 3600
                ? fill(this.labels.minutes, { '%n%': Math.floor(t.stopSeconds / 60) })
                : formatDuration(t.stopSeconds);
        }

        const cutoffs = sum('cutoffs');
        if (cutoffs) {
            const counts = { ok: 0, tight: 0, missed: 0 };
            result.rows.forEach((r) => { if (r.cutoffStatus !== 'none') counts[r.cutoffStatus]++; });
            const labelKeys = { ok: 'cutoffOk', tight: 'cutoffTight', missed: 'cutoffMissed' };
            const pills = ['ok', 'tight', 'missed']
                .filter(s => counts[s] > 0)
                .map(s => el('span', `rp-sc-pill rp-sc-pill--${s}`, fill(this.labels[labelKeys[s]], { '%n%': counts[s] })));
            cutoffs.replaceChildren(...(pills.length ? pills : [el('span', 'rp-empty', this.labels.cutoffsNone)]));
        }

        const pinned = sum('pinned');
        if (pinned) pinned.textContent = `${result.rows.filter(r => r.pinned).length}/${result.rows.length}`;

        const stopsAlert = form.querySelector('[data-sc-alert="stops"]');
        if (stopsAlert) stopsAlert.hidden = !result.errors.some(e => e.code === 'stops_exceed_target');

        const errorsAlert = form.querySelector('[data-sc-alert="errors"]');
        if (errorsAlert) {
            const count = result.errors.filter(e => e.code !== 'stops_exceed_target').length;
            errorsAlert.hidden = count === 0;
            const text = errorsAlert.querySelector('[data-sc-alert-text]');
            if (text) text.textContent = fill(count === 1 ? this.labels.errorsOne : this.labels.errorsMany, { '%count%': count });
        }
    }

    renderTabTarget(form, target) {
        const id = form.closest('[data-race-splits-target="panel"]')?.dataset.scenarioId;
        const badge = this.tabTargets.find(t => t.dataset.scenarioId === id)?.querySelector('[data-sc-tab-target]');
        if (!badge) return;
        badge.textContent = target !== null ? formatInput(target) : '';
        badge.hidden = target === null;
    }

    // ── Modifications non enregistrées ──────────────────────────────────────

    scenarioForms() {
        return Array.from(this.element.querySelectorAll('form[data-sc-form]'));
    }

    /**
     * Modifié = un champ (nom, heure, temps visé, stratégie, intensité) a changé, un repère a changé d'état
     * fixé / auto, ou un temps fixé a changé de valeur. La valeur affichée d'un auto (recalculée) ne compte pas.
     */
    isDirty(form) {
        const selectChanged = Array.from(form.querySelectorAll('select[name]')).some((select) => {
            const initial = Array.from(select.options).findIndex(o => o.defaultSelected);
            return select.selectedIndex !== (initial === -1 ? 0 : initial);
        });
        if (selectChanged) return true;
        return Array.from(form.querySelectorAll('input:not([type="hidden"])')).some((input) => {
            if (input.type === 'radio') return input.checked !== input.defaultChecked;
            if (input.matches('[data-sc-split]')) {
                if (input.dataset.scState !== input.dataset.scInitial) return true;
                return this.isPinned(input) && input.value !== input.defaultValue;
            }
            return (input.name || input.matches('[data-sc-finish-target]')) && input.value !== input.defaultValue;
        });
    }

    refreshDirty(form) {
        const dirty = this.isDirty(form);
        form.querySelectorAll('[data-sc-dirty]').forEach((node) => { node.hidden = !dirty; });
        const id = form.closest('[data-race-splits-target="panel"]')?.dataset.scenarioId;
        const dot = this.tabTargets.find(t => t.dataset.scenarioId === id)?.querySelector('[data-sc-tab-dirty]');
        if (dot) dot.hidden = !dirty;
    }

    /** Envoi d'un formulaire (enregistrer, créer) : prévient si un AUTRE scénario a des modifications. */
    submitted(event) {
        const form = event.currentTarget;
        const othersDirty = this.scenarioForms().some(f => f !== form && this.isDirty(f));
        if (othersDirty && !window.confirm(this.labels.confirmDirty)) {
            event.preventDefault();
            return;
        }
        this.submitting = true;
    }

    /** Répartir / dupliquer / supprimer : confirmations avant envoi. */
    confirmAction(event) {
        const form = event.currentTarget;
        const panel = form.closest('[data-race-splits-target="panel"]');
        const own = panel?.querySelector('form[data-sc-form]');
        const ownDirty = own ? this.isDirty(own) : false;
        const othersDirty = this.scenarioForms().some(f => f !== own && this.isDirty(f));

        const questions = [];
        switch (form.dataset.scAction) {
            case 'distribute':
                // Sans modification en cours, les temps fixés affichés sont ceux enregistrés (effacés par "Répartir")
                if (ownDirty) questions.push(this.labels.confirmDistributeDirty);
                else if (own?.querySelector('[data-sc-split][data-sc-state="pinned"]')) questions.push(this.labels.confirmDistribute);
                if (othersDirty) questions.push(this.labels.confirmDirty);
                break;
            case 'duplicate':
                if (ownDirty || othersDirty) questions.push(this.labels.confirmDirty);
                break;
            case 'delete':
                // La question de data-confirm est déjà posée (et acceptée) par l'écouteur global d'app.js
                if (othersDirty) questions.push(this.labels.confirmDirty);
                break;
            default:
                break;
        }

        for (const question of questions) {
            if (question && !window.confirm(question)) {
                event.preventDefault();
                return;
            }
        }
        this.submitting = true;
    }
}
