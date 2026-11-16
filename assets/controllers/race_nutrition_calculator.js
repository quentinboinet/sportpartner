/*
 * Miroir JS EXACT de App\Service\Nutrition\RaceNutritionCalculator (compute(), carryList(), status(),
 * lineHundredths()) : recalcul en direct de la section Nutrition du plan de course par race_nutrition_controller.js.
 * Ce n'est pas un controller Stimulus. Mêmes règles, mêmes opérations ENTIÈRES (centièmes de gramme, roundDiv() de
 * activity_fuel_calculator.js, exact tant que les numérateurs restent < 4,5e15, ce que garantissent les bornes des
 * réglages et des temps) ; toute règle modifiée côté PHP doit l'être ici aussi (et inversement). Le docblock de la
 * classe PHP décrit les règles ; la parité est vérifiée par un jeu de cas aléatoires (PHP contre ce fichier).
 *
 * Entrée (mêmes clés qu'en PHP) :
 *   { settings: { carbsPerHour, fluidPerHour, mixDrink, mixGel, mixSolid, solidEarly, gel, solid, drink },
 *     products: [{ key, kind: 'food'|'drink', cat: 'gel'|'solid'|'drink', tenths }],
 *     points: [{ anchor, type: 'start'|'point'|'aid_station', assistance }],   // départ puis repères
 *     segments: [{ anchor, seconds }],                                         // n + 1 tronçons
 *     manual: { 'p:start': [{ key, qty }], 's:123': [...], ... } }
 */
import { roundDiv } from './activity_fuel_calculator.js';

export const CAP_PCT = 150;
export const STATUS_TOLERANCE_PCT = 20;
export const STATUS_MIN_SECONDS = 1200;
export const FLUID_STEP_ML = 250;
export const SIGMA_SCALE = 1000000;
export const WATER_KEY = 'water';
const DRINK_REFERENCE_ML = 500;

const has = (object, key) => Object.prototype.hasOwnProperty.call(object, key);

/** intdiv() de PHP pour a >= 0, b > 0 (entiers petits : quotient flottant exact). */
const intdiv = (a, b) => Math.floor(a / b);

/** RaceNutritionCalculator::status() */
export function status(rate, target) {
    if (rate === null) return 'none';
    const tolerance = roundDiv(target * STATUS_TOLERANCE_PCT, 100);
    if (rate < target - tolerance) return 'below';
    return rate > target + tolerance ? 'above' : 'within';
}

/** RaceNutritionCalculator::lineHundredths() (règle d'ActivityFuelCalculator::itemCarbsHundredths()). */
export function lineHundredths(product, qty) {
    if (!product || qty <= 0) return 0;
    return product.kind === 'drink'
        ? roundDiv(qty * product.tenths * 10, DRINK_REFERENCE_ML)
        : qty * product.tenths * 10;
}

function usable(products, key, cat, share) {
    const product = key !== null && key !== undefined ? (products.get(key) ?? null) : null;
    if (product === null || product.cat !== cat || product.tenths <= 0 || share <= 0) return null;
    return product;
}

const eligible = (point) => point.type === 'aid_station' || point.assistance === true;

function add(sum, items, products) {
    for (const line of items) {
        const product = products.get(line.key) ?? null;
        if (product === null || line.qty <= 0) continue;
        const carbs = lineHundredths(product, line.qty);
        sum.carbs += carbs;
        if (product.kind === 'drink') {
            sum.fluid += line.qty;
            sum.liquid += carbs;
        } else if (product.cat === 'solid') {
            sum.solid += carbs;
        }
    }
}

function auto(c, sum, a, b) {
    const dt = b - a;
    if (dt <= 0) return [];

    // 1. Volume (échelle : ml x 3600 x 100), au pas le plus proche, jamais au-delà du plafond
    let volume = 0;
    if (c.fluid > 0) {
        const deficit = (c.fluid * b - 3600 * sum.fluid) * 100;
        const step = 3600 * FLUID_STEP_ML * 100;
        const cap = Math.max(c.fluid * dt * CAP_PCT, c.fluid * dt * 100 + step);
        if (deficit > 0) volume = Math.min(roundDiv(deficit, step), intdiv(cap, step)) * FLUID_STEP_ML;
    }

    // 2. Boisson glucidique, puis eau pour le reste du volume
    let drinkMl = 0;
    if (c.drink !== null && volume > 0) {
        const need = roundDiv(c.liquid50 * b, 180000) - sum.liquid;
        if (need > 0) drinkMl = Math.min(volume, roundDiv(need * 50, c.drink.tenths * FLUID_STEP_ML) * FLUID_STEP_ML);
    }
    const waterMl = c.water !== null ? volume - drinkMl : 0;
    const drinkCarbs = lineHundredths(c.drink, drinkMl) + lineHundredths(c.water, waterMl);

    // 3. Aliments : déficit total, à l'unité la plus proche, jamais au-delà du plafond (5)
    let fillerUnits = 0;
    let solidUnits = 0;
    if (c.filler !== null) {
        const targetB = roundDiv(c.rate * 100 * b, 3600);
        const targetA = roundDiv(c.rate * 100 * a, 3600);
        const increment = targetB - targetA;
        const cap = Math.max(roundDiv(increment * CAP_PCT, 100), increment + c.maxUnit);
        let room = Math.max(cap - drinkCarbs, c.maxUnit);
        const deficit = targetB - sum.carbs - drinkCarbs;
        if (deficit > 0) {
            const tracked = c.tracked;
            let rest = deficit;
            if (tracked !== null) {
                const unit = tracked.tenths * 10;
                const foodTarget = roundDiv(c.food50 * b, 180000);
                const sigma = roundDiv(
                    SIGMA_SCALE * (c.mixSolid * c.total + (c.early ? Math.min(c.mixSolid, c.mixGel) : 0) * (c.total - b)),
                    (c.mixGel + c.mixSolid) * c.total,
                );
                const solidNeed = roundDiv(foodTarget * sigma, SIGMA_SCALE) - sum.solid;
                if (solidNeed > 0) solidUnits = Math.min(roundDiv(Math.min(solidNeed, deficit), unit), intdiv(room, unit));
                rest = deficit - solidUnits * unit;
                room -= solidUnits * unit;
            }
            if (rest > 0) {
                const unit = c.filler.tenths * 10;
                fillerUnits = Math.min(roundDiv(rest, unit), intdiv(room, unit));
            }
        }
    }

    const items = [];
    if (fillerUnits > 0 && c.filler !== null) items.push({ key: c.filler.key, qty: fillerUnits });
    if (solidUnits > 0 && c.tracked !== null) items.push({ key: c.tracked.key, qty: solidUnits });
    if (drinkMl > 0 && c.drink !== null) items.push({ key: c.drink.key, qty: drinkMl });
    if (waterMl > 0 && c.water !== null) items.push({ key: c.water.key, qty: waterMl });
    return items;
}

function finish(rows, products, rate, fluid, moving, ready) {
    const out = [];
    let totalCarbs = 0;
    let totalFluid = 0;
    const byKey = new Map();
    let previousCarbs = 0;
    let previousFluid = 0;
    for (const row of rows) {
        let carbs = 0;
        let fluidMl = 0;
        for (const line of row.items) {
            const product = products.get(line.key) ?? null;
            carbs += lineHundredths(product, line.qty);
            if (product !== null && product.kind === 'drink') fluidMl += Math.max(0, line.qty);
            byKey.set(line.key, (byKey.get(line.key) ?? 0) + Math.max(0, line.qty));
        }
        totalCarbs += carbs;
        totalFluid += fluidMl;

        let carbsRate = null;
        let fluidRate = null;
        let rowStatus = 'none';
        let fluidStatus = 'none';
        if (row.place === 'segment' && row.seconds !== null && row.seconds > 0) {
            carbsRate = roundDiv((previousCarbs + carbs) * 36, row.seconds);
            fluidRate = roundDiv((previousFluid + fluidMl) * 3600, row.seconds);
            if (row.seconds >= STATUS_MIN_SECONDS) {
                rowStatus = status(carbsRate, rate);
                fluidStatus = status(fluidRate, fluid);
            }
        }
        if (row.place === 'point') {
            previousCarbs = carbs;
            previousFluid = fluidMl;
        }

        out.push({
            key: row.key,
            place: row.place,
            anchor: row.anchor,
            manual: row.manual,
            items: row.items,
            carbsH: carbs,
            carbs: roundDiv(carbs, 100),
            fluidMl,
            seconds: row.seconds,
            carbsPerHour: carbsRate,
            fluidPerHour: fluidRate,
            status: rowStatus,
            fluidStatus,
        });
    }

    const carbsRate = moving !== null && moving > 0 ? roundDiv(totalCarbs * 36, moving) : null;
    const fluidRate = moving !== null && moving > 0 ? roundDiv(totalFluid * 3600, moving) : null;
    const productTotals = [];
    for (const key of products.keys()) {
        if ((byKey.get(key) ?? 0) > 0) productTotals.push({ key, qty: byKey.get(key) });
    }

    return {
        ready,
        rows: out,
        totals: {
            carbsH: totalCarbs,
            carbs: roundDiv(totalCarbs, 100),
            fluidMl: totalFluid,
            movingSeconds: moving,
            carbsPerHour: carbsRate,
            fluidPerHour: fluidRate,
            status: status(carbsRate, rate),
            fluidStatus: status(fluidRate, fluid),
            products: productTotals,
        },
    };
}

/** RaceNutritionCalculator::compute() */
export function compute(input) {
    const s = input.settings;
    const products = new Map();
    for (const product of input.products) products.set(product.key, product);
    const { points, segments } = input;
    const manual = input.manual ?? {};
    const rate = s.carbsPerHour;
    const fluid = s.fluidPerHour;

    let ready = segments.length > 0;
    let total = 0;
    for (const segment of segments) {
        if (segment.seconds === null || segment.seconds === undefined) ready = false;
        else total += Math.max(0, segment.seconds);
    }
    ready = ready && total > 0;

    const gel = usable(products, s.gel, 'gel', s.mixGel);
    const solid = usable(products, s.solid, 'solid', s.mixSolid);
    const drink = usable(products, s.drink, 'drink', s.mixDrink);
    const water = products.get(WATER_KEY) ?? null;
    const filler = gel ?? solid;
    const tracked = gel !== null ? solid : null;
    const maxUnit = Math.max(gel !== null ? gel.tenths * 10 : 0, solid !== null ? solid.tenths * 10 : 0);
    const liquid50 = drink !== null ? Math.min(rate * s.mixDrink * 50, fluid * drink.tenths) : 0;
    const food50 = rate * 5000 - liquid50;
    const ctx = {
        rate, fluid, total, early: s.solidEarly === true, mixGel: s.mixGel, mixSolid: s.mixSolid, liquid50, food50,
        drink, water, filler, tracked, maxUnit,
    };

    const sum = { carbs: 0, fluid: 0, liquid: 0, solid: 0 };
    const rows = [];

    const startAnchor = points[0]?.anchor ?? 'start';
    const startKey = `p:${startAnchor}`;
    const startItems = has(manual, startKey) ? manual[startKey] : [];
    rows.push({ key: startKey, place: 'point', anchor: startAnchor, manual: has(manual, startKey), items: startItems, seconds: null });
    add(sum, startItems, products);
    let pointRow = 0;

    let elapsed = 0;
    const n = segments.length - 1;
    segments.forEach((segment, i) => {
        const seconds = segment.seconds !== null && segment.seconds !== undefined ? Math.max(0, segment.seconds) : null;
        const from = elapsed;
        elapsed += seconds ?? 0;
        const key = `s:${segment.anchor}`;
        const isManual = has(manual, key);
        let items = [];
        if (isManual) {
            items = manual[key];
        } else if (ready) {
            items = auto(ctx, sum, from, elapsed);
            const start = points[i] ?? null;
            if (i > 0 && start !== null && !rows[pointRow].manual && eligible(start)) {
                const moved = [];
                const kept = [];
                for (const line of items) {
                    if ((products.get(line.key)?.cat ?? null) === 'solid') moved.push(line);
                    else kept.push(line);
                }
                rows[pointRow].items = moved;
                items = kept;
                add(sum, moved, products);
            }
        }
        add(sum, items, products);
        rows.push({ key, place: 'segment', anchor: segment.anchor, manual: isManual, items, seconds });

        if (i < n) {
            const point = points[i + 1];
            const pKey = `p:${point.anchor}`;
            const pManual = has(manual, pKey);
            const pItems = pManual ? manual[pKey] : [];
            add(sum, pItems, products);
            rows.push({ key: pKey, place: 'point', anchor: point.anchor, manual: pManual, items: pItems, seconds: null });
            pointRow = rows.length - 1;
        }
    });

    return finish(rows, products, rate, fluid, ready ? total : null, ready);
}

/** RaceNutritionCalculator::carryList() */
export function carryList(input, result, flaskMl) {
    const order = new Map();
    const kinds = new Map();
    input.products.forEach((product, i) => {
        order.set(product.key, i);
        kinds.set(product.key, product.kind);
    });
    const pointsByAnchor = new Map();
    for (const point of input.points) pointsByAnchor.set(point.anchor, point);
    const flask = Math.max(1, flaskMl);

    const bump = (map, key, qty) => map.set(key, (map.get(key) ?? 0) + qty);
    const stops = [];
    let foodStop = -1;
    let drinkStop = -1;
    for (const row of result.rows) {
        if (row.place === 'point') {
            const point = pointsByAnchor.get(row.anchor) ?? { anchor: row.anchor, type: 'point', assistance: false };
            const isStart = point.type === 'start';
            const isAid = point.type === 'aid_station';
            const food = isStart || point.assistance === true;
            const refill = food || isAid;
            if (refill) {
                stops.push({ anchor: row.anchor, type: point.type, assistance: point.assistance === true, eatHere: new Map(), carry: food ? new Map() : null, fill: new Map() });
                const index = stops.length - 1;
                drinkStop = index;
                if (food) foodStop = index;
                for (const line of row.items) {
                    bump(stops[index].eatHere, line.key, line.qty);
                    if (food && (kinds.get(line.key) ?? 'food') !== 'drink') bump(stops[index].carry, line.key, line.qty);
                }
                continue;
            }
        }
        for (const line of row.items) {
            if ((kinds.get(line.key) ?? 'food') === 'drink') {
                if (drinkStop >= 0) bump(stops[drinkStop].fill, line.key, line.qty);
            } else if (foodStop >= 0) {
                bump(stops[foodStop].carry, line.key, line.qty);
            }
        }
    }

    const list = (quantities) => [...quantities.entries()]
        .filter(([, qty]) => qty > 0)
        .map(([key]) => key)
        .sort((a, b) => (order.get(a) ?? Number.MAX_SAFE_INTEGER) - (order.get(b) ?? Number.MAX_SAFE_INTEGER))
        .map((key) => ({ key, qty: quantities.get(key) }));

    return stops.map((stop) => {
        let fill = null;
        let flasks = 0;
        if (stop.fill !== null) {
            fill = list(stop.fill).map((line) => {
                const count = Math.floor((line.qty + flask - 1) / flask);
                flasks += count;
                return { key: line.key, ml: line.qty, flasks: count };
            });
        }
        return {
            anchor: stop.anchor,
            type: stop.type,
            assistance: stop.assistance,
            eatHere: list(stop.eatHere),
            carry: stop.carry !== null ? list(stop.carry) : null,
            fill,
            flasks,
        };
    });
}
