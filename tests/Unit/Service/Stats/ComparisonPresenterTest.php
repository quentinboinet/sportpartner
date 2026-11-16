<?php

namespace App\Tests\Unit\Service\Stats;

use App\Service\Stats\ComparisonFormat as F;
use App\Service\Stats\ComparisonPresenter;
use App\Service\Stats\DateRange;
use App\Service\Stats\MetricComparison;
use App\Service\Stats\MetricDelta;
use App\Service\Stats\PeriodComparator;
use App\Service\Stats\PeriodComparisonResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Textes affichés (fr / en) : nombres, durées, allures, écarts signés, pourcentages, périodes, et données
 * des graphiques (libellés des tranches, lignes du tableau de données, détail par sport).
 */
final class ComparisonPresenterTest extends TestCase
{
    private const MONTHS_FR = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

    /** @return iterable<string, array{string, string}> */
    public static function formatProvider(): iterable
    {
        yield 'thousands fr' => [F::number(1234.56, 1, 'fr'), '1 234,6'];
        yield 'thousands en' => [F::number(1234.56, 1, 'en'), '1,234.6'];
        yield 'integer' => [F::number(12.5, 0, 'fr'), '13'];
        yield 'negative' => [F::number(-2.25, 1, 'fr'), '-2,3'];
        yield 'negative zero' => [F::number(-0.04, 1, 'en'), '0.0'];
        yield 'minutes' => [F::duration(45, 'fr'), '45 min'];
        yield 'zero minutes' => [F::duration(0, 'en'), '0 min'];
        yield 'hours' => [F::duration(120, 'fr'), '2h'];
        yield 'hours minutes fr' => [F::duration(65, 'fr'), '1h05'];
        yield 'hours minutes en' => [F::duration(65, 'en'), '1h 05m'];
        yield 'many hours' => [F::duration(61_000, 'fr'), '1 016h40'];
        yield 'pace' => [F::pace(307), '5:07'];
        yield 'value km' => [F::value(102.0, 'km', 1, 'fr'), '102,0 km'];
        yield 'value duration' => [F::value(408.0, 'min', 0, 'fr'), '6h48'];
        yield 'value pace' => [F::value(295.0, 'pace', 0, 'fr'), '4:55 /km'];
        yield 'value speed' => [F::value(27.5, 'kmh', 1, 'en'), '27.5 km/h'];
        yield 'value bpm' => [F::value(145.0, 'bpm', 0, 'fr'), '145 bpm'];
        yield 'value count' => [F::value(2.5, 'count', 1, 'fr'), '2,5'];
        yield 'value null' => [F::value(null, 'km', 1, 'fr'), '-'];
        yield 'signed km' => [F::signed(12.5, 'km', 1, 'fr'), '+12,5 km'];
        yield 'signed duration' => [F::signed(-45.0, 'min', 0, 'fr'), '-45 min'];
        yield 'signed long duration' => [F::signed(-65.0, 'min', 0, 'en'), '-1h 05m'];
        yield 'signed pace (faster)' => [F::signed(-12.0, 'pace', 0, 'fr'), '-0:12 /km'];
        yield 'signed zero' => [F::signed(0.0, 'km', 1, 'fr'), '0,0 km'];
        yield 'signed null' => [F::signed(null, 'km', 1, 'fr'), '-'];
        yield 'percent small fr' => [F::percent(4.25, 'fr'), '+4,3 %'];
        yield 'percent round fr' => [F::percent(5.0, 'fr'), '+5 %'];
        yield 'percent big' => [F::percent(64.5, 'fr'), '+65 %'];
        yield 'percent negative en' => [F::percent(-3.8, 'en'), '-3.8%'];
        yield 'percent -100' => [F::percent(-100.0, 'fr'), '-100 %'];
        yield 'percent zero' => [F::percent(0.0, 'fr'), '0 %'];
        yield 'day' => [F::day('2026-11-01', self::MONTHS_FR), '1 nov. 2026'];
        yield 'range same year' => [F::range('2026-11-01', '2026-11-16', self::MONTHS_FR), '1 nov. → 16 nov. 2026'];
        yield 'range across years' => [F::range('2025-12-28', '2026-01-03', self::MONTHS_FR), '28 déc. 2025 → 3 janv. 2026'];
        yield 'range one day' => [F::range('2026-11-16', '2026-11-16', self::MONTHS_FR), '16 nov. 2026'];
        yield 'range without year' => [F::range('2026-11-01', '2026-11-07', self::MONTHS_FR, false), '1 nov. → 7 nov.'];
    }

    #[DataProvider('formatProvider')]
    public function testFormat(string $actual, string $expected): void
    {
        self::assertSame($expected, $actual);
    }

    public function testMetricTexts(): void
    {
        $pace = new MetricComparison('pace', 'pace', 0, MetricDelta::compare(295.0, 312.0, MetricDelta::LOWER_IS_BETTER, 0), 'run');
        self::assertSame([
            'key' => 'pace', 'sport' => 'run', 'a' => '4:55 /km', 'b' => '5:12 /km', 'delta' => '-0:17 /km',
            'percent' => '-5,4 %', 'direction' => 'down', 'tone' => 'better',
        ], ComparisonPresenter::metric($pace, 'fr'));

        $empty = new MetricComparison('distance', 'km', 1, MetricDelta::compare(0.0, 0.0, MetricDelta::HIGHER_IS_BETTER, 1));
        self::assertSame(['0,0 km', '0,0 km', '-', null, 'none'], array_values(array_intersect_key(ComparisonPresenter::metric($empty, 'fr'), array_flip(['a', 'b', 'delta', 'percent', 'tone']))));
    }

    public function testOverviewDeltasFromSqlRows(): void
    {
        // Lignes de getSeasonStats() : valeurs SQL en chaînes
        $current = ['cnt' => '12', 'total_distance' => '152400.5', 'total_time' => '54000', 'total_elevation' => '1830.4'];
        $previous = ['cnt' => '10', 'total_distance' => '120000', 'total_time' => '54000', 'total_elevation' => '0'];

        $d = ComparisonPresenter::overview($current, $previous, 'fr');

        self::assertSame(['distance', 'time', 'elevation', 'sessions'], array_keys($d));
        self::assertSame(['+27 %', '120,0 km', 'better'], [$d['distance']['percent'], $d['distance']['b'], $d['distance']['tone']]);
        self::assertSame(['0 %', 'flat', 'same', '15h'], [$d['time']['percent'], $d['time']['direction'], $d['time']['tone'], $d['time']['b']]);
        self::assertSame([null, '+1 830 m'], [$d['elevation']['percent'], $d['elevation']['delta']]);
        self::assertSame(['+20 %', '+2'], [$d['sessions']['percent'], $d['sessions']['delta']]);

        // Période précédente vide et période en cours vide : « - »
        $zero = ['cnt' => '0', 'total_distance' => '0', 'total_time' => '0', 'total_elevation' => '0'];
        self::assertSame('none', ComparisonPresenter::overview($zero, $zero, 'en')['distance']['direction']);
    }

    public function testChartPayload(): void
    {
        $c = PeriodComparisonResolver::preset('month', new \DateTimeImmutable('2026-11-16'));
        $rows = [
            ['day' => '2026-11-02', 'sport' => 'run', 'sessions' => 1, 'distanceM' => 10000.0, 'movingSeconds' => 3000, 'elevationM' => 100.0, 'maxDistanceM' => 10000.0, 'maxElevationM' => 100.0, 'hrWeighted' => 0.0, 'hrSeconds' => 0, 'paceDistanceM' => 10000.0, 'paceSeconds' => 3000],
            ['day' => '2026-10-09', 'sport' => 'ride', 'sessions' => 1, 'distanceM' => 40000.0, 'movingSeconds' => 5400, 'elevationM' => 300.0, 'maxDistanceM' => 40000.0, 'maxElevationM' => 300.0, 'hrWeighted' => 0.0, 'hrSeconds' => 0, 'paceDistanceM' => 40000.0, 'paceSeconds' => 5400],
        ];
        $result = PeriodComparator::compare($c, $rows, null, '2026-11-16', ['run', 'ride']);

        $chart = ComparisonPresenter::chart($result, 'fr', self::MONTHS_FR, ['run' => 'Course', 'ride' => 'Vélo'], ['run' => '#E8400C'], ['day' => 'J', 'week' => 'S']);

        self::assertSame('week', $chart['buckets']['granularity']);
        self::assertSame(['S1', 'S2', 'S3'], $chart['buckets']['labels']);
        self::assertSame(['a' => '1 nov. → 7 nov.', 'b' => '1 oct. → 7 oct.'], $chart['buckets']['ranges'][0]);
        self::assertSame(['a' => '15 nov. → 16 nov.', 'b' => '15 oct. → 16 oct.'], $chart['buckets']['ranges'][2]);
        self::assertSame(['10,0 km', '0,0 km', '+10,0 km', null, 'better'], [
            $chart['buckets']['rows']['distance'][0]['a'], $chart['buckets']['rows']['distance'][0]['b'],
            $chart['buckets']['rows']['distance'][0]['delta'], $chart['buckets']['rows']['distance'][0]['percent'],
            $chart['buckets']['rows']['distance'][0]['tone'],
        ]);
        self::assertSame(['0,0 km', '40,0 km', '-40,0 km', '-100 %', 'worse'], [
            $chart['buckets']['rows']['distance'][1]['a'], $chart['buckets']['rows']['distance'][1]['b'],
            $chart['buckets']['rows']['distance'][1]['delta'], $chart['buckets']['rows']['distance'][1]['percent'],
            $chart['buckets']['rows']['distance'][1]['tone'],
        ]);
        self::assertSame('1h30', $chart['buckets']['rows']['time'][1]['b']);
        self::assertSame(['10,0 km', '40,0 km'], [$chart['totals']['distance']['a'], $chart['totals']['distance']['b']]);

        self::assertNotNull($chart['sports']);
        self::assertSame(['run', 'ride'], $chart['sports']['slugs']);
        self::assertSame(['Course', 'Vélo'], $chart['sports']['labels']);
        self::assertSame(['#E8400C', '#9CA3AF'], $chart['sports']['colors']);
        self::assertSame(['a' => [10.0, 0.0], 'b' => [0.0, 40.0]], $chart['sports']['values']['distance']);
        self::assertSame(['a' => [1.0, 0.0], 'b' => [0.0, 1.0]], $chart['sports']['values']['sessions']);

        // JSON valide (données de l'attribut Stimulus)
        self::assertJson((string) json_encode($chart));
    }

    public function testMonthLabelsAddTheYearWhenAPeriodSpansYears(): void
    {
        $tz = new \DateTimeZone('UTC');
        $c = PeriodComparisonResolver::custom(DateRange::ofDays('2025-11-01', '2026-04-30', $tz), DateRange::ofDays('2024-11-01', '2025-04-30', $tz));
        $result = PeriodComparator::compare($c, [], 'run', '2026-11-16', []);
        $chart = ComparisonPresenter::chart($result, 'fr', self::MONTHS_FR, [], [], ['day' => 'J', 'week' => 'S']);

        self::assertSame(['nov. 25', 'déc. 25', 'janv. 26', 'févr. 26', 'mars 26', 'avr. 26'], $chart['buckets']['labels']);
        self::assertNull($chart['sports']);

        $c = PeriodComparisonResolver::preset('ytd', new \DateTimeImmutable('2026-03-10'));
        $chart = ComparisonPresenter::chart(PeriodComparator::compare($c, [], null, '2026-03-10', []), 'fr', self::MONTHS_FR, [], [], ['day' => 'J', 'week' => 'S']);
        self::assertSame(['S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7', 'S8', 'S9', 'S10'], $chart['buckets']['labels']);

        $c = PeriodComparisonResolver::preset('last_year', new \DateTimeImmutable('2026-11-16'));
        $chart = ComparisonPresenter::chart(PeriodComparator::compare($c, [], null, '2026-11-16', []), 'fr', self::MONTHS_FR, [], [], ['day' => 'J', 'week' => 'S']);
        self::assertSame(self::MONTHS_FR, $chart['buckets']['labels']);
    }
}
