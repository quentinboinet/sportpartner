<?php

namespace App\Service\Stats;

/**
 * Résultat de PeriodComparator::compare() : totaux, indicateurs comparés, détail par sport et séries.
 */
final class ComparisonResult
{
    /**
     * @param list<MetricComparison>                                                                                                                                     $metrics  totaux de la période
     * @param list<MetricComparison>                                                                                                                                     $weekly   moyennes par semaine
     * @param list<array{slug: string, metrics: array<string, MetricComparison>}>                                                                                        $sports   détail par sport (tous les sports seulement)
     * @param array{days: int, dates: array{a: list<string>, b: list<string>}, values: array<string, array{a: list<int|float|null>, b: list<int|float|null>}>}             $cumulative
     * @param array{granularity: string, buckets: list<array{a: array{from: string, to: string}|null, b: array{from: string, to: string}|null}>, values: array<string, array{a: list<int|float|null>, b: list<int|float|null>}>} $buckets
     */
    public function __construct(
        public readonly PeriodComparison $comparison,
        public readonly ?string $sport,
        public readonly PeriodTotals $a,
        public readonly PeriodTotals $b,
        public readonly array $metrics,
        public readonly array $weekly,
        public readonly array $sports,
        public readonly array $cumulative,
        public readonly array $buckets,
    ) {
    }
}
