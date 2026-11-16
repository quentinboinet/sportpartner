<?php

namespace App\Service\Stats;

/**
 * Données d'affichage de la comparaison : textes mis en forme pour Twig, et données JSON des graphiques
 * (Stimulus stats-compare). Les libellés traduits (mois, sports, préfixes J / S) sont fournis par l'appelant.
 */
final class ComparisonPresenter
{
    /** Décimales des indicateurs des graphiques (voir ComparisonSeries::values()). */
    private const CHART_UNITS = [
        'distance'  => ['km', 1],
        'time'      => ['min', 0],
        'elevation' => ['m', 0],
        'sessions'  => ['count', 0],
    ];

    private function __construct()
    {
    }

    /**
     * @return array{key: string, sport: string|null, a: string, b: string, delta: string, percent: string|null, direction: string, tone: string}
     */
    public static function metric(MetricComparison $metric, string $locale): array
    {
        $d = $metric->delta;

        return [
            'key'       => $metric->key,
            'sport'     => $metric->sport,
            'a'         => ComparisonFormat::value($d->a, $metric->unit, $metric->decimals, $locale),
            'b'         => ComparisonFormat::value($d->b, $metric->unit, $metric->decimals, $locale),
            'delta'     => ComparisonFormat::signed($d->delta, $metric->unit, $metric->decimals, $locale),
            'percent'   => $d->percent !== null ? ComparisonFormat::percent($d->percent, $locale) : null,
            'direction' => $d->direction,
            'tone'      => $d->tone,
        ];
    }

    /**
     * @param list<MetricComparison> $metrics
     *
     * @return list<array{key: string, sport: string|null, a: string, b: string, delta: string, percent: string|null, direction: string, tone: string}>
     */
    public static function metrics(array $metrics, string $locale): array
    {
        return array_map(static fn (MetricComparison $m): array => self::metric($m, $locale), $metrics);
    }

    /**
     * Écarts de la vue d'ensemble (/stats) entre ses totaux et ceux de la période précédente, lignes de
     * ActivityRepository::getSeasonStats() (cnt, total_distance, total_time, total_elevation).
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $previous
     *
     * @return array<string, array{key: string, sport: string|null, a: string, b: string, delta: string, percent: string|null, direction: string, tone: string}>
     */
    public static function overview(array $current, array $previous, string $locale): array
    {
        $num = static fn (array $row, string $key): float => is_numeric($row[$key] ?? null) ? (float) $row[$key] : 0.0;
        $metrics = [
            new MetricComparison('distance', 'km', 1, MetricDelta::compare($num($current, 'total_distance') / 1000, $num($previous, 'total_distance') / 1000, MetricDelta::HIGHER_IS_BETTER, 1)),
            new MetricComparison('time', 'min', 0, MetricDelta::compare($num($current, 'total_time') / 60, $num($previous, 'total_time') / 60, MetricDelta::HIGHER_IS_BETTER, 0)),
            new MetricComparison('elevation', 'm', 0, MetricDelta::compare($num($current, 'total_elevation'), $num($previous, 'total_elevation'), MetricDelta::HIGHER_IS_BETTER, 0)),
            new MetricComparison('sessions', 'count', 0, MetricDelta::compare($num($current, 'cnt'), $num($previous, 'cnt'), MetricDelta::HIGHER_IS_BETTER, 0)),
        ];

        $out = [];
        foreach ($metrics as $metric) {
            $out[$metric->key] = self::metric($metric, $locale);
        }

        return $out;
    }

    /**
     * Données des graphiques.
     *
     * @param list<string>          $months      noms courts des 12 mois
     * @param array<string, string> $sportLabels slug => libellé traduit
     * @param array<string, string> $sportColors slug => couleur
     * @param array{day: string, week: string} $prefixes préfixes des tranches (« J », « S »)
     *
     * @return array<string, mixed>
     */
    public static function chart(ComparisonResult $result, string $locale, array $months, array $sportLabels, array $sportColors, array $prefixes): array
    {
        $buckets = $result->buckets;
        $labels = [];
        $ranges = [];
        foreach ($buckets['buckets'] as $k => $bucket) {
            $ref = $bucket['a'] ?? $bucket['b'];
            $labels[] = match ($buckets['granularity']) {
                'day'   => $prefixes['day'].($k + 1),
                'week'  => $prefixes['week'].($k + 1),
                default => self::monthLabel($ref['from'] ?? '', $result->comparison, $months),
            };
            $ranges[] = [
                'a' => $bucket['a'] !== null ? ComparisonFormat::range($bucket['a']['from'], $bucket['a']['to'], $months, false) : null,
                'b' => $bucket['b'] !== null ? ComparisonFormat::range($bucket['b']['from'], $bucket['b']['to'], $months, false) : null,
            ];
        }

        $rows = [];
        $totals = [];
        foreach (self::CHART_UNITS as $metric => [$unit, $decimals]) {
            foreach ($buckets['values'][$metric]['a'] as $k => $valueA) {
                $valueB = $buckets['values'][$metric]['b'][$k] ?? null;
                $rows[$metric][] = self::metric(new MetricComparison($metric, $unit, $decimals, MetricDelta::compare(
                    $valueA !== null ? (float) $valueA : null,
                    $valueB !== null ? (float) $valueB : null,
                    MetricDelta::HIGHER_IS_BETTER,
                    $decimals,
                )), $locale);
            }
            foreach ($result->metrics as $m) {
                if ($m->key === $metric) {
                    $totals[$metric] = self::metric($m, $locale);
                }
            }
        }

        $sports = null;
        if ($result->sports !== []) {
            $sports = ['slugs' => [], 'labels' => [], 'colors' => [], 'values' => []];
            foreach ($result->sports as $row) {
                $sports['slugs'][] = $row['slug'];
                $sports['labels'][] = $sportLabels[$row['slug']] ?? $row['slug'];
                $sports['colors'][] = $sportColors[$row['slug']] ?? '#9CA3AF';
                foreach (array_keys(self::CHART_UNITS) as $metric) {
                    $sports['values'][$metric]['a'][] = $row['metrics'][$metric]->delta->a ?? 0;
                    $sports['values'][$metric]['b'][] = $row['metrics'][$metric]->delta->b ?? 0;
                }
            }
        }

        return [
            'locale'     => $locale,
            'cumulative' => $result->cumulative,
            'buckets'    => [
                'granularity' => $buckets['granularity'],
                'labels'      => $labels,
                'ranges'      => $ranges,
                'values'      => $buckets['values'],
                'rows'        => $rows,
            ],
            'sports'     => $sports,
            'totals'     => $totals,
        ];
    }

    /**
     * Mois d'une tranche : « janv. », suivi de l'année sur deux chiffres si la période A couvre plusieurs années.
     *
     * @param list<string> $months
     */
    private static function monthLabel(string $from, PeriodComparison $comparison, array $months): string
    {
        $label = $months[(int) substr($from, 5, 2) - 1] ?? substr($from, 5, 2);
        $spansYears = substr($comparison->a->fromKey(), 0, 4) !== substr($comparison->a->toKey(), 0, 4)
            || substr($comparison->b->fromKey(), 0, 4) !== substr($comparison->b->toKey(), 0, 4);

        return $spansYears ? $label.' '.substr($from, 2, 2) : $label;
    }
}
