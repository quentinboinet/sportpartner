<?php

namespace App\Tests\Unit\Service\Stats;

use App\Service\Stats\OverviewPeriod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Période de la vue d'ensemble et de son export : préréglages, période personnalisée, repli, bornes incluses. */
final class OverviewPeriodTest extends TestCase
{
    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-16 14:25:00', new \DateTimeZone('Europe/Paris'));
    }

    /** @return iterable<string, array{array<string, mixed>, string, string, string}> */
    public static function presets(): iterable
    {
        yield 'défaut : saison' => [[], 'season', '2026-01-01 00:00:00', 'month'];
        yield '7 jours' => [['period' => '7d'], '7d', '2026-11-09 00:00:00', 'day'];
        yield 'ce mois' => [['period' => 'month'], 'month', '2026-11-01 00:00:00', 'day'];
        yield 'saison' => [['period' => 'season'], 'season', '2026-01-01 00:00:00', 'month'];
        yield 'tout' => [['period' => 'all'], 'all', '2000-01-01 00:00:00', 'month'];
        yield 'valeur inconnue : saison' => [['period' => 'decade'], 'season', '2026-01-01 00:00:00', 'month'];
        yield 'tableau ignoré' => [['period' => ['7d']], 'season', '2026-01-01 00:00:00', 'month'];
    }

    /** @param array<string, mixed> $query */
    #[Test]
    #[DataProvider('presets')]
    public function presetsRunUntilTheEndOfToday(array $query, string $period, string $from, string $granularity): void
    {
        $p = OverviewPeriod::fromQuery($query, self::now());
        self::assertSame($period, $p->period);
        self::assertSame($from, $p->from->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-16 23:59:59', $p->to->format('Y-m-d H:i:s'), 'le dernier jour compte jusqu\'à 23:59:59');
        self::assertSame($granularity, $p->granularity);
        self::assertSame(['period' => $period], $p->queryParams());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function customRanges(): iterable
    {
        yield 'un jour' => ['2026-11-16', '2026-11-16', 'day'];
        yield '31 jours : jours' => ['2026-10-01', '2026-11-01', 'day'];
        yield '32 jours : semaines' => ['2026-10-01', '2026-11-02', 'week'];
        yield '120 jours : semaines' => ['2026-01-01', '2026-05-01', 'week'];
        yield 'au-delà : mois' => ['2025-01-01', '2026-11-16', 'month'];
    }

    #[Test]
    #[DataProvider('customRanges')]
    public function customRangeOverridesThePreset(string $from, string $to, string $granularity): void
    {
        $p = OverviewPeriod::fromQuery(['period' => '7d', 'from' => $from, 'to' => $to], self::now());
        self::assertSame(OverviewPeriod::CUSTOM, $p->period);
        self::assertSame($from.' 00:00:00', $p->from->format('Y-m-d H:i:s'));
        self::assertSame($to.' 23:59:59', $p->to->format('Y-m-d H:i:s'));
        self::assertSame($granularity, $p->granularity);
        self::assertSame([$from, $to], [$p->customFrom, $p->customTo]);
        self::assertSame(['from' => $from, 'to' => $to], $p->queryParams());
    }

    #[Test]
    public function invalidCustomRangeFallsBackToThePreset(): void
    {
        foreach ([['2026-13-01', '2026-11-16'], ['2026-02-30', '2026-03-01'], ['hier', '2026-11-16'], ['2026-11-01', '16/11/2026']] as [$from, $to]) {
            $p = OverviewPeriod::fromQuery(['period' => 'month', 'from' => $from, 'to' => $to], self::now());
            self::assertSame(['month', null, null], [$p->period, $p->customFrom, $p->customTo], "$from -> $to");
            self::assertSame('2026-11-01 00:00:00', $p->from->format('Y-m-d H:i:s'));
        }

        // Une seule borne : préréglage, saisie gardée pour le formulaire
        $p = OverviewPeriod::fromQuery(['from' => '2026-11-01'], self::now());
        self::assertSame(['season', '2026-11-01', null], [$p->period, $p->customFrom, $p->customTo]);
    }
}
