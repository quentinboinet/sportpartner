<?php

namespace App\Tests\Unit\Service\Stats;

use App\Service\Stats\MetricComparison;
use App\Service\Stats\PeriodComparator;
use App\Service\Stats\PeriodComparisonResolver;
use App\Service\Stats\DateRange;
use PHPUnit\Framework\TestCase;

/**
 * Comparaison à partir des lignes « jour x sport » : totaux, allure / vitesse selon le filtre, FC neutre,
 * records, moyennes par semaine, détail par sport, périodes vides.
 */
final class PeriodComparatorTest extends TestCase
{
    private const ORDER = ['run', 'trail', 'ride', 'swim', 'walk', 'hike', 'weight_training', 'other'];

    /**
     * @return array{day: string, sport: string, sessions: int, distanceM: float, movingSeconds: int, elevationM: float, maxDistanceM: float, maxElevationM: float, hrWeighted: float, hrSeconds: int, paceDistanceM: float, paceSeconds: int}
     */
    private static function row(string $day, string $sport, float $km, int $seconds, float $elevation = 0.0, ?float $hr = null, int $sessions = 1): array
    {
        $m = $km * 1000;

        return [
            'day' => $day, 'sport' => $sport, 'sessions' => $sessions,
            'distanceM' => $m, 'movingSeconds' => $seconds, 'elevationM' => $elevation,
            'maxDistanceM' => $m / $sessions, 'maxElevationM' => $elevation,
            'hrWeighted' => $hr !== null ? $hr * $seconds : 0.0, 'hrSeconds' => $hr !== null ? $seconds : 0,
            'paceDistanceM' => $m > 100 ? $m : 0.0, 'paceSeconds' => $m > 100 ? $seconds : 0,
        ];
    }

    /**
     * @param list<MetricComparison> $metrics
     *
     * @return array<string, MetricComparison>
     */
    private static function byKey(array $metrics): array
    {
        $out = [];
        foreach ($metrics as $m) {
            $out[$m->key] = $m;
        }

        return $out;
    }

    /** @return list<array{day: string, sport: string, sessions: int, distanceM: float, movingSeconds: int, elevationM: float, maxDistanceM: float, maxElevationM: float, hrWeighted: float, hrSeconds: int, paceDistanceM: float, paceSeconds: int}> */
    private static function rows(): array
    {
        return [
            // A : novembre 2026 (1er -> 16)
            self::row('2026-11-02', 'run', 10, 3000, 100, 150),          // 5:00 /km
            self::row('2026-11-05', 'run', 12, 3480, 50, 140),           // 4:50 /km
            self::row('2026-11-07', 'ride', 60, 7200, 800),              // 30 km/h
            self::row('2026-11-08', 'trail', 20, 9000, 1200, 145),
            self::row('2026-11-10', 'other', 0, 1800),                   // sans sport : compté avec "other"
            // B : octobre 2026 (1er -> 16)
            self::row('2026-10-03', 'run', 10, 3120, 80, 155),           // 5:12 /km
            self::row('2026-10-04', 'ride', 50, 7200, 600),              // 25 km/h
            self::row('2026-10-12', 'swim', 2, 2700),
            // Hors périodes : ignorées
            self::row('2026-10-20', 'run', 99, 30000),
            self::row('2026-11-17', 'run', 99, 30000),
        ];
    }

    public function testAllSports(): void
    {
        $c = PeriodComparisonResolver::preset('month', new \DateTimeImmutable('2026-11-16 08:00', new \DateTimeZone('UTC')));
        $r = PeriodComparator::compare($c, self::rows(), null, '2026-11-16', self::ORDER);

        self::assertSame(
            ['distance', 'time', 'elevation', 'sessions', 'pace', 'speed', 'heartrate', 'longest', 'biggest_climb'],
            array_map(static fn (MetricComparison $m): string => $m->key, $r->metrics)
        );
        $m = self::byKey($r->metrics);

        self::assertSame([102.0, 62.0, 40.0, 64.5, 'up', 'better'], [$m['distance']->delta->a, $m['distance']->delta->b, $m['distance']->delta->delta, $m['distance']->delta->percent, $m['distance']->delta->direction, $m['distance']->delta->tone]);
        self::assertSame([408.0, 217.0], [$m['time']->delta->a, $m['time']->delta->b]);           // 24 480 s, 13 020 s
        self::assertSame([2150.0, 680.0], [$m['elevation']->delta->a, $m['elevation']->delta->b]);
        self::assertSame([5.0, 3.0, 2.0], [$m['sessions']->delta->a, $m['sessions']->delta->b, $m['sessions']->delta->delta]);

        // Allure de la course seulement (run) : 6 480 s / 22 km = 294,5 s -> 295 (4:55) contre 312 (5:12) : plus rapide
        self::assertSame('run', $m['pace']->sport);
        self::assertSame([295.0, 312.0, -17.0, 'down', 'better'], [$m['pace']->delta->a, $m['pace']->delta->b, $m['pace']->delta->delta, $m['pace']->delta->direction, $m['pace']->delta->tone]);
        // Vitesse du vélo seulement (ride)
        self::assertSame('ride', $m['speed']->sport);
        self::assertSame([30.0, 25.0, 'better'], [$m['speed']->delta->a, $m['speed']->delta->b, $m['speed']->delta->tone]);
        // FC pondérée par le temps : (150x3000 + 140x3480 + 145x9000) / 15 480 = 144,6 -> 145 ; B : 155. Neutre.
        self::assertSame([145.0, 155.0, 'down', 'neutral'], [$m['heartrate']->delta->a, $m['heartrate']->delta->b, $m['heartrate']->delta->direction, $m['heartrate']->delta->tone]);
        self::assertSame([60.0, 50.0], [$m['longest']->delta->a, $m['longest']->delta->b]);
        self::assertSame([1200.0, 600.0], [$m['biggest_climb']->delta->a, $m['biggest_climb']->delta->b]);

        // Détail par sport, dans l'ordre de la table sports
        self::assertSame(['run', 'trail', 'ride', 'swim', 'other'], array_column($r->sports, 'slug'));
        $swim = $r->sports[3]['metrics'];
        self::assertSame([0.0, 2.0, -2.0, -100.0, 'worse'], [$swim['distance']->delta->a, $swim['distance']->delta->b, $swim['distance']->delta->delta, $swim['distance']->delta->percent, $swim['distance']->delta->tone]);
        $trail = $r->sports[1]['metrics'];
        self::assertSame([20.0, 0.0, null, 'better'], [$trail['distance']->delta->a, $trail['distance']->delta->b, $trail['distance']->delta->percent, $trail['distance']->delta->tone]);

        // Cumul : dernier point = total ; même longueur, jours alignés
        self::assertSame(16, $r->cumulative['days']);
        self::assertSame(102.0, $r->cumulative['values']['distance']['a'][15]);
        self::assertSame(62.0, $r->cumulative['values']['distance']['b'][15]);
        self::assertSame('week', $r->buckets['granularity']);
        self::assertSame([82.0, 20.0, 0.0], $r->buckets['values']['distance']['a']);   // 1-7, 8-14, 15-16
    }

    public function testPaceWhenOneRunningSportIsSelected(): void
    {
        $c = PeriodComparisonResolver::preset('month', new \DateTimeImmutable('2026-11-16'));
        $r = PeriodComparator::compare($c, self::rows(), 'run', '2026-11-16', self::ORDER);
        $m = self::byKey($r->metrics);

        self::assertArrayHasKey('pace', $m);
        self::assertArrayNotHasKey('speed', $m);
        self::assertNull($m['pace']->sport);
        self::assertSame([22.0, 10.0], [$m['distance']->delta->a, $m['distance']->delta->b]);
        self::assertSame([2.0, 1.0], [$m['sessions']->delta->a, $m['sessions']->delta->b]);
        self::assertSame('better', $m['pace']->delta->tone);
        self::assertSame([], $r->sports, 'no per-sport breakdown with one sport');

        // Trail : allure du trail (9 000 s / 20 km = 450 s) ; B sans trail : pas d'écart
        $m = self::byKey(PeriodComparator::compare($c, self::rows(), 'trail', '2026-11-16', self::ORDER)->metrics);
        self::assertSame([450.0, null, 'none'], [$m['pace']->delta->a, $m['pace']->delta->b, $m['pace']->delta->tone]);
    }

    public function testSpeedWhenRideIsSelected(): void
    {
        $c = PeriodComparisonResolver::preset('month', new \DateTimeImmutable('2026-11-16'));
        $m = self::byKey(PeriodComparator::compare($c, self::rows(), 'ride', '2026-11-16', self::ORDER)->metrics);

        self::assertArrayNotHasKey('pace', $m);
        self::assertArrayNotHasKey('heartrate', $m, 'no HR on rides here');
        self::assertSame([30.0, 25.0, 5.0, 20.0, 'up', 'better'], [$m['speed']->delta->a, $m['speed']->delta->b, $m['speed']->delta->delta, $m['speed']->delta->percent, $m['speed']->delta->direction, $m['speed']->delta->tone]);
    }

    public function testNoPaceNorSpeedForOtherSports(): void
    {
        $c = PeriodComparisonResolver::preset('month', new \DateTimeImmutable('2026-11-16'));
        $m = self::byKey(PeriodComparator::compare($c, self::rows(), 'swim', '2026-11-16', self::ORDER)->metrics);

        self::assertSame(['distance', 'time', 'elevation', 'sessions', 'longest', 'biggest_climb'], array_keys($m));
        self::assertSame(PeriodComparator::speedMetrics('weight_training'), []);
    }

    public function testWeeklyAveragesNormaliseDifferentLengths(): void
    {
        // A : 14 jours, 140 km ; B : 7 jours, 70 km -> 70 km par semaine des deux côtés
        $tz = new \DateTimeZone('UTC');
        $c = PeriodComparisonResolver::custom(DateRange::ofDays('2026-11-01', '2026-11-14', $tz), DateRange::ofDays('2026-10-01', '2026-10-07', $tz));
        $rows = [self::row('2026-11-03', 'run', 140, 50400, 0, null, 14), self::row('2026-10-02', 'run', 70, 25200, 0, null, 7)];
        $r = PeriodComparator::compare($c, $rows, null, '2026-11-16', self::ORDER);

        $total = self::byKey($r->metrics)['distance']->delta;
        self::assertSame([140.0, 70.0, 100.0, 'better'], [$total->a, $total->b, $total->percent, $total->tone]);
        $weekly = self::byKey($r->weekly);
        self::assertSame([70.0, 70.0, 0.0, 'same'], [$weekly['distance_week']->delta->a, $weekly['distance_week']->delta->b, $weekly['distance_week']->delta->delta, $weekly['distance_week']->delta->tone]);
        self::assertSame([420.0, 420.0], [$weekly['time_week']->delta->a, $weekly['time_week']->delta->b]);
        self::assertSame([7.0, 7.0], [$weekly['sessions_week']->delta->a, $weekly['sessions_week']->delta->b]);
        self::assertSame(1, $weekly['sessions_week']->decimals);
        // Cumul aligné par jour : B s'arrête après 7 jours
        self::assertSame(14, $r->cumulative['days']);
        self::assertNull($r->cumulative['values']['distance']['b'][7]);
    }

    public function testOverlappingCustomPeriodsCountSharedDaysInBoth(): void
    {
        $tz = new \DateTimeZone('UTC');
        $c = PeriodComparisonResolver::custom(DateRange::ofDays('2026-11-01', '2026-11-10', $tz), DateRange::ofDays('2026-11-05', '2026-11-14', $tz));
        $r = PeriodComparator::compare($c, [self::row('2026-11-06', 'run', 10, 3000), self::row('2026-11-12', 'run', 5, 1500)], null, '2026-11-16', self::ORDER);

        $d = self::byKey($r->metrics)['distance']->delta;
        self::assertSame([10.0, 15.0], [$d->a, $d->b]);
    }

    public function testEmptyPeriods(): void
    {
        $c = PeriodComparisonResolver::preset('week', new \DateTimeImmutable('2026-11-16'));
        $r = PeriodComparator::compare($c, [], null, '2026-11-16', self::ORDER);

        self::assertSame(['distance', 'time', 'elevation', 'sessions', 'longest', 'biggest_climb'], array_map(static fn (MetricComparison $m): string => $m->key, $r->metrics));
        foreach (array_merge($r->metrics, $r->weekly) as $m) {
            self::assertSame(0.0, $m->delta->a, $m->key);
            self::assertSame(0.0, $m->delta->b, $m->key);
            self::assertNull($m->delta->delta, $m->key);
            self::assertNull($m->delta->percent, $m->key);
            self::assertSame('none', $m->delta->tone, $m->key);
        }
        self::assertSame([], $r->sports);
        self::assertSame([0.0], $r->cumulative['values']['distance']['a']);
        self::assertSame([0], $r->buckets['values']['sessions']['b']);
    }
}
