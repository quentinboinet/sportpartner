<?php

namespace App\Service\Nutrition;

use App\Entity\FuelKind;

/**
 * Calculs purs du ravitaillement d'une activité. Miroir JS identique : assets/controllers/activity_fuel_calculator.js
 * (mise à jour en direct de la carte) ; toute règle modifiée ici doit l'être aussi là-bas.
 *
 * Règles :
 *  - glucides d'un aliment = quantité x glucides par unité ; d'une boisson = ml / 500 x glucides pour 500 ml ;
 *  - durée = temps en mouvement, sinon temps écoulé ; sans durée, ni débit, ni hydratation (statut "none") ;
 *  - débit (g/h) = glucides totaux / heures ; hydratation (ml/h) = liquide (boissons) / heures ;
 *  - arrondis d'affichage au plus proche, demi vers le haut : grammes et débits entiers, liquide au dixième de litre.
 *    Le total est l'arrondi de la somme exacte (il peut différer d'une unité de aliments + boissons arrondis) ;
 *  - statut : comparaison du débit ARRONDI (celui qui est affiché) à la cible, bornes incluses :
 *    59 -> below, 60 et 90 -> within, 91 -> above.
 * Tout est calculé en entiers (centièmes de gramme) pour que PHP et JS donnent exactement le même résultat.
 */
final class ActivityFuelCalculator
{
    /**
     * Cible de glucides pendant l'effort (g/h, bornes incluses) et fin d'échelle de la jauge.
     * Valeurs fixes pour l'instant ; elles pourront venir du profil de l'athlète ou dépendre de la durée.
     * Seule source de ces valeurs : le JS les reçoit du partial activities/_fuel.html.twig.
     */
    public const TARGET_MIN_G_PER_H = 60;
    public const TARGET_MAX_G_PER_H = 90;
    public const GAUGE_MAX_G_PER_H = 120;

    /** Durée de référence : temps en mouvement, sinon temps écoulé ; null si aucune n'est connue. */
    public static function durationSeconds(?int $movingSeconds, ?int $elapsedSeconds): ?int
    {
        if ($movingSeconds !== null && $movingSeconds > 0) {
            return $movingSeconds;
        }

        return $elapsedSeconds !== null && $elapsedSeconds > 0 ? $elapsedSeconds : null;
    }

    /**
     * @param list<FuelItem> $items
     */
    public function summarize(array $items, ?int $durationSeconds): FuelSummary
    {
        $foodHundredths = 0;
        $drinkHundredths = 0;
        $liquidMl = 0;
        $foodUnits = 0;
        foreach ($items as $item) {
            $hundredths = self::itemCarbsHundredths($item);
            if ($item->kind === FuelKind::Food) {
                $foodHundredths += $hundredths;
                $foodUnits += $item->quantity;
            } else {
                $drinkHundredths += $hundredths;
                $liquidMl += $item->quantity;
            }
        }
        $totalHundredths = $foodHundredths + $drinkHundredths;

        $duration = $durationSeconds !== null && $durationSeconds > 0 ? $durationSeconds : null;
        // g/h = (centièmes / 100) / (secondes / 3600) = centièmes x 36 / secondes
        $rate = $duration === null ? null : self::roundDiv($totalHundredths * 36, $duration);
        $hydration = $duration === null ? null : self::roundDiv($liquidMl * 3600, $duration);

        return new FuelSummary(
            foodCarbs: self::roundDiv($foodHundredths, 100),
            drinkCarbs: self::roundDiv($drinkHundredths, 100),
            totalCarbs: self::roundDiv($totalHundredths, 100),
            durationSeconds: $duration,
            carbsPerHour: $rate,
            liquidMl: $liquidMl,
            liquidDl: self::roundDiv($liquidMl, 100),
            hydrationMlPerHour: $hydration,
            foodUnits: $foodUnits,
            status: self::status($rate),
        );
    }

    /** Glucides d'une ligne, en grammes arrondis (total de la ligne affiché dans la carte). */
    public static function itemCarbs(FuelItem $item): int
    {
        return self::roundDiv(self::itemCarbsHundredths($item), 100);
    }

    /**
     * Glucides d'une ligne en centièmes de gramme. Les glucides par unité sont pris au dixième de gramme ;
     * pour une boisson (multiple de 50 ml une fois validée), le résultat est exact.
     */
    public static function itemCarbsHundredths(FuelItem $item): int
    {
        $tenths = self::carbsTenths($item->carbs);

        return $item->kind === FuelKind::Food
            ? $item->quantity * $tenths * 10
            // (ml / 500) x (dixièmes / 10) g = ml x dixièmes x 10 / 500 centièmes
            : self::roundDiv($item->quantity * $tenths * 10, FuelCatalog::DRINK_REFERENCE_ML);
    }

    public static function status(?int $carbsPerHour): FuelTargetStatus
    {
        return match (true) {
            $carbsPerHour === null                    => FuelTargetStatus::None,
            $carbsPerHour < self::TARGET_MIN_G_PER_H => FuelTargetStatus::Below,
            $carbsPerHour > self::TARGET_MAX_G_PER_H => FuelTargetStatus::Above,
            default                                   => FuelTargetStatus::Within,
        };
    }

    /** Glucides en dixièmes de gramme (les valeurs saisies sont déjà arrondies au dixième). */
    private static function carbsTenths(float $carbs): int
    {
        return (int) round($carbs * 10);
    }

    /**
     * Division entière arrondie au plus proche, demi vers le haut ($a >= 0, $b > 0). Aussi utilisée par
     * RaceNutritionCalculator (miroir JS : roundDiv() de activity_fuel_calculator.js, exact tant que $a < 4,5e15).
     */
    public static function roundDiv(int $a, int $b): int
    {
        return intdiv(2 * $a + $b, 2 * $b);
    }
}
