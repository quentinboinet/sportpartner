<?php

namespace App\Tests\Unit\Service\Stats;

use App\Service\Stats\ComparisonSeries;
use App\Service\Stats\DateRange;
use App\Service\Stats\PeriodTotals;
use PHPUnit\Framework\TestCase;

/**
 * Séries des graphiques : cumul jour par jour (A et B alignés par indice, arrêt à aujourd'hui) et tranches
 * (jours, blocs de 7 jours, mois civils).
 */
final class ComparisonSeriesTest extends TestCase
{
    private static function range(string $from, string $to, string $tz = 'UTC'): DateRange
    {
        return DateRange::ofDays($from, $to, new \DateTimeZone($tz));
    }

    private static function day(float $distanceM, int $seconds, float $elevation = 0.0, int $sessions = 1): PeriodTotals
    {
        return new PeriodTotals($sessions, $distanceM, $seconds, $elevation);
    }

    public function testGranularityThresholds(): void
    {
        self::assertSame('day', ComparisonSeries::granularity(1));
        self::assertSame('day', ComparisonSeries::granularity(14));
        self::assertSame('week', ComparisonSeries::granularity(15));
        self::assertSame('week', ComparisonSeries::granularity(120));
        self::assertSame('month', ComparisonSeries::granularity(121));
        self::assertSame('month', ComparisonSeries::granularity(1827));
    }

    public function testCumulativeStopsAtTodayAndAtTheEndOfEachPeriod(): void
    {
        $a = self::range('2026-11-13', '2026-11-16');
        $b = self::range('2026-10-13', '2026-10-14');
        $dailyA = [
            '2026-11-13' => self::day(10_000, 3000, 100),
            '2026-11-15' => self::day(1_234.5, 600, 20.4, 2),
            '2026-11-16' => self::day(5_000, 1500), // après "aujourd'hui" : ignoré
        ];
        $dailyB = ['2026-10-14' => self::day(21_097, 6000, 50)];

        $s = ComparisonSeries::cumulative($a, $b, $dailyA, $dailyB, '2026-11-15');

        self::assertSame(4, $s['days']);
        self::assertSame(['2026-11-13', '2026-11-14', '2026-11-15', '2026-11-16'], $s['dates']['a']);
        self::assertSame(['2026-10-13', '2026-10-14'], $s['dates']['b']);
        self::assertSame([10.0, 10.0, 11.2, null], $s['values']['distance']['a']);
        self::assertSame([50, 50, 60, null], $s['values']['time']['a']);
        self::assertSame([100, 100, 120, null], $s['values']['elevation']['a']);
        self::assertSame([1, 1, 3, null], $s['values']['sessions']['a']);
        // B : 2 jours seulement, puis null
        self::assertSame([0.0, 21.1, null, null], $s['values']['distance']['b']);
        self::assertSame([0, 100, null, null], $s['values']['time']['b']);
        self::assertSame([0, 1, null, null], $s['values']['sessions']['b']);
    }

    public function testCumulativeSumsMetresBeforeRounding(): void
    {
        $r = self::range('2026-01-01', '2026-01-03');
        $daily = ['2026-01-01' => self::day(1_049, 29), '2026-01-02' => self::day(1_049, 29), '2026-01-03' => self::day(1_049, 29)];

        $s = ComparisonSeries::cumulative($r, $r, $daily, [], '2026-12-31');

        // 1,049 -> 2,098 -> 3,147 km (et non 1,0 + 1,0 + 1,0) ; 29 -> 58 -> 87 s
        self::assertSame([1.0, 2.1, 3.1], $s['values']['distance']['a']);
        self::assertSame([0, 1, 1], $s['values']['time']['a']);
        self::assertSame([0.0, 0.0, 0.0], $s['values']['distance']['b']);
    }

    public function testCumulativeAcrossDaylightSavingTime(): void
    {
        $a = self::range('2026-03-01', '2026-03-31', 'Europe/Paris');
        $b = self::range('2026-02-01', '2026-02-28', 'Europe/Paris');

        $s = ComparisonSeries::cumulative($a, $b, ['2026-03-30' => self::day(5_000, 1800)], [], '2026-12-31');

        self::assertSame(31, $s['days']);
        self::assertCount(31, $s['dates']['a']);
        self::assertSame('2026-03-29', $s['dates']['a'][28]);
        self::assertSame('2026-03-30', $s['dates']['a'][29]);
        self::assertSame(0.0, $s['values']['distance']['a'][28]);
        self::assertSame(5.0, $s['values']['distance']['a'][29]);
        self::assertCount(28, $s['dates']['b']);
        self::assertNull($s['values']['distance']['b'][28]);
    }

    public function testWeekBucketsAreSevenDayBlocksFromTheStart(): void
    {
        // 30 derniers jours (le 16/11/2026) : 5 tranches (7, 7, 7, 7, 2 jours)
        $a = self::range('2026-10-18', '2026-11-16');
        $b = self::range('2026-09-18', '2026-10-17');
        $dailyA = [
            '2026-10-18' => self::day(10_000, 3600),
            '2026-10-24' => self::day(5_000, 1800),
            '2026-10-25' => self::day(8_000, 2400, 80),
            '2026-11-15' => self::day(12_000, 3600),
        ];
        $dailyB = ['2026-10-17' => self::day(42_195, 12_600, 300)];

        $s = ComparisonSeries::buckets($a, $b, $dailyA, $dailyB, '2026-11-16');

        self::assertSame('week', $s['granularity']);
        self::assertCount(5, $s['buckets']);
        self::assertSame(['from' => '2026-10-18', 'to' => '2026-10-24'], $s['buckets'][0]['a']);
        self::assertSame(['from' => '2026-11-15', 'to' => '2026-11-16'], $s['buckets'][4]['a']);
        self::assertSame(['from' => '2026-10-16', 'to' => '2026-10-17'], $s['buckets'][4]['b']);
        self::assertSame([15.0, 8.0, 0.0, 0.0, 12.0], $s['values']['distance']['a']);
        self::assertSame([90, 40, 0, 0, 60], $s['values']['time']['a']);
        self::assertSame([2, 1, 0, 0, 1], $s['values']['sessions']['a']);
        self::assertSame([0.0, 0.0, 0.0, 0.0, 42.2], $s['values']['distance']['b']);
        self::assertSame([0, 0, 0, 0, 300], $s['values']['elevation']['b']);
    }

    public function testBucketsAfterTodayAreNull(): void
    {
        // Mois en cours complet (période libre) vu le 10 : les jours suivants n'existent pas encore
        $a = self::range('2026-11-01', '2026-11-30');
        $b = self::range('2026-10-01', '2026-10-30');

        $s = ComparisonSeries::buckets($a, $b, ['2026-11-02' => self::day(3_000, 900)], [], '2026-11-10');

        self::assertSame([3.0, 0.0, null, null, null], $s['values']['distance']['a']);
        self::assertSame([0.0, 0.0, 0.0, 0.0, 0.0], $s['values']['distance']['b']);
    }

    public function testDayBuckets(): void
    {
        $a = self::range('2026-11-16', '2026-11-22');
        $b = self::range('2026-11-09', '2026-11-15');

        $s = ComparisonSeries::buckets($a, $b, ['2026-11-18' => self::day(7_000, 2100)], ['2026-11-15' => self::day(30_000, 9000)], '2026-11-22');

        self::assertSame('day', $s['granularity']);
        self::assertCount(7, $s['buckets']);
        self::assertSame(['from' => '2026-11-18', 'to' => '2026-11-18'], $s['buckets'][2]['a']);
        self::assertSame([0.0, 0.0, 7.0, 0.0, 0.0, 0.0, 0.0], $s['values']['distance']['a']);
        self::assertSame([0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 30.0], $s['values']['distance']['b']);
    }

    public function testMonthBucketsAreCalendarMonthsAlignedByIndex(): void
    {
        // Depuis le 1er janvier (320 jours) : 11 mois, le dernier partiel
        $a = self::range('2026-01-01', '2026-11-16');
        $b = self::range('2025-01-01', '2025-11-16');
        $s = ComparisonSeries::buckets($a, $b, ['2026-02-28' => self::day(10_000, 3000), '2026-11-16' => self::day(5_000, 1500)], ['2025-01-31' => self::day(20_000, 6000)], '2026-11-16');

        self::assertSame('month', $s['granularity']);
        self::assertCount(11, $s['buckets']);
        self::assertSame(['from' => '2026-02-01', 'to' => '2026-02-28'], $s['buckets'][1]['a']);
        self::assertSame(['from' => '2026-11-01', 'to' => '2026-11-16'], $s['buckets'][10]['a']);
        self::assertSame(['from' => '2025-11-01', 'to' => '2025-11-16'], $s['buckets'][10]['b']);
        self::assertSame(10.0, $s['values']['distance']['a'][1]);
        self::assertSame(5.0, $s['values']['distance']['a'][10]);
        self::assertSame(20.0, $s['values']['distance']['b'][0]);

        // Période libre qui commence en milieu de mois ; B plus courte : tranches manquantes à null
        $a = self::range('2026-01-15', '2026-06-10');
        $b = self::range('2025-01-31', '2025-05-31');
        $s = ComparisonSeries::buckets($a, $b, [], ['2025-03-01' => self::day(1_000, 600)], '2026-11-16');
        self::assertSame(
            [['2026-01-15', '2026-01-31'], ['2026-02-01', '2026-02-28'], ['2026-03-01', '2026-03-31'], ['2026-04-01', '2026-04-30'], ['2026-05-01', '2026-05-31'], ['2026-06-01', '2026-06-10']],
            array_map(static fn (array $bucket): array => [$bucket['a']['from'] ?? '', $bucket['a']['to'] ?? ''], $s['buckets'])
        );
        self::assertSame(['from' => '2025-01-31', 'to' => '2025-01-31'], $s['buckets'][0]['b']);
        self::assertNull($s['buckets'][5]['b']);
        self::assertSame([0.0, 0.0, 1.0, 0.0, 0.0, null], $s['values']['distance']['b']);
    }

    public function testSplit(): void
    {
        $leap = self::range('2028-02-20', '2028-03-05');
        self::assertSame(
            [['from' => '2028-02-20', 'to' => '2028-02-29'], ['from' => '2028-03-01', 'to' => '2028-03-05']],
            ComparisonSeries::split($leap, 'month')
        );
        self::assertSame(
            [['from' => '2028-02-20', 'to' => '2028-02-26'], ['from' => '2028-02-27', 'to' => '2028-03-04'], ['from' => '2028-03-05', 'to' => '2028-03-05']],
            ComparisonSeries::split($leap, 'week')
        );
        self::assertCount(15, ComparisonSeries::split($leap, 'day'));
    }
}
