<?php

namespace App\Tests\Unit\Service\Route;

use App\Service\Route\SplitCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SplitCalculatorTest extends TestCase
{
    private SplitCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new SplitCalculator();
    }

    /**
     * Tracé synthétique : un point tous les $stepM mètres, altitude = $ele(distance).
     *
     * @return list<array{int, ?float, float, float}>
     */
    private function track(int $lengthM, callable $ele, int $stepM = 100): array
    {
        $points = [];
        for ($d = 0; $d <= $lengthM; $d += $stepM) {
            $points[] = [$d, round((float) $ele($d), 1), 45.0 + $d / 111195.08, 6.0];
        }

        return $points;
    }

    /** @return list<array{int, ?float, float, float}> */
    private function flat(int $lengthM): array
    {
        return $this->track($lengthM, static fn (int $d): float => 100.0);
    }

    /**
     * @return array{id: int, name: string, type: string, distanceM: int, stopSeconds: ?int, cutoffSeconds: ?int}
     */
    private static function cp(int $id, int $distanceM, string $type = 'point', ?int $stop = null, ?int $cutoff = null): array
    {
        return ['id' => $id, 'name' => 'CP'.$id, 'type' => $type, 'distanceM' => $distanceM, 'stopSeconds' => $stop, 'cutoffSeconds' => $cutoff];
    }

    #[Test]
    public function flatRouteComputesPaceAndTotals(): void
    {
        $r = $this->calc->compute($this->flat(10000), 10000, [self::cp(1, 5000)], [1 => 1500], 3000, null);

        self::assertCount(1, $r['rows']);
        $row = $r['rows'][0];
        self::assertSame(1, $row['checkpointId']);
        self::assertSame(5000, $row['segmentDistanceM']);
        self::assertSame(0, $row['segmentGainM']);
        self::assertSame(1500, $row['arrivalSeconds']);
        self::assertSame(1500, $row['departureSeconds']);
        self::assertSame(1500, $row['segmentMovingSeconds']);
        self::assertSame(300.0, $row['segmentPaceSecPerKm']);
        self::assertSame(12.0, $row['segmentSpeedKmh']);
        self::assertSame(300.0, $row['avgPaceSecPerKm']);
        self::assertNull($row['paceFromCheckpointId']);
        self::assertNull($row['clock']);
        self::assertSame('none', $row['cutoffStatus']);

        $finish = $r['finish'];
        self::assertNull($finish['checkpointId']);
        self::assertSame('finish', $finish['type']);
        self::assertSame(10000, $finish['distanceM']);
        self::assertSame(5000, $finish['segmentDistanceM']);
        self::assertSame(3000, $finish['arrivalSeconds']);
        self::assertSame(1500, $finish['segmentMovingSeconds']);
        self::assertSame(1, $finish['paceFromCheckpointId']);

        self::assertSame(3000, $r['totals']['movingSeconds']);
        self::assertSame(0, $r['totals']['stopSeconds']);
        self::assertSame(300.0, $r['totals']['avgPaceSecPerKm']);
        self::assertTrue($r['totals']['complete']);
        self::assertSame([], $r['errors']);
    }

    #[Test]
    public function hillyRouteReportsSegmentElevationAndWeightsDistribution(): void
    {
        // 5 km de montée régulière (+500 m) puis 5 km de plat
        $points = $this->track(10000, static fn (int $d): float => min($d, 5000) / 10);
        $checkpoints = [self::cp(1, 5000)];

        $r = $this->calc->compute($points, 10000, $checkpoints, [], 6000, null);
        self::assertSame(500, $r['rows'][0]['segmentGainM']);
        self::assertSame(500, $r['rows'][0]['cumulativeGainM']);
        self::assertSame(0, $r['finish']['segmentGainM']);
        self::assertSame(500, $r['totals']['gainM']);

        // Efforts : 5 + 500/100 = 10 puis 5 -> 2/3 du temps sur la montée
        self::assertSame([10.0, 5.0], $this->calc->segmentEfforts($points, $checkpoints, 10000));
        self::assertSame([1 => 4000], $this->calc->distribute($points, 10000, $checkpoints, 6000));
    }

    #[Test]
    public function aidStationStopsAreExcludedFromMovingTime(): void
    {
        $checkpoints = [self::cp(1, 10000, 'aid_station', 300)];
        $r = $this->calc->compute($this->flat(20000), 20000, $checkpoints, [1 => 3000], 6300, null);

        $row = $r['rows'][0];
        self::assertSame(300, $row['stopSeconds']);
        self::assertSame(3000, $row['arrivalSeconds']);
        self::assertSame(3300, $row['departureSeconds']);
        self::assertSame(3000, $r['finish']['segmentMovingSeconds']);
        self::assertSame(300.0, $r['finish']['segmentPaceSecPerKm']);
        self::assertSame(315.0, $r['finish']['avgPaceSecPerKm']);
        self::assertSame(6000, $r['totals']['movingSeconds']);
        self::assertSame(300, $r['totals']['stopSeconds']);
        self::assertSame(300.0, $r['totals']['avgPaceSecPerKm']);

        // Répartition : 6000 s de déplacement sur 2 tronçons égaux
        self::assertSame([1 => 3000], $this->calc->distribute($this->flat(20000), 20000, $checkpoints, 6300));
    }

    #[Test]
    public function stopIgnoredForNonAidStationAndAtFinish(): void
    {
        $checkpoints = [self::cp(1, 5000, 'point', 600), self::cp(2, 10000, 'aid_station', 600)];
        $r = $this->calc->compute($this->flat(10000), 10000, $checkpoints, [1 => 1500, 2 => 3000], 3000, null);

        self::assertSame(0, $r['rows'][0]['stopSeconds']);
        self::assertSame(0, $r['rows'][1]['stopSeconds']);
        self::assertSame([], $r['errors']);
    }

    #[Test]
    public function missingMiddleSplitUsesPreviousKnownCheckpoint(): void
    {
        $checkpoints = [self::cp(1, 2000), self::cp(2, 4000), self::cp(3, 6000)];
        $r = $this->calc->compute($this->flat(8000), 8000, $checkpoints, [1 => 600, 3 => 1800], 2400, null);

        $missing = $r['rows'][1];
        self::assertNull($missing['arrivalSeconds']);
        self::assertNull($missing['segmentMovingSeconds']);
        self::assertNull($missing['segmentPaceSecPerKm']);
        self::assertSame(2000, $missing['segmentDistanceM']);

        $third = $r['rows'][2];
        self::assertSame(1, $third['paceFromCheckpointId']);
        self::assertSame(4000, $third['paceSpanDistanceM']);
        self::assertSame(2000, $third['segmentDistanceM']);
        self::assertSame(1200, $third['segmentMovingSeconds']);
        self::assertSame(300.0, $third['segmentPaceSecPerKm']);

        self::assertFalse($r['totals']['complete']);
        self::assertSame([], $r['errors']);
    }

    #[Test]
    public function nonIncreasingArrivalIsAnError(): void
    {
        $checkpoints = [self::cp(1, 2000), self::cp(2, 4000), self::cp(3, 6000)];
        $r = $this->calc->compute($this->flat(8000), 8000, $checkpoints, [1 => 600, 2 => 500, 3 => 500], null, null);

        self::assertSame([
            ['checkpointId' => 2, 'code' => 'not_increasing'],
            ['checkpointId' => 3, 'code' => 'not_increasing'], // égal au précédent avec 2 km d'écart
        ], $r['errors']);
        self::assertSame(['not_increasing'], $r['rows'][1]['errors']);
        self::assertNull($r['rows'][1]['segmentMovingSeconds']);
        self::assertNull($r['rows'][1]['segmentPaceSecPerKm']);
        self::assertFalse($r['totals']['complete']); // pas de temps cible
        self::assertNull($r['totals']['movingSeconds']);
    }

    #[Test]
    public function arrivalBeforePreviousAidDepartureIsAnError(): void
    {
        $checkpoints = [self::cp(1, 2000, 'aid_station', 600), self::cp(2, 4000)];
        $r = $this->calc->compute($this->flat(8000), 8000, $checkpoints, [1 => 600, 2 => 1100], null, null);

        self::assertSame([['checkpointId' => 2, 'code' => 'not_increasing']], $r['errors']);
    }

    #[Test]
    public function sameDistanceSameTimeIsNotAnError(): void
    {
        $checkpoints = [self::cp(1, 0), self::cp(2, 3000), self::cp(3, 3000)];
        $r = $this->calc->compute($this->flat(6000), 6000, $checkpoints, [1 => 0, 2 => 900, 3 => 900], 1800, null);

        self::assertSame([], $r['errors']);
        self::assertSame(0, $r['rows'][2]['segmentMovingSeconds']);
        self::assertNull($r['rows'][2]['segmentPaceSecPerKm']);
    }

    #[Test]
    public function arrivalAfterTargetIsAnError(): void
    {
        $r = $this->calc->compute($this->flat(8000), 8000, [self::cp(1, 4000)], [1 => 1200], 1000, null);

        self::assertContains(['checkpointId' => 1, 'code' => 'after_finish'], $r['errors']);
        self::assertContains(['checkpointId' => null, 'code' => 'not_increasing'], $r['errors']);
        self::assertSame(['after_finish'], $r['rows'][0]['errors']);
    }

    #[Test]
    public function cutoffStatuses(): void
    {
        $checkpoints = [
            self::cp(1, 2000, 'point', null, 2000),
            self::cp(2, 4000, 'point', null, 2500),
            self::cp(3, 6000, 'point', null, 2900),
            self::cp(4, 7000, 'point', null, 5000),
        ];
        $r = $this->calc->compute($this->flat(8000), 8000, $checkpoints, [1 => 1000, 2 => 2000, 3 => 3000], 4000, null);

        self::assertSame(['ok', 1000], [$r['rows'][0]['cutoffStatus'], $r['rows'][0]['cutoffMarginSeconds']]);
        self::assertSame(['tight', 500], [$r['rows'][1]['cutoffStatus'], $r['rows'][1]['cutoffMarginSeconds']]);
        self::assertSame(['missed', -100], [$r['rows'][2]['cutoffStatus'], $r['rows'][2]['cutoffMarginSeconds']]);
        self::assertSame(['none', null], [$r['rows'][3]['cutoffStatus'], $r['rows'][3]['cutoffMarginSeconds']]);
        self::assertSame(5000, $r['rows'][3]['cutoffSeconds']);
        self::assertSame('none', $r['finish']['cutoffStatus']);
    }

    #[Test]
    public function clockCrossesMidnight(): void
    {
        $checkpoints = [self::cp(1, 50000, 'aid_station', 1800)];
        $start = new \DateTimeImmutable('1970-01-01 06:00:00');
        $r = $this->calc->compute($this->flat(100000), 100000, $checkpoints, [1 => 20 * 3600], 30 * 3600, $start);

        self::assertSame(['time' => '02:00', 'dayOffset' => 1], $r['rows'][0]['clock']);
        self::assertSame(['time' => '02:30', 'dayOffset' => 1], $r['rows'][0]['departureClock']);
        self::assertSame(['time' => '12:00', 'dayOffset' => 1], $r['finish']['clock']);
    }

    #[Test]
    public function worksWithoutRoute(): void
    {
        $r = $this->calc->compute([], 42195, [self::cp(1, 21000)], [1 => 5400], 11700, null, 'Finish');

        self::assertSame(0, $r['rows'][0]['segmentGainM']);
        self::assertSame(21000, $r['rows'][0]['segmentDistanceM']);
        self::assertSame('Finish', $r['finish']['name']);
        self::assertSame(42195, $r['finish']['distanceM']);
        self::assertSame(21195, $r['finish']['segmentDistanceM']);
        self::assertSame(0, $r['totals']['gainM']);
        self::assertTrue($r['totals']['complete']);

        // Repère au-delà de la distance connue : l'arrivée est repoussée au dernier repère
        $r = $this->calc->compute([], 0, [self::cp(1, 5000)], [], null, null);
        self::assertSame(5000, $r['finish']['distanceM']);
        self::assertSame(0, $r['finish']['segmentDistanceM']);
    }

    #[Test]
    public function emptyPlan(): void
    {
        $r = $this->calc->compute([], 10000, [], [], null, null);

        self::assertSame([], $r['rows']);
        self::assertNull($r['finish']['arrivalSeconds']);
        self::assertFalse($r['totals']['complete']);
        self::assertSame([], $r['errors']);
    }

    #[Test]
    public function distributeIsMonotonicAndLandsExactlyOnTarget(): void
    {
        // Parcours vallonné : sinusoïde d'amplitude 150 m sur 30 km
        $points = $this->track(30000, static fn (int $d): float => 500 + 150 * sin($d / 2500));
        $checkpoints = [
            self::cp(1, 4000),
            self::cp(2, 9000, 'aid_station', 300),
            self::cp(3, 15000),
            self::cp(4, 21000, 'aid_station', 600),
            self::cp(5, 26000),
        ];
        $target = 4 * 3600 + 7; // non multiple de 10

        $arrivals = $this->calc->distribute($points, 30000, $checkpoints, $target);
        self::assertSame([1, 2, 3, 4, 5], array_keys($arrivals));
        foreach ($arrivals as $seconds) {
            self::assertSame(0, $seconds % 10);
        }

        $r = $this->calc->compute($points, 30000, $checkpoints, $arrivals, $target, null);
        self::assertSame([], $r['errors']);
        self::assertTrue($r['totals']['complete']);

        // Somme des temps de déplacement + arrêts = cible exactement
        $moving = $r['finish']['segmentMovingSeconds'];
        $previousDeparture = 0;
        foreach ($r['rows'] as $row) {
            self::assertGreaterThan($previousDeparture, $row['arrivalSeconds']);
            $previousDeparture = (int) $row['departureSeconds'];
            $moving += $row['segmentMovingSeconds'];
        }
        self::assertSame($target, $moving + $r['totals']['stopSeconds']);
        self::assertSame($target - 900, $r['totals']['movingSeconds']);

        // Plus d'effort -> plus de temps : la proportion suit l'effort à l'arrondi près
        $efforts = $this->calc->segmentEfforts($points, $checkpoints, 30000);
        $totalEffort = array_sum($efforts);
        $segments = array_merge($r['rows'], [$r['finish']]);
        foreach ($segments as $i => $row) {
            self::assertEqualsWithDelta(($target - 900) * $efforts[$i] / $totalEffort, $row['segmentMovingSeconds'], 10);
        }
    }

    #[Test]
    public function distributeRejectsStopsExceedingTarget(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stops_exceed_target');

        $this->calc->distribute([], 10000, [self::cp(1, 5000, 'aid_station', 3600)], 3600);
    }

    #[Test]
    public function computeFlagsStopsExceedingTarget(): void
    {
        $r = $this->calc->compute([], 10000, [self::cp(1, 5000, 'aid_station', 3600)], [], 3000, null);

        self::assertContains(['checkpointId' => null, 'code' => 'stops_exceed_target'], $r['errors']);
        self::assertNull($r['totals']['movingSeconds']);
    }
}
