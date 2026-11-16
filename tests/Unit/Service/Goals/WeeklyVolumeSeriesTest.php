<?php

namespace App\Tests\Unit\Service\Goals;

use App\Service\Goals\WeeklyVolumeSeries;
use PHPUnit\Framework\TestCase;

class WeeklyVolumeSeriesTest extends TestCase
{
    private static function at(string $datetime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($datetime, new \DateTimeZone('Europe/Paris'));
    }

    public function testSinceIsMondayMidnightOfTheOldestWeek(): void
    {
        // Lundi 16/11/2026 (S47) : 12 semaines = S36 (lundi 31/08) -> S47
        $this->assertSame('2026-08-31 00:00:00', WeeklyVolumeSeries::since(self::at('2026-11-16 08:30'), 12)->format('Y-m-d H:i:s'));
        // Dimanche soir : toujours la même semaine
        $this->assertSame('2026-08-31 00:00:00', WeeklyVolumeSeries::since(self::at('2026-11-22 23:59:59'), 12)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-16 00:00:00', WeeklyVolumeSeries::since(self::at('2026-11-18 12:00'), 1)->format('Y-m-d H:i:s'));
    }

    public function testCurrentWeekIsLastEvenWithoutActivity(): void
    {
        // Un lundi sans sortie : la dernière semaine active (S46) devient l'avant-dernière, la semaine en cours vaut zéro
        $rows = [
            ['yearweek' => '202645', 'week' => '45', 'year' => '2026', 'totalDistance' => '30000', 'totalTime' => '9000', 'totalElevation' => '250', 'count' => '3'],
            ['yearweek' => '202646', 'week' => '46', 'year' => '2026', 'totalDistance' => '42195.5', 'totalTime' => '12600', 'totalElevation' => '400', 'count' => '4'],
        ];

        $series = WeeklyVolumeSeries::fill($rows, self::at('2026-11-16 07:00'), 12);

        $this->assertCount(12, $series);
        $this->assertSame(202636, $series[0]['yearweek']);
        $last = $series[11];
        $this->assertSame(['yearweek' => 202647, 'week' => 47, 'year' => 2026, 'totalDistance' => 0.0, 'totalTime' => 0, 'totalElevation' => 0.0, 'count' => 0], $last);
        $this->assertSame(202646, $series[10]['yearweek']);
        $this->assertSame(42195.5, $series[10]['totalDistance']);
        $this->assertSame(12600, $series[10]['totalTime']);
        $this->assertSame(400.0, $series[10]['totalElevation']);
        $this->assertSame(4, $series[10]['count']);
        $this->assertSame(3, $series[9]['count']);
        // Semaines vides intermédiaires à zéro, consécutives
        $this->assertSame(0, $series[5]['count']);
        $yearweeks = array_column($series, 'yearweek');
        $this->assertSame(range(202636, 202647), $yearweeks);
    }

    public function testIsoWeeksAcrossNewYear(): void
    {
        // Lundi 04/01/2027 = S01 de 2027 ; la semaine du 28/12/2026 est la S53 de 2026 (année ISO à 53 semaines).
        // MySQL WEEK(..., 1) renverrait 0 pour le 01/01/2027 : la semaine affichée vient de yearweek.
        $rows = [
            ['yearweek' => '202653', 'week' => '0', 'year' => '2027', 'totalDistance' => '10000', 'totalTime' => '3000', 'totalElevation' => '50', 'count' => '1'],
        ];

        $series = WeeklyVolumeSeries::fill($rows, self::at('2027-01-06 12:00'), 3);

        $this->assertSame([202652, 202653, 202701], array_column($series, 'yearweek'));
        $this->assertSame([52, 53, 1], array_column($series, 'week'));
        $this->assertSame([2026, 2026, 2027], array_column($series, 'year'));
        $this->assertSame(1, $series[1]['count']);
        $this->assertSame(0, $series[2]['count']);
    }

    public function testRowsOutsideTheWindowAreIgnored(): void
    {
        $rows = [['yearweek' => '202601', 'totalDistance' => '5000', 'totalTime' => '1500', 'totalElevation' => '0', 'count' => '1']];

        $series = WeeklyVolumeSeries::fill($rows, self::at('2026-11-16 07:00'), 2);

        $this->assertSame([202646, 202647], array_column($series, 'yearweek'));
        $this->assertSame(0, array_sum(array_column($series, 'count')));
    }
}
