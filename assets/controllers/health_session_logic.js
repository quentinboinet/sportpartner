/*
 * Logique pure de la séance guidée Santé (sans DOM ni horloge), utilisée par health_session_controller.js.
 *
 * Étapes d'une séance (buildSteps) : « Prépare-toi » (prep) avant le premier exercice, puis pour chaque exercice
 * et chaque série une étape de travail (work) par côté (gauche puis droite pour un exercice « chaque côté », avec
 * une courte étape de changement de côté entre les deux, switch), et un repos (rest) après chaque série sauf la
 * toute dernière de la séance (repos de l'exercice qui se termine ; aucune étape si le repos vaut 0).
 * Une étape de repos, de changement de côté ou de préparation pointe vers ce qui suit (ex, set, side).
 *
 * Valeurs faites (performed) : une par série TERMINÉE ; pour un exercice « chaque côté », une série compte quand
 * les deux côtés sont faits, avec la valeur du côté le moins long (même règle que HealthSessionExercise).
 */

/** Durée estimée d'une répétition, pour l'estimation de la durée de la séance (en secondes). */
export const REP_SECONDS = 3;

const STRENGTH_WORK = ['strength', 'core', 'balance'];

export function buildSteps(plan, { prep = 0, switchSeconds = 0 } = {}) {
    const steps = [];
    if (plan.length === 0) return steps;
    const firstSide = (item) => (item.unilateral ? 'left' : null);
    if (prep > 0) steps.push({ kind: 'prep', seconds: prep, ex: 0, set: 1, side: firstSide(plan[0]) });

    plan.forEach((item, ex) => {
        const sides = item.unilateral ? ['left', 'right'] : [null];
        for (let set = 1; set <= item.sets; set++) {
            sides.forEach((side, s) => {
                steps.push({ kind: 'work', ex, set, side, mode: item.mode, value: item.value });
                if (s < sides.length - 1) {
                    if (switchSeconds > 0) steps.push({ kind: 'switch', seconds: switchSeconds, ex, set, side: sides[s + 1] });
                } else if (set < item.sets) {
                    if (item.rest > 0) steps.push({ kind: 'rest', seconds: item.rest, ex, set: set + 1, side: firstSide(item) });
                } else if (ex < plan.length - 1 && item.rest > 0) {
                    steps.push({ kind: 'rest', seconds: item.rest, ex: ex + 1, set: 1, side: firstSide(plan[ex + 1]) });
                }
            });
        }
    });

    return steps;
}

/** L'étape a un compte à rebours (tout sauf une série en répétitions, terminée par « Fait »). */
export function hasCountdown(step) {
    return step.kind !== 'work' || step.mode === 'time';
}

/** Durée initiale du compte à rebours d'une étape, en ms (0 pour une série en répétitions). */
export function stepDurationMs(step) {
    if (step.kind === 'work') return step.mode === 'time' ? step.value * 1000 : 0;
    return step.seconds * 1000;
}

export function workCount(steps) {
    return steps.filter((step) => step.kind === 'work').length;
}

/** Séries (étapes de travail) déjà passées avant l'étape index : sert à la barre de progression. */
export function workBefore(steps, index) {
    return steps.slice(0, index).filter((step) => step.kind === 'work').length;
}

/**
 * « Précédent » : la dernière étape de travail avant index (la série qu'on vient de finir pendant un repos),
 * sinon index lui-même (recommencer l'étape en cours).
 */
export function previousWorkIndex(steps, index) {
    for (let i = index - 1; i >= 0; i--) {
        if (steps[i].kind === 'work') return i;
    }
    return index;
}

/** Valeurs faites par exercice (records : {indexDeLEtape: valeur}). */
export function performed(plan, steps, records) {
    const bySet = new Map();
    steps.forEach((step, i) => {
        const value = records[i];
        if (step.kind !== 'work' || !Number.isInteger(value) || value < 1) return;
        const key = `${step.ex}:${step.set}`;
        const entry = bySet.get(key) || {};
        entry[step.side || 'both'] = value;
        bySet.set(key, entry);
    });

    return plan.map((item, ex) => {
        const out = [];
        for (let set = 1; set <= item.sets; set++) {
            const entry = bySet.get(`${ex}:${set}`);
            if (!entry) continue;
            if (item.unilateral) {
                if (entry.left >= 1 && entry.right >= 1) out.push(Math.min(entry.left, entry.right));
            } else if (entry.both >= 1) {
                out.push(entry.both);
            }
        }
        return out;
    });
}

function counts(categories) {
    const strength = categories.filter((c) => STRENGTH_WORK.includes(c)).length;
    return [strength, categories.length - strength];
}

/** Miroir de HealthActivityMapper::sportSlug() sans choix : sport proposé pour l'activité. */
export function sportFor(categories) {
    const [strength, mobility] = counts(categories);
    return strength >= mobility ? 'weight_training' : 'other';
}

/** Miroir de HealthActivityMapper::sessionKind() : nom par défaut de la séance. */
export function sessionKind(categories) {
    const [strength, mobility] = counts(categories);
    if (mobility === 0) return 'strength';
    if (strength === 0) return 'mobility';
    return 'mixed';
}

/** Durée estimée de la séance en secondes (une répétition = REP_SECONDS). */
export function estimateSeconds(plan, options) {
    return buildSteps(plan, options).reduce((total, step) => {
        if (step.kind !== 'work') return total + step.seconds;
        return total + (step.mode === 'time' ? step.value : step.value * REP_SECONDS);
    }, 0);
}

/** Entier borné ; null si la saisie n'est pas un entier. */
export function clampInt(raw, min, max) {
    const text = String(raw).trim();
    if (!/^\d{1,6}$/.test(text)) return null;
    return Math.min(max, Math.max(min, Number(text)));
}

/** « 0:45 », « 1:30 », « 12:05 » (secondes arrondies au-dessus pour un compte à rebours). */
export function clock(ms) {
    const total = Math.max(0, Math.ceil(ms / 1000));
    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
}
