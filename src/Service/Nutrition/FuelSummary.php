<?php

namespace App\Service\Nutrition;

/**
 * Bilan du ravitaillement d'une activité, déjà arrondi pour l'affichage (voir ActivityFuelCalculator) :
 * grammes et débits entiers, liquide en ml et en dixièmes de litre (affiché avec 1 décimale).
 */
final class FuelSummary
{
    public function __construct(
        public readonly int $foodCarbs,
        public readonly int $drinkCarbs,
        public readonly int $totalCarbs,
        public readonly ?int $durationSeconds,
        public readonly ?int $carbsPerHour,
        public readonly int $liquidMl,
        public readonly int $liquidDl,
        public readonly ?int $hydrationMlPerHour,
        public readonly int $foodUnits,
        public readonly FuelTargetStatus $status,
    ) {
    }

    /** Au moins un aliment ou une boisson saisi(e). */
    public function hasEntries(): bool
    {
        return $this->foodUnits > 0 || $this->liquidMl > 0;
    }

    /** Liquide total en litres, 1 décimale. */
    public function liquidLiters(): float
    {
        return $this->liquidDl / 10;
    }

    /**
     * Contrat JSON de POST /activities/{id}/fuel (clé "summary").
     *
     * @return array{foodCarbs: int, drinkCarbs: int, totalCarbs: int, durationSeconds: int|null, carbsPerHour: int|null,
     *               liquidMl: int, liquidLiters: float, hydrationMlPerHour: int|null, foodUnits: int, status: string,
     *               target: array{minPerHour: int, maxPerHour: int}}
     */
    public function toArray(): array
    {
        return [
            'foodCarbs'          => $this->foodCarbs,
            'drinkCarbs'         => $this->drinkCarbs,
            'totalCarbs'         => $this->totalCarbs,
            'durationSeconds'    => $this->durationSeconds,
            'carbsPerHour'       => $this->carbsPerHour,
            'liquidMl'           => $this->liquidMl,
            'liquidLiters'       => $this->liquidLiters(),
            'hydrationMlPerHour' => $this->hydrationMlPerHour,
            'foodUnits'          => $this->foodUnits,
            'status'             => $this->status->value,
            'target'             => [
                'minPerHour' => ActivityFuelCalculator::TARGET_MIN_G_PER_H,
                'maxPerHour' => ActivityFuelCalculator::TARGET_MAX_G_PER_H,
            ],
        ];
    }
}
