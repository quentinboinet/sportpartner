import { Controller } from '@hotwired/stimulus';

/*
 * Formulaire d'une activité saisie à la main (templates/activities/form.html.twig, ManualActivityForm côté serveur,
 * qui reste la référence : tout est revalidé à l'envoi).
 *  - masque distance / D+ / aperçu selon le sport (renfo : ni distance ni D+ ; natation : pas de D+) ;
 *  - matériel : ne propose que les types compatibles avec le sport, met à jour l'option « Par défaut » (matériel
 *    par défaut du type rattaché au sport, comme la synchro Strava) et y revient si le choix devient incompatible ;
 *  - aperçu en direct de l'allure (min/km) ou, pour le vélo, de la vitesse (km/h), calculé avec les trois champs
 *    du temps en mouvement, signalé « inhabituel » hors des seuils d'ActivityPlausibilityRule (valeur
 *    plausibility, envoyée par le serveur) ;
 *  - nouvelle saisie : fuseau du navigateur dans le champ caché timezone et date du jour locale.
 * Aucun texte n'est inséré en HTML : textContent uniquement.
 */

const UNITS = [3600, 60, 1];

/**
 * Miroir de ManualActivityForm::duration() : trois champs [heures, minutes, secondes], vide = 0, entiers de 1 ou
 * 2 chiffres, heures <= hoursMax, minutes et secondes <= 59. Secondes, null si les trois sont vides, NaN si un
 * champ est invalide (les bornes du total, 1 s à hoursMax h, sont vérifiées par l'appelant).
 */
export function partsToSeconds(parts, hoursMax = 48) {
    const values = parts.map((p) => String(p ?? '').trim());
    if (values.every((v) => v === '')) return null;
    const max = [hoursMax, 59, 59];
    let total = 0;
    for (let i = 0; i < 3; i++) {
        if (values[i] === '') continue;
        if (!/^\d{1,2}$/.test(values[i]) || Number(values[i]) > max[i]) return NaN;
        total += Number(values[i]) * UNITS[i];
    }
    return total;
}

/** Miroir de la lecture de la distance de ManualActivityForm : mètres entiers, null si vide, NaN si invalide. */
export function parseDistanceMeters(value) {
    const v = String(value ?? '').trim();
    if (v === '') return null;
    const m = v.match(/^(\d{1,4})(?:[.,](\d{1,3}))?$/);
    return m ? Number(m[1]) * 1000 + Number((m[2] ?? '').padEnd(3, '0')) : NaN;
}

const MAX_METERS = 1000 * 1000;

/**
 * Allure (secondes par km, arrondie à la seconde comme Activity::getPaceMinPerKm()) ou vitesse (km/h à 0,1 près
 * comme Activity::getSpeedKmh()) ; null si la saisie ne permet pas le calcul.
 */
export function previewOf(sport, movingSeconds, meters, maxSeconds = 48 * 3600) {
    if (!Number.isFinite(movingSeconds) || !Number.isFinite(meters)) return null;
    if (movingSeconds <= 0 || movingSeconds > maxSeconds || meters <= 0 || meters > MAX_METERS) return null;
    if (sport === 'ride') {
        return { kind: 'speed', kmh: Math.floor(meters / movingSeconds * 3.6 * 10 + 0.5) / 10 };
    }
    return { kind: 'pace', seconds: Math.floor(movingSeconds / meters * 1000 + 0.5) };
}

/** Miroir d'ActivityPlausibilityRule::check() : true si l'allure ou la vitesse sort des seuils (bornes incluses). */
export function isImplausible(sport, movingSeconds, meters, rules) {
    if (!rules || !(meters > 0) || !(movingSeconds > 0)) return false;
    if ((rules.paceSports ?? []).includes(sport)) {
        return movingSeconds * 1000 > rules.slowestPace * meters || movingSeconds * 1000 < rules.fastestPace * meters;
    }
    return sport === 'ride' && meters * 36 > rules.rideMaxKmh * 10 * movingSeconds;
}

export function formatPace(seconds) {
    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
}

export default class extends Controller {
    static targets = ['sport', 'date', 'movingPart', 'distance', 'distanceField', 'elevationField',
        'previewBox', 'preview', 'gear', 'gearField', 'timezone'];

    static values = {
        compat: Object, defaults: Object, autoType: Object, noDistance: Array, noElevation: Array,
        fresh: Boolean, labels: Object, decimal: { type: String, default: ',' }, plausibility: Object,
        hoursMax: { type: Number, default: 48 },
    };

    connect() {
        if (this.hasTimezoneTarget && this.timezoneTarget.dataset.fromBrowser) {
            try {
                const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
                if (tz) this.timezoneTarget.value = tz;
            } catch {
                // fuseau du serveur conservé
            }
        }
        if (this.freshValue && this.hasDateTarget) {
            const d = new Date();
            this.dateTarget.value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        }
        this.applySport();
        this.refresh();
    }

    sportChanged() {
        this.applySport();
        this.refresh();
    }

    applySport() {
        const sport = this.sportTarget.value;
        const noDistance = this.noDistanceValue.includes(sport);
        if (this.hasDistanceFieldTarget) this.distanceFieldTarget.hidden = noDistance;
        if (this.hasPreviewBoxTarget) this.previewBoxTarget.hidden = noDistance;
        if (this.hasElevationFieldTarget) this.elevationFieldTarget.hidden = this.noElevationValue.includes(sport);
        if (this.hasGearTarget) this.applyGear(sport);
    }

    applyGear(sport) {
        const types = this.compatValue[sport] ?? [];
        const select = this.gearTarget;
        select.querySelectorAll('optgroup[data-type]').forEach((group) => {
            group.hidden = !types.includes(group.dataset.type);
        });
        select.querySelectorAll('option[data-type]').forEach((option) => {
            const ok = types.includes(option.dataset.type);
            option.hidden = !ok;
            option.disabled = !ok;
        });
        if (select.selectedOptions[0]?.disabled) select.value = 'auto';

        const auto = select.querySelector('option[data-auto]');
        const autoType = this.autoTypeValue[sport] ?? null;
        const name = autoType ? this.defaultsValue[autoType] : null;
        if (auto) {
            auto.textContent = name
                ? String(this.labelsValue.autoNamed ?? '').replace('%name%', name)
                : String(this.labelsValue.autoNone ?? '');
        }
        if (this.hasGearFieldTarget) this.gearFieldTarget.hidden = types.length === 0;
    }

    /** Temps en mouvement lu dans les trois champs (h, min, s), dans l'ordre du gabarit. */
    movingSeconds() {
        return partsToSeconds(this.movingPartTargets.map((input) => input.value), this.hoursMaxValue);
    }

    refresh() {
        const sport = this.sportTarget.value;
        if (!this.hasPreviewTarget || this.noDistanceValue.includes(sport)) return;

        const moving = this.movingSeconds();
        const meters = this.hasDistanceTarget ? parseDistanceMeters(this.distanceTarget.value) : null;
        const result = previewOf(sport, moving, meters, this.hoursMaxValue * 3600);
        let text;
        if (result === null) {
            text = String(this.labelsValue.empty ?? '');
        } else if (result.kind === 'speed') {
            const kmh = result.kmh.toFixed(1).replace('.', this.decimalValue);
            text = String(this.labelsValue.speed ?? '').replace('%value%', kmh);
        } else {
            text = String(this.labelsValue.pace ?? '').replace('%value%', formatPace(result.seconds));
        }
        const odd = result !== null && isImplausible(sport, moving, meters, this.plausibilityValue);
        if (odd) text += ` ${String(this.labelsValue.implausible ?? '')}`;
        this.previewTarget.textContent = text;
        if (this.hasPreviewBoxTarget) this.previewBoxTarget.classList.toggle('is-implausible', odd);
    }
}
