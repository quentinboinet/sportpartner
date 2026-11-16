<?php

namespace App\Service\Stats;

/**
 * Un indicateur comparé : clé (traduite par stats.compare.metric.<key>), unité d'affichage et écart.
 *
 * Unités : km, min (minutes affichées en durée), m, count, pace (secondes par km), kmh, bpm.
 */
final class MetricComparison
{
    public function __construct(
        public readonly string $key,
        public readonly string $unit,
        public readonly int $decimals,
        public readonly MetricDelta $delta,
        /** Sport sur lequel porte l'indicateur quand il ne couvre pas toute la sélection (allure de la course à pied...). */
        public readonly ?string $sport = null,
    ) {
    }
}
