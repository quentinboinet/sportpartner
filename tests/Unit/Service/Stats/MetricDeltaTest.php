<?php

namespace App\Tests\Unit\Service\Stats;

use App\Service\Stats\MetricDelta;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Écarts entre A et B : pourcentages sur base nulle, sens de l'allure et de la vitesse, FC neutre,
 * arrondis sans round() et moyennes par semaine.
 */
final class MetricDeltaTest extends TestCase
{
    /** @return iterable<string, array{?float, ?float, int, int, ?float, ?float, string, string}> */
    public static function deltaProvider(): iterable
    {
        $up = MetricDelta::HIGHER_IS_BETTER;
        $down = MetricDelta::LOWER_IS_BETTER;
        $neutral = MetricDelta::NEUTRAL;

        yield 'more volume is better' => [123.44, 100.0, $up, 1, 23.4, 23.4, 'up', 'better'];
        yield 'less volume is worse' => [80.0, 100.0, $up, 1, -20.0, -20.0, 'down', 'worse'];
        yield 'zero base: no percent' => [5.0, 0.0, $up, 0, 5.0, null, 'up', 'better'];
        yield 'both zero: no delta' => [0.0, 0.0, $up, 1, null, null, 'none', 'none'];
        yield 'A empty, B not' => [0.0, 8.0, $up, 0, -8.0, -100.0, 'down', 'worse'];
        yield 'missing A' => [null, 10.0, $up, 0, null, null, 'none', 'none'];
        yield 'missing B' => [10.0, null, $up, 0, null, null, 'none', 'none'];
        yield 'equal after rounding' => [10.04, 10.01, $up, 1, 0.0, 0.0, 'flat', 'same'];
        yield 'float noise' => [0.3, 0.1, $up, 1, 0.2, 200.0, 'up', 'better'];
        yield 'faster pace (lower s/km) is better' => [300.0, 312.0, $down, 0, -12.0, -3.8, 'down', 'better'];
        yield 'slower pace is worse' => [320.4, 300.0, $down, 0, 20.0, 6.7, 'up', 'worse'];
        yield 'faster speed is better' => [27.46, 25.0, $up, 1, 2.5, 10.0, 'up', 'better'];
        yield 'higher HR: neutral' => [150.2, 140.0, $neutral, 0, 10.0, 7.1, 'up', 'neutral'];
        yield 'lower HR: neutral' => [130.0, 140.0, $neutral, 0, -10.0, -7.1, 'down', 'neutral'];
        yield 'same HR' => [140.4, 139.6, $neutral, 0, 0.0, 0.0, 'flat', 'same'];
    }

    #[DataProvider('deltaProvider')]
    public function testCompare(?float $a, ?float $b, int $polarity, int $decimals, ?float $delta, ?float $percent, string $direction, string $tone): void
    {
        $d = MetricDelta::compare($a, $b, $polarity, $decimals);

        self::assertSame($delta, $d->delta);
        self::assertSame($percent, $d->percent);
        self::assertSame($direction, $d->direction);
        self::assertSame($tone, $d->tone);
    }

    public function testValuesAreRoundedLikeTheDisplay(): void
    {
        $d = MetricDelta::compare(12.349, 10.051, MetricDelta::HIGHER_IS_BETTER, 1);
        self::assertSame(12.3, $d->a);
        self::assertSame(10.1, $d->b);
        // Écart = différence des valeurs affichées (12,3 - 10,1), pas des valeurs brutes (2,298)
        self::assertSame(2.2, $d->delta);
        self::assertSame(21.8, $d->percent);
    }

    /** @return iterable<string, array{float, int, float}> */
    public static function roundProvider(): iterable
    {
        yield 'half up' => [2.25, 1, 2.3];
        yield 'half away from zero' => [-2.25, 1, -2.3];
        yield 'integer half' => [12.5, 0, 13.0];
        yield 'negative integer half' => [-12.5, 0, -13.0];
        yield 'no negative zero' => [-0.04, 1, 0.0];
        yield 'already rounded' => [7.1, 1, 7.1];
        yield 'two decimals' => [3.14159, 2, 3.14];
    }

    #[DataProvider('roundProvider')]
    public function testRound(float $value, int $decimals, float $expected): void
    {
        $rounded = MetricDelta::round($value, $decimals);
        self::assertSame($expected, $rounded);
        if ($expected == 0.0) {
            self::assertSame('0', (string) $rounded);
        }
    }

    public function testPerWeek(): void
    {
        self::assertSame(50.0, MetricDelta::perWeek(100.0, 14));
        self::assertSame(7.0, MetricDelta::perWeek(30.0, 30));
        self::assertSame(0.0, MetricDelta::perWeek(10.0, 0));
        // 365 contre 366 jours : la même moyenne par semaine malgré un total différent
        $a = MetricDelta::perWeek(3650.0, 365);
        $b = MetricDelta::perWeek(3660.0, 366);
        self::assertSame('same', MetricDelta::compare($a, $b, MetricDelta::HIGHER_IS_BETTER, 1)->tone);
        self::assertSame('better', MetricDelta::compare(3660.0, 3650.0, MetricDelta::HIGHER_IS_BETTER, 1)->tone);
    }
}
