<?php

namespace App\Service\Stats;

/**
 * Comparaison de deux périodes à partir des totaux par jour et par sport (une seule requête :
 * ActivityRepository::sumByDayAndSport). Calcul pur.
 *
 * Indicateurs, dans l'ordre d'affichage :
 * - distance, temps en mouvement, D+, séances (plus = mieux) ;
 * - allure moyenne (secondes / km, plus bas = mieux) pour un sport à pied choisi (course, trail, marche,
 *   randonnée), vitesse moyenne (km/h, plus haut = mieux) pour le vélo ; avec « tous les sports », allure de
 *   la course (run) et vitesse du vélo (ride) seulement, chacune affichée si une des périodes en a une
 *   (mélanger course, marche et vélo ne donnerait pas une allure qui ait un sens) ;
 * - FC moyenne pondérée par le temps en mouvement, si une des périodes en a une (sans jugement : neutre) ;
 * - plus longue sortie (distance) et plus gros D+ sur une sortie (plus = mieux) ;
 * - moyennes par semaine (total x 7 / nombre de jours) : distance, temps, séances.
 */
final class PeriodComparator
{
    /** Sports « à pied » : allure en min/km. */
    public const PACE_SPORTS = ['run', 'trail', 'walk', 'hike'];
    /** Sports à vitesse : km/h. */
    public const SPEED_SPORTS = ['ride'];

    private function __construct()
    {
    }

    /**
     * @param list<array{day: string, sport: string, sessions: int, distanceM: float, movingSeconds: int, elevationM: float, maxDistanceM: float, maxElevationM: float, hrWeighted: float, hrSeconds: int, paceDistanceM: float, paceSeconds: int}> $rows lignes par jour et par sport couvrant A et B
     * @param string       $today      'Y-m-d'
     * @param list<string> $sportOrder slugs dans l'ordre d'affichage
     */
    public static function compare(PeriodComparison $comparison, array $rows, ?string $sport, string $today, array $sportOrder = []): ComparisonResult
    {
        $ranges = ['a' => $comparison->a, 'b' => $comparison->b];
        $daily = ['a' => [], 'b' => []];
        $bySport = ['a' => [], 'b' => []];
        $totals = ['a' => new PeriodTotals(), 'b' => new PeriodTotals()];

        foreach ($rows as $row) {
            if ($sport !== null && $row['sport'] !== $sport) {
                continue;
            }
            $line = PeriodTotals::ofRow($row);
            foreach ($ranges as $side => $range) {
                if (!$range->contains($row['day'])) {
                    continue;
                }
                $day = $row['day'];
                $daily[$side][$day] = isset($daily[$side][$day]) ? $daily[$side][$day]->plus($line) : $line;
                $bySport[$side][$row['sport']] = isset($bySport[$side][$row['sport']]) ? $bySport[$side][$row['sport']]->plus($line) : $line;
                $totals[$side] = $totals[$side]->plus($line);
            }
        }

        [$a, $b] = [$totals['a'], $totals['b']];
        $metrics = [
            self::metric('distance', 'km', 1, $a->distanceM / 1000, $b->distanceM / 1000, MetricDelta::HIGHER_IS_BETTER),
            self::metric('time', 'min', 0, $a->movingSeconds / 60, $b->movingSeconds / 60, MetricDelta::HIGHER_IS_BETTER),
            self::metric('elevation', 'm', 0, $a->elevationM, $b->elevationM, MetricDelta::HIGHER_IS_BETTER),
            self::metric('sessions', 'count', 0, $a->sessions, $b->sessions, MetricDelta::HIGHER_IS_BETTER),
        ];

        foreach (self::speedMetrics($sport) as [$key, $sports, $qualifier]) {
            $familyA = self::sumSports($bySport['a'], $sports);
            $familyB = self::sumSports($bySport['b'], $sports);
            $metric = $key === 'pace'
                ? self::metric('pace', 'pace', 0, $familyA->paceSecondsPerKm(), $familyB->paceSecondsPerKm(), MetricDelta::LOWER_IS_BETTER, $qualifier)
                : self::metric('speed', 'kmh', 1, $familyA->speedKmh(), $familyB->speedKmh(), MetricDelta::HIGHER_IS_BETTER, $qualifier);
            if ($metric->delta->a !== null || $metric->delta->b !== null) {
                $metrics[] = $metric;
            }
        }

        if ($a->averageHeartrate() !== null || $b->averageHeartrate() !== null) {
            $metrics[] = self::metric('heartrate', 'bpm', 0, $a->averageHeartrate(), $b->averageHeartrate(), MetricDelta::NEUTRAL);
        }
        $metrics[] = self::metric('longest', 'km', 1, $a->maxDistanceM / 1000, $b->maxDistanceM / 1000, MetricDelta::HIGHER_IS_BETTER);
        $metrics[] = self::metric('biggest_climb', 'm', 0, $a->maxElevationM, $b->maxElevationM, MetricDelta::HIGHER_IS_BETTER);

        [$daysA, $daysB] = [$comparison->a->days(), $comparison->b->days()];
        $weekly = [
            self::metric('distance_week', 'km', 1, MetricDelta::perWeek($a->distanceM / 1000, $daysA), MetricDelta::perWeek($b->distanceM / 1000, $daysB), MetricDelta::HIGHER_IS_BETTER),
            self::metric('time_week', 'min', 0, MetricDelta::perWeek($a->movingSeconds / 60, $daysA), MetricDelta::perWeek($b->movingSeconds / 60, $daysB), MetricDelta::HIGHER_IS_BETTER),
            self::metric('sessions_week', 'count', 1, MetricDelta::perWeek($a->sessions, $daysA), MetricDelta::perWeek($b->sessions, $daysB), MetricDelta::HIGHER_IS_BETTER),
        ];

        $sports = [];
        if ($sport === null) {
            foreach (self::orderSports(array_keys($bySport['a'] + $bySport['b']), $sportOrder) as $slug) {
                $sa = $bySport['a'][$slug] ?? new PeriodTotals();
                $sb = $bySport['b'][$slug] ?? new PeriodTotals();
                $sports[] = ['slug' => $slug, 'metrics' => [
                    'distance'  => self::metric('distance', 'km', 1, $sa->distanceM / 1000, $sb->distanceM / 1000, MetricDelta::HIGHER_IS_BETTER),
                    'time'      => self::metric('time', 'min', 0, $sa->movingSeconds / 60, $sb->movingSeconds / 60, MetricDelta::HIGHER_IS_BETTER),
                    'elevation' => self::metric('elevation', 'm', 0, $sa->elevationM, $sb->elevationM, MetricDelta::HIGHER_IS_BETTER),
                    'sessions'  => self::metric('sessions', 'count', 0, $sa->sessions, $sb->sessions, MetricDelta::HIGHER_IS_BETTER),
                ]];
            }
        }

        return new ComparisonResult(
            $comparison,
            $sport,
            $a,
            $b,
            $metrics,
            $weekly,
            $sports,
            ComparisonSeries::cumulative($comparison->a, $comparison->b, $daily['a'], $daily['b'], $today),
            ComparisonSeries::buckets($comparison->a, $comparison->b, $daily['a'], $daily['b'], $today),
        );
    }

    /**
     * Allure / vitesse à afficher selon le filtre : [clé, sports agrégés, sport précisé dans le libellé].
     *
     * @return list<array{0: string, 1: list<string>, 2: string|null}>
     */
    public static function speedMetrics(?string $sport): array
    {
        return match (true) {
            $sport === null                             => [['pace', ['run'], 'run'], ['speed', ['ride'], 'ride']],
            in_array($sport, self::PACE_SPORTS, true)   => [['pace', [$sport], null]],
            in_array($sport, self::SPEED_SPORTS, true)  => [['speed', [$sport], null]],
            default                                     => [],
        };
    }

    private static function metric(string $key, string $unit, int $decimals, int|float|null $a, int|float|null $b, int $polarity, ?string $sport = null): MetricComparison
    {
        return new MetricComparison($key, $unit, $decimals, MetricDelta::compare(
            $a !== null ? (float) $a : null,
            $b !== null ? (float) $b : null,
            $polarity,
            $decimals,
        ), $sport);
    }

    /**
     * @param array<string, PeriodTotals> $bySport
     * @param list<string>                $sports
     */
    private static function sumSports(array $bySport, array $sports): PeriodTotals
    {
        $sum = new PeriodTotals();
        foreach ($sports as $slug) {
            if (isset($bySport[$slug])) {
                $sum = $sum->plus($bySport[$slug]);
            }
        }

        return $sum;
    }

    /**
     * @param list<int|string> $slugs
     * @param list<string>     $order
     *
     * @return list<string>
     */
    private static function orderSports(array $slugs, array $order): array
    {
        $slugs = array_map('strval', $slugs);
        $rank = array_flip($order);
        usort($slugs, static fn (string $x, string $y): int => [$rank[$x] ?? PHP_INT_MAX, $x] <=> [$rank[$y] ?? PHP_INT_MAX, $y]);

        return $slugs;
    }
}
