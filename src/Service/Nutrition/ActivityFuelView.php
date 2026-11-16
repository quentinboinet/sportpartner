<?php

namespace App\Service\Nutrition;

use App\Entity\FuelKind;

/** Données de la carte Ravitaillement d'une activité (partial activities/_fuel.html.twig). */
final class ActivityFuelView
{
    /**
     * @param list<FuelItem> $items lignes affichées : aliments (catalogue puis personnalisés), puis boissons (idem)
     */
    public function __construct(
        public readonly array $items,
        public readonly FuelSummary $summary,
    ) {
    }

    /** @return list<FuelItem> */
    public function food(): array
    {
        return $this->ofKind(FuelKind::Food);
    }

    /** @return list<FuelItem> */
    public function drinks(): array
    {
        return $this->ofKind(FuelKind::Drink);
    }

    /** Total d'une ligne, en grammes arrondis. */
    public function rowCarbs(FuelItem $item): int
    {
        return ActivityFuelCalculator::itemCarbs($item);
    }

    /**
     * Cible, échelle de la jauge et limites de saisie, transmises au JS.
     *
     * @return array<string, int>
     */
    public function config(): array
    {
        return [
            'targetMin'    => ActivityFuelCalculator::TARGET_MIN_G_PER_H,
            'targetMax'    => ActivityFuelCalculator::TARGET_MAX_G_PER_H,
            'gaugeMax'     => ActivityFuelCalculator::GAUGE_MAX_G_PER_H,
            'maxFoodUnits' => FuelCatalog::MAX_FOOD_UNITS,
            'maxDrinkMl'   => FuelCatalog::MAX_DRINK_ML,
            'drinkStep'    => FuelCatalog::DRINK_STEP_ML,
            'maxCarbs'     => FuelCatalog::MAX_CARBS,
            'maxCustom'    => FuelCatalog::MAX_CUSTOM_PER_KIND,
            'labelMax'     => FuelCatalog::LABEL_MAX_LENGTH,
        ];
    }

    /** @return list<FuelItem> */
    private function ofKind(FuelKind $kind): array
    {
        return array_values(array_filter($this->items, static fn (FuelItem $item): bool => $item->kind === $kind));
    }
}
