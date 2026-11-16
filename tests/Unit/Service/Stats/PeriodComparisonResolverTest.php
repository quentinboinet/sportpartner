<?php

namespace App\Tests\Unit\Service\Stats;

use App\Service\Stats\DateRange;
use App\Service\Stats\PeriodComparison;
use App\Service\Stats\PeriodComparisonResolver as R;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Périodes de la vue « Comparer » : chaque préréglage, règles « à date », fins de mois, années bissextiles,
 * bornes lundi / dimanche, changements d'heure, et validation des paramètres de la requête.
 */
final class PeriodComparisonResolverTest extends TestCase
{
    private const SPORTS = ['run', 'trail', 'ride', 'swim', 'walk', 'hike', 'weight_training', 'other'];

    private static function at(string $datetime, string $tz = 'UTC'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($datetime, new \DateTimeZone($tz));
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} */
    private static function keys(PeriodComparison $c): array
    {
        return [$c->a->fromKey(), $c->a->toKey(), $c->b->fromKey(), $c->b->toKey()];
    }

    /** @return iterable<string, array{string, string, list<string>, bool, ?string}> */
    public static function presetProvider(): iterable
    {
        // Lundi 16/11/2026 (date de référence de la machine)
        yield 'week, Monday: one day vs last Monday' => ['week', '2026-11-16 08:00', ['2026-11-16', '2026-11-16', '2026-11-09', '2026-11-09'], true, null];
        yield 'week, Wednesday' => ['week', '2026-11-18 12:00', ['2026-11-16', '2026-11-18', '2026-11-09', '2026-11-11'], true, null];
        yield 'week, Sunday night: two full weeks' => ['week', '2026-11-22 23:59:59', ['2026-11-16', '2026-11-22', '2026-11-09', '2026-11-15'], true, null];
        yield 'week across new year' => ['week', '2027-01-07 09:00', ['2027-01-04', '2027-01-07', '2026-12-28', '2026-12-31'], true, null];
        yield 'week, Monday 4 Jan' => ['week', '2027-01-04 00:00', ['2027-01-04', '2027-01-04', '2026-12-28', '2026-12-28'], true, null];

        yield 'month to date' => ['month', '2026-11-16 08:00', ['2026-11-01', '2026-11-16', '2026-10-01', '2026-10-16'], true, null];
        yield 'month, January vs December' => ['month', '2026-01-16 08:00', ['2026-01-01', '2026-01-16', '2025-12-01', '2025-12-16'], true, null];
        yield 'month, 15 March vs February' => ['month', '2026-03-15 08:00', ['2026-03-01', '2026-03-15', '2026-02-01', '2026-02-15'], true, null];
        yield 'month, 30 March: February clamped' => ['month', '2026-03-30 08:00', ['2026-03-01', '2026-03-30', '2026-02-01', '2026-02-28'], true, PeriodComparison::NOTE_CLAMPED];
        yield 'month, 31 March: full months' => ['month', '2026-03-31 08:00', ['2026-03-01', '2026-03-31', '2026-02-01', '2026-02-28'], true, PeriodComparison::NOTE_FULL_MONTH];
        yield 'month, 29 March leap year: 29 Feb exists' => ['month', '2028-03-29 08:00', ['2028-03-01', '2028-03-29', '2028-02-01', '2028-02-29'], true, null];
        yield 'month, 30 April: all of March' => ['month', '2026-04-30 08:00', ['2026-04-01', '2026-04-30', '2026-03-01', '2026-03-31'], true, PeriodComparison::NOTE_FULL_MONTH];
        yield 'month, 29 April' => ['month', '2026-04-29 08:00', ['2026-04-01', '2026-04-29', '2026-03-01', '2026-03-29'], true, null];
        yield 'month, 28 Feb (non leap, last day)' => ['month', '2026-02-28 08:00', ['2026-02-01', '2026-02-28', '2026-01-01', '2026-01-31'], true, PeriodComparison::NOTE_FULL_MONTH];
        yield 'month, 28 Feb leap year (not last day)' => ['month', '2028-02-28 08:00', ['2028-02-01', '2028-02-28', '2028-01-01', '2028-01-28'], true, null];
        yield 'month, 29 Feb leap year (last day)' => ['month', '2028-02-29 08:00', ['2028-02-01', '2028-02-29', '2028-01-01', '2028-01-31'], true, PeriodComparison::NOTE_FULL_MONTH];

        yield 'same month last year' => ['month_yoy', '2026-11-16 08:00', ['2026-11-01', '2026-11-16', '2025-11-01', '2025-11-16'], true, null];
        yield 'same month last year, 29 Feb' => ['month_yoy', '2028-02-29 08:00', ['2028-02-01', '2028-02-29', '2027-02-01', '2027-02-28'], true, PeriodComparison::NOTE_FULL_MONTH];
        yield 'same month last year, 28 Feb after leap' => ['month_yoy', '2029-02-28 08:00', ['2029-02-01', '2029-02-28', '2028-02-01', '2028-02-29'], true, PeriodComparison::NOTE_FULL_MONTH];
        yield 'same month last year, 28 Feb leap (not last)' => ['month_yoy', '2028-02-28 08:00', ['2028-02-01', '2028-02-28', '2027-02-01', '2027-02-28'], true, null];

        yield 'year to date' => ['ytd', '2026-11-16 08:00', ['2026-01-01', '2026-11-16', '2025-01-01', '2025-11-16'], true, null];
        yield 'year to date, 1 Jan' => ['ytd', '2026-01-01 00:00', ['2026-01-01', '2026-01-01', '2025-01-01', '2025-01-01'], true, null];
        yield 'year to date, 31 Dec' => ['ytd', '2026-12-31 23:00', ['2026-01-01', '2026-12-31', '2025-01-01', '2025-12-31'], true, null];
        yield 'year to date, 29 Feb' => ['ytd', '2028-02-29 08:00', ['2028-01-01', '2028-02-29', '2027-01-01', '2027-02-28'], true, PeriodComparison::NOTE_LEAP_DAY];
        yield 'year to date, 1 Mar after leap day' => ['ytd', '2028-03-01 08:00', ['2028-01-01', '2028-03-01', '2027-01-01', '2027-03-01'], true, null];

        yield '30 days' => ['30d', '2026-11-16 08:00', ['2026-10-18', '2026-11-16', '2026-09-18', '2026-10-17'], false, null];
        yield '30 days over February' => ['30d', '2026-03-01 08:00', ['2026-01-31', '2026-03-01', '2026-01-01', '2026-01-30'], false, null];

        yield 'last year vs the one before' => ['last_year', '2026-11-16 08:00', ['2025-01-01', '2025-12-31', '2024-01-01', '2024-12-31'], false, null];
        yield 'last year on 1 Jan' => ['last_year', '2026-01-01 00:00', ['2025-01-01', '2025-12-31', '2024-01-01', '2024-12-31'], false, null];
    }

    /** @param list<string> $expected */
    #[DataProvider('presetProvider')]
    public function testPresets(string $preset, string $now, array $expected, bool $toDate, ?string $note): void
    {
        $c = R::preset($preset, self::at($now));

        self::assertSame($preset, $c->preset);
        self::assertSame($expected, self::keys($c));
        self::assertSame($toDate, $c->toDate);
        self::assertSame($note, $c->note);
        // Jours entiers
        self::assertSame('00:00:00', $c->a->from->format('H:i:s'));
        self::assertSame('23:59:59', $c->a->to->format('H:i:s'));
        self::assertSame('00:00:00', $c->b->from->format('H:i:s'));
        self::assertSame('23:59:59', $c->b->to->format('H:i:s'));
    }

    public function testToDatePresetsCompareTheSameNumberOfElapsedDays(): void
    {
        $now = self::at('2026-11-16 08:00');
        foreach (['week', 'month', 'month_yoy', 'ytd', '30d'] as $preset) {
            $c = R::preset($preset, $now);
            self::assertTrue($c->sameLength(), $preset);
        }
        self::assertSame(16, R::preset('month', $now)->a->days());
        self::assertSame(320, R::preset('ytd', $now)->a->days());
        self::assertSame(1, R::preset('week', $now)->a->days());
        self::assertSame(30, R::preset('30d', $now)->b->days());
    }

    public function testDifferentLengths(): void
    {
        // 2025 (365 jours) contre 2024 (366 jours, bissextile)
        $c = R::preset('last_year', self::at('2026-11-16'));
        self::assertSame([365, 366], [$c->a->days(), $c->b->days()]);
        self::assertFalse($c->sameLength());

        // 30 mars : 30 jours contre février entier (28)
        $c = R::preset('month', self::at('2026-03-30'));
        self::assertSame([30, 28], [$c->a->days(), $c->b->days()]);
        // 29 février : 60 jours contre 59
        $c = R::preset('ytd', self::at('2028-02-29'));
        self::assertSame([60, 59], [$c->a->days(), $c->b->days()]);
    }

    public function testDaylightSavingTimeDoesNotShiftDays(): void
    {
        // Passage à l'heure d'été le dimanche 29/03/2026 à 02:00 (Paris) : semaine du 23 au 29 mars
        $c = R::preset('week', self::at('2026-03-29 12:00', 'Europe/Paris'));
        self::assertSame(['2026-03-23', '2026-03-29', '2026-03-16', '2026-03-22'], self::keys($c));
        self::assertSame('2026-03-23 00:00:00 +01:00', $c->a->from->format('Y-m-d H:i:s P'));
        self::assertSame('2026-03-29 23:59:59 +02:00', $c->a->to->format('Y-m-d H:i:s P'));
        self::assertSame(7, $c->a->days());

        // Retour à l'heure d'hiver le 25/10/2026 : mois complet d'octobre (31 jours, dont un de 25 h)
        $c = R::preset('month', self::at('2026-10-31 22:00', 'Europe/Paris'));
        self::assertSame(['2026-10-01', '2026-10-31', '2026-09-01', '2026-09-30'], self::keys($c));
        self::assertSame(31, $c->a->days());

        // 30 jours à cheval sur le changement d'heure
        $c = R::preset('30d', self::at('2026-04-10 07:00', 'Europe/Paris'));
        self::assertSame(['2026-03-12', '2026-04-10', '2026-02-10', '2026-03-11'], self::keys($c));
        self::assertSame([30, 30], [$c->a->days(), $c->b->days()]);

        self::assertSame(1, DateRange::ofDays('2026-03-29', '2026-03-29', new \DateTimeZone('Europe/Paris'))->days());
        self::assertSame(31, DateRange::ofDays('2026-03-01', '2026-03-31', new \DateTimeZone('Europe/Paris'))->days());
        self::assertSame(5, DateRange::ofDays('2026-03-27', '2026-03-31', new \DateTimeZone('Europe/Paris'))->indexOf('2026-04-01'));
    }

    public function testUnknownPresetThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        R::preset('decade', self::at('2026-11-16'));
    }

    public function testDateRangeRejectsInvertedBounds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DateRange::ofDays('2026-11-16', '2026-11-15', new \DateTimeZone('UTC'));
    }

    public function testPreviousOfHasTheSameLengthJustBefore(): void
    {
        // Vue d'ensemble « 7 jours » : du 09/11 00:00 à aujourd'hui (8 jours calendaires)
        $previous = R::previousOf(DateRange::ofDays('2026-11-09', '2026-11-16', new \DateTimeZone('UTC')));
        self::assertSame(['2026-11-01', '2026-11-08'], [$previous->fromKey(), $previous->toKey()]);
        // Au-dessus d'une fin de février bissextile
        $previous = R::previousOf(DateRange::ofDays('2028-03-01', '2028-03-10', new \DateTimeZone('UTC')));
        self::assertSame(['2028-02-20', '2028-02-29'], [$previous->fromKey(), $previous->toKey()]);
    }

    // ── Paramètres de la requête ──────────────────────────────────────

    public function testEmptyQueryUsesTheDefaultPresetWithoutError(): void
    {
        $r = R::fromQuery([], self::SPORTS, self::at('2026-11-16 08:00'));
        self::assertSame('month', $r->comparison->preset);
        self::assertNull($r->sport);
        self::assertSame([], $r->errors);
    }

    public function testValidPresetAndSport(): void
    {
        $r = R::fromQuery(['preset' => 'ytd', 'sport' => 'run'], self::SPORTS, self::at('2026-11-16'));
        self::assertSame('ytd', $r->comparison->preset);
        self::assertSame('run', $r->sport);
        self::assertSame([], $r->errors);

        // Un préréglage prime sur des dates
        $r = R::fromQuery(['preset' => 'week', 'a_from' => '2020-01-01'], self::SPORTS, self::at('2026-11-16'));
        self::assertSame('week', $r->comparison->preset);
        self::assertSame([], $r->errors);

        foreach (['', 'all'] as $all) {
            self::assertNull(R::fromQuery(['sport' => $all], self::SPORTS, self::at('2026-11-16'))->sport);
        }
    }

    public function testCustomRanges(): void
    {
        $query = ['preset' => 'custom', 'a_from' => '2026-06-01', 'a_to' => '2026-06-30', 'b_from' => '2025-06-01', 'b_to' => '2025-07-15', 'sport' => 'ride'];
        $r = R::fromQuery($query, self::SPORTS, self::at('2026-11-16'));
        self::assertSame('custom', $r->comparison->preset);
        self::assertSame(['2026-06-01', '2026-06-30', '2025-06-01', '2025-07-15'], self::keys($r->comparison));
        self::assertFalse($r->comparison->toDate);
        self::assertSame([30, 45], [$r->comparison->a->days(), $r->comparison->b->days()]);
        self::assertSame('ride', $r->sport);
        self::assertSame([], $r->errors);

        // Sans preset, des dates suffisent ; les périodes peuvent se chevaucher
        unset($query['preset']);
        $query['b_from'] = '2026-06-15';
        $query['b_to'] = '2026-07-15';
        $r = R::fromQuery($query, self::SPORTS, self::at('2026-11-16'));
        self::assertSame('custom', $r->comparison->preset);
        self::assertSame(['2026-06-01', '2026-06-30', '2026-06-15', '2026-07-15'], self::keys($r->comparison));
        self::assertSame([], $r->errors);

        // Exactement 5 ans (1 827 jours) : accepté ; dates futures jusqu'à fin de l'année prochaine
        $r = R::fromQuery(['a_from' => '2021-01-01', 'a_to' => '2026-01-01', 'b_from' => '2027-01-01', 'b_to' => '2027-12-31'], self::SPORTS, self::at('2026-11-16'));
        self::assertSame([], $r->errors);
        self::assertSame(1827, $r->comparison->a->days());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidProvider(): iterable
    {
        $ok = ['a_from' => '2026-06-01', 'a_to' => '2026-06-30', 'b_from' => '2025-06-01', 'b_to' => '2025-06-30'];

        yield 'unknown preset' => [['preset' => 'decade'], 'stats.compare.error_preset'];
        yield 'preset as array' => [['preset' => ['ytd']], 'stats.compare.error_preset'];
        yield 'XSS in preset' => [['preset' => '"><script>alert(1)</script>'], 'stats.compare.error_preset'];
        yield 'custom without dates' => [['preset' => 'custom'], 'stats.compare.error_dates'];
        yield 'missing a_to' => [['a_to' => ''] + $ok, 'stats.compare.error_dates'];
        yield 'impossible date' => [['b_to' => '2026-02-30'] + $ok, 'stats.compare.error_dates'];
        yield 'French format' => [['a_from' => '01/06/2026'] + $ok, 'stats.compare.error_dates'];
        yield 'no leading zeros' => [['a_from' => '2026-6-1'] + $ok, 'stats.compare.error_dates'];
        yield 'date with time' => [['a_from' => '2026-06-01 10:00'] + $ok, 'stats.compare.error_dates'];
        yield 'date as array' => [['a_from' => ['2026-06-01']] + $ok, 'stats.compare.error_dates'];
        yield 'XSS in date' => [['a_from' => '2026-06-01"><img src=x onerror=alert(1)>'] + $ok, 'stats.compare.error_dates'];
        yield 'inverted A' => [['a_from' => '2026-07-01'] + $ok, 'stats.compare.error_inverted'];
        yield 'inverted B' => [['b_to' => '2025-05-31'] + $ok, 'stats.compare.error_inverted'];
        yield 'over 5 years' => [['b_from' => '2020-06-01', 'b_to' => '2025-06-03'] + $ok, 'stats.compare.error_span'];
        yield 'before 2000' => [['b_from' => '1999-12-31'] + $ok, 'stats.compare.error_bounds'];
        yield 'after next year' => [['a_to' => '2028-01-01'] + $ok, 'stats.compare.error_bounds'];
    }

    /** @param array<string, mixed> $query */
    #[DataProvider('invalidProvider')]
    public function testInvalidInputFallsBackToTheDefaultPreset(array $query, string $error): void
    {
        $r = R::fromQuery($query, self::SPORTS, self::at('2026-11-16 08:00'));

        self::assertSame('month', $r->comparison->preset);
        self::assertSame(['2026-11-01', '2026-11-16', '2026-10-01', '2026-10-16'], self::keys($r->comparison));
        self::assertSame([$error], array_column($r->errors, 'key'));
    }

    public function testInvalidSportFallsBackToAllSports(): void
    {
        foreach (['bmx', '<script>alert(1)</script>', ['run'], 12] as $sport) {
            $r = R::fromQuery(['preset' => 'week', 'sport' => $sport], self::SPORTS, self::at('2026-11-16'));
            self::assertNull($r->sport);
            self::assertSame('week', $r->comparison->preset);
            self::assertSame(['stats.compare.error_sport'], array_column($r->errors, 'key'));
        }

        // Sport et dates invalides : les deux messages
        $r = R::fromQuery(['preset' => 'custom', 'sport' => 'bmx'], self::SPORTS, self::at('2026-11-16'));
        self::assertSame(['stats.compare.error_sport', 'stats.compare.error_dates'], array_column($r->errors, 'key'));
    }

    public function testErrorParameters(): void
    {
        $r = R::fromQuery(['a_from' => '2026-01-01', 'a_to' => '2026-01-31', 'b_from' => '2026-01-01', 'b_to' => '2030-01-01'], self::SPORTS, self::at('2026-11-16'));
        self::assertSame([['key' => 'stats.compare.error_bounds', 'params' => ['%max%' => '2027']]], $r->errors);

        $r = R::fromQuery(['a_from' => '2016-01-01', 'a_to' => '2026-01-31', 'b_from' => '2026-01-01', 'b_to' => '2026-01-02'], self::SPORTS, self::at('2026-11-16'));
        self::assertSame([['key' => 'stats.compare.error_span', 'params' => ['%max%' => '1827']]], $r->errors);
    }

    public function testParseDayIsStrict(): void
    {
        $utc = new \DateTimeZone('UTC');
        self::assertSame('2028-02-29', R::parseDay('2028-02-29', $utc)?->format('Y-m-d'));
        self::assertSame('00:00:00', R::parseDay('2028-02-29', $utc)?->format('H:i:s'));
        self::assertNull(R::parseDay('2027-02-29', $utc));
        self::assertNull(R::parseDay('2026-13-01', $utc));
        self::assertNull(R::parseDay(' 2026-01-01', $utc));
        self::assertNull(R::parseDay(null, $utc));
        self::assertNull(R::parseDay(20260101, $utc));
    }
}
