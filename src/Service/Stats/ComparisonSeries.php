<?php

namespace App\Service\Stats;

/**
 * Séries des graphiques de la comparaison, calculées à partir des totaux par jour de chaque période.
 *
 * Indicateurs (METRICS) : distance en km au dixième, temps en mouvement en minutes, D+ en mètres, séances.
 *
 * - Cumul : un point par jour depuis le début de chaque période (J1, J2...), A et B alignés par indice.
 *   Les jours après la fin d'une période, ou après aujourd'hui, valent null (la courbe s'arrête).
 * - Barres : un groupe par tranche, alignées par indice : jour jusqu'à 14 jours, tranches de 7 jours
 *   depuis le début de la période jusqu'à 120 jours (S1 = jours 1 à 7...), mois civils au-delà (la
 *   première et la dernière tranche peuvent être partielles). Une tranche absente de la période, ou qui
 *   commence après aujourd'hui, vaut null.
 *
 * Les dates sont des chaînes 'Y-m-d' : aucun calcul en secondes, donc aucun effet des changements d'heure.
 */
final class ComparisonSeries
{
    public const METRICS = ['distance', 'time', 'elevation', 'sessions'];
    public const DAY_BUCKETS_MAX_DAYS = 14;
    public const WEEK_BUCKETS_MAX_DAYS = 120;

    private function __construct()
    {
    }

    public static function granularity(int $days): string
    {
        return match (true) {
            $days <= self::DAY_BUCKETS_MAX_DAYS  => 'day',
            $days <= self::WEEK_BUCKETS_MAX_DAYS => 'week',
            default                              => 'month',
        };
    }

    /**
     * @param array<string, PeriodTotals> $dailyA totaux de A par jour 'Y-m-d'
     * @param array<string, PeriodTotals> $dailyB totaux de B par jour 'Y-m-d'
     * @param string                      $today  'Y-m-d'
     *
     * @return array{days: int, dates: array{a: list<string>, b: list<string>}, values: array<string, array{a: list<int|float|null>, b: list<int|float|null>}>}
     */
    public static function cumulative(DateRange $a, DateRange $b, array $dailyA, array $dailyB, string $today): array
    {
        $days = max($a->days(), $b->days());
        $dates = ['a' => [], 'b' => []];
        $values = [];
        foreach (self::METRICS as $metric) {
            $values[$metric] = ['a' => [], 'b' => []];
        }

        foreach (['a' => [$a, $dailyA], 'b' => [$b, $dailyB]] as $side => [$range, $daily]) {
            $running = new PeriodTotals();
            $length = $range->days();
            $day = $range->fromKey();
            for ($i = 0; $i < $days; ++$i) {
                $point = null;
                if ($i < $length) {
                    $dates[$side][] = $day;
                    if ($day <= $today) {
                        $running = $running->plus($daily[$day] ?? new PeriodTotals());
                        $point = self::values($running);
                    }
                    $day = DateRange::addDays($day, 1);
                }
                foreach (self::METRICS as $metric) {
                    $values[$metric][$side][] = $point[$metric] ?? null;
                }
            }
        }

        return ['days' => $days, 'dates' => $dates, 'values' => $values];
    }

    /**
     * @param array<string, PeriodTotals> $dailyA
     * @param array<string, PeriodTotals> $dailyB
     *
     * @return array{granularity: string, buckets: list<array{a: array{from: string, to: string}|null, b: array{from: string, to: string}|null}>, values: array<string, array{a: list<int|float|null>, b: list<int|float|null>}>}
     */
    public static function buckets(DateRange $a, DateRange $b, array $dailyA, array $dailyB, string $today): array
    {
        $granularity = self::granularity(max($a->days(), $b->days()));
        $rangesA = self::split($a, $granularity);
        $rangesB = self::split($b, $granularity);
        $count = max(count($rangesA), count($rangesB));

        $totals = ['a' => self::sumByBucket($a, $dailyA, $granularity), 'b' => self::sumByBucket($b, $dailyB, $granularity)];
        $ranges = ['a' => $rangesA, 'b' => $rangesB];

        $buckets = [];
        $values = [];
        foreach (self::METRICS as $metric) {
            $values[$metric] = ['a' => [], 'b' => []];
        }
        for ($k = 0; $k < $count; ++$k) {
            $buckets[] = ['a' => $rangesA[$k] ?? null, 'b' => $rangesB[$k] ?? null];
            foreach (['a', 'b'] as $side) {
                $range = $ranges[$side][$k] ?? null;
                $point = $range !== null && $range['from'] <= $today
                    ? self::values($totals[$side][$k] ?? new PeriodTotals())
                    : null;
                foreach (self::METRICS as $metric) {
                    $values[$metric][$side][] = $point[$metric] ?? null;
                }
            }
        }

        return ['granularity' => $granularity, 'buckets' => $buckets, 'values' => $values];
    }

    /**
     * Tranches d'une période : jours, blocs de 7 jours depuis le début, ou mois civils (bornés à la période).
     *
     * @return list<array{from: string, to: string}>
     */
    public static function split(DateRange $range, string $granularity): array
    {
        $out = [];
        $from = $range->fromKey();
        $end = $range->toKey();
        while ($from <= $end) {
            $to = match ($granularity) {
                'day'   => $from,
                'week'  => DateRange::addDays($from, 6),
                default => substr($from, 0, 8).(new \DateTimeImmutable($from, new \DateTimeZone('UTC')))->format('t'),
            };
            $to = min($to, $end);
            $out[] = ['from' => $from, 'to' => $to];
            $from = DateRange::addDays($to, 1);
        }

        return $out;
    }

    /**
     * Valeurs affichées des indicateurs d'un total (km au dixième, minutes, mètres, séances).
     *
     * @return array<string, int|float>
     */
    public static function values(PeriodTotals $totals): array
    {
        return [
            'distance'  => MetricDelta::round($totals->distanceM / 1000, 1),
            'time'      => (int) MetricDelta::round($totals->movingSeconds / 60, 0),
            'elevation' => (int) MetricDelta::round($totals->elevationM, 0),
            'sessions'  => $totals->sessions,
        ];
    }

    /**
     * @param array<string, PeriodTotals> $daily
     *
     * @return array<int, PeriodTotals> totaux par indice de tranche
     */
    private static function sumByBucket(DateRange $range, array $daily, string $granularity): array
    {
        $firstMonth = (int) substr($range->fromKey(), 0, 4) * 12 + (int) substr($range->fromKey(), 5, 2);
        $out = [];
        foreach ($daily as $day => $totals) {
            $day = (string) $day;
            if (!$range->contains($day)) {
                continue;
            }
            $index = match ($granularity) {
                'day'   => $range->indexOf($day),
                'week'  => intdiv($range->indexOf($day), 7),
                default => (int) substr($day, 0, 4) * 12 + (int) substr($day, 5, 2) - $firstMonth,
            };
            $out[$index] = isset($out[$index]) ? $out[$index]->plus($totals) : $totals;
        }

        return $out;
    }
}
