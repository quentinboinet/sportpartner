<?php

namespace App\Service\Goals;

use App\Entity\Sport;

/** Avancement de la semaine pour un objectif par sport : une mesure par cible renseignée. */
final class SportGoalProgress
{
    /** @param list<GoalMetric> $metrics */
    public function __construct(
        public readonly Sport $sport,
        public readonly array $metrics,
    ) {
    }

    /** Toutes les cibles atteintes. */
    public function isReached(): bool
    {
        foreach ($this->metrics as $metric) {
            if (!$metric->isReached()) {
                return false;
            }
        }

        return $this->metrics !== [];
    }
}
