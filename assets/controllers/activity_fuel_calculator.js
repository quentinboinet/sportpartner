/*
 * Miroir JS de App\Service\Nutrition\ActivityFuelCalculator (mêmes règles, mêmes arrondis) : mise à jour en direct
 * de la carte Ravitaillement par activity_fuel_controller.js. Ce n'est pas un controller Stimulus.
 * Toute règle modifiée côté PHP doit l'être ici aussi (et inversement).
 *
 * Calcul en entiers (centièmes de gramme) pour obtenir exactement les valeurs de PHP :
 *  - aliment : quantité x glucides par unité ; boisson : ml / 500 x glucides pour 500 ml (glucides au dixième) ;
 *  - arrondis au plus proche, demi vers le haut : grammes et débits entiers, liquide au dixième de litre ;
 *  - statut : débit ARRONDI comparé à la cible, bornes incluses ; "none" sans durée.
 * La cible (min, max) vient du serveur (ActivityFuelCalculator::TARGET_*), jamais codée ici.
 */

export const DRINK_REFERENCE_ML = 500;

/** Division entière arrondie au plus proche, demi vers le haut (a >= 0, b > 0). */
export function roundDiv(a, b) {
    return Math.floor((2 * a + b) / (2 * b));
}

function carbsTenths(carbs) {
    return Math.round(carbs * 10);
}

/** Glucides d'une ligne en centièmes de gramme ; item = { kind: 'food' | 'drink', quantity, carbs }. */
export function itemCarbsHundredths(item) {
    const tenths = carbsTenths(item.carbs);
    return item.kind === 'food'
        ? item.quantity * tenths * 10
        : roundDiv(item.quantity * tenths * 10, DRINK_REFERENCE_ML);
}

/** Total d'une ligne, en grammes arrondis. */
export function itemCarbs(item) {
    return roundDiv(itemCarbsHundredths(item), 100);
}

export function targetStatus(carbsPerHour, target) {
    if (carbsPerHour === null) return 'none';
    if (carbsPerHour < target.min) return 'below';
    if (carbsPerHour > target.max) return 'above';
    return 'within';
}

/**
 * Bilan (mêmes champs que FuelSummary) : items = [{ kind, quantity, carbs }], durationSeconds = durée de référence
 * (temps en mouvement, sinon écoulé ; 0 ou null si inconnue), target = { min, max }.
 */
export function summarize(items, durationSeconds, target) {
    let foodHundredths = 0;
    let drinkHundredths = 0;
    let liquidMl = 0;
    let foodUnits = 0;
    for (const item of items) {
        const hundredths = itemCarbsHundredths(item);
        if (item.kind === 'food') {
            foodHundredths += hundredths;
            foodUnits += item.quantity;
        } else {
            drinkHundredths += hundredths;
            liquidMl += item.quantity;
        }
    }
    const totalHundredths = foodHundredths + drinkHundredths;
    const duration = durationSeconds > 0 ? durationSeconds : null;
    // g/h = (centièmes / 100) / (secondes / 3600) = centièmes x 36 / secondes
    const carbsPerHour = duration === null ? null : roundDiv(totalHundredths * 36, duration);

    return {
        foodCarbs: roundDiv(foodHundredths, 100),
        drinkCarbs: roundDiv(drinkHundredths, 100),
        totalCarbs: roundDiv(totalHundredths, 100),
        durationSeconds: duration,
        carbsPerHour,
        liquidMl,
        liquidDl: roundDiv(liquidMl, 100),
        hydrationMlPerHour: duration === null ? null : roundDiv(liquidMl * 3600, duration),
        foodUnits,
        status: targetStatus(carbsPerHour, target),
    };
}
