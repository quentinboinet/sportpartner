<?php

namespace App\Tests\Unit\Service\Route;

use App\Entity\PacingIntensity;
use App\Entity\PacingStrategy;
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

    /**
     * Allures et vitesses arrondies à 0,01 sans round(), qui diffère entre PHP 8.3 (prod) et PHP 8.4+ près des
     * demi-centièmes : même résultat sur toutes les versions et dans le calcul en direct du navigateur.
     */
    #[Test]
    public function paceAndSpeedRoundingIsTheSameOnEveryPhpVersion(): void
    {
        // 1 126 m en 480 s = 8,445 km/h, soit le flottant 8.4449999999999985 : round(x, 2) donne 8.45 en PHP 8.3
        // et 8.44 en PHP 8.4+
        $r = $this->calc->compute([], 1126, [], [], 480, null);

        self::assertSame(8.44, $r['finish']['segmentSpeedKmh']);
        self::assertSame(426.29, $r['finish']['segmentPaceSecPerKm']);
        self::assertSame(426.29, $r['finish']['avgPaceSecPerKm']);
        self::assertSame(426.29, $r['totals']['avgPaceSecPerKm']);
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
    public function fillAutoWithoutPinsEqualsDistribute(): void
    {
        $points = $this->track(30000, static fn (int $d): float => 500 + 150 * sin($d / 2500));
        $checkpoints = [
            self::cp(5, 26000),
            self::cp(1, 4000),
            self::cp(2, 9000, 'aid_station', 300),
            self::cp(4, 21000, 'aid_station', 600),
            self::cp(3, 15000),
        ];
        $target = 4 * 3600 + 7;

        $filled = $this->calc->fillAuto($points, 30000, $checkpoints, [], $target);
        $distributed = $this->calc->distribute($points, 30000, $checkpoints, $target);

        self::assertSame($distributed, $filled['arrivals']);
        self::assertSame([1 => false, 2 => false, 3 => false, 4 => false, 5 => false], $filled['pinned']);
        // Ordre des distances, quel que soit l'ordre d'entrée
        self::assertSame([1, 2, 3, 4, 5], array_keys($filled['arrivals']));

        // Même résultat à partir des efforts précalculés
        $efforts = $this->calc->routeEfforts($points, 30000, $checkpoints);
        self::assertCount(6, $efforts);
        self::assertSame($filled, $this->calc->fillAutoFromEfforts($checkpoints, 30000, $efforts, [], $target));
    }

    #[Test]
    public function pinInTheMiddleSplitsTheRouteIntoTwoIntervals(): void
    {
        $checkpoints = [self::cp(1, 2500), self::cp(2, 5000), self::cp(3, 7500)];
        // 1800 s sur les 5 premiers km (temps fixé), 1200 s restants sur les 5 derniers
        $filled = $this->calc->fillAuto($this->flat(10000), 10000, $checkpoints, [2 => 1800], 3000);

        self::assertSame([1 => 900, 2 => 1800, 3 => 2400], $filled['arrivals']);
        self::assertSame([1 => false, 2 => true, 3 => false], $filled['pinned']);

        $r = $this->calc->compute($this->flat(10000), 10000, $checkpoints, $filled['arrivals'], 3000, null, pinned: $filled['pinned']);
        self::assertSame([false, true, false], array_column($r['rows'], 'pinned'));
        self::assertTrue($r['finish']['pinned']);
        self::assertSame(3000, $r['finish']['arrivalSeconds']);
        self::assertSame([], $r['errors']);
    }

    #[Test]
    public function twoPins(): void
    {
        $checkpoints = [self::cp(1, 2000), self::cp(2, 4000), self::cp(3, 6000), self::cp(4, 8000)];
        $filled = $this->calc->fillAuto($this->flat(10000), 10000, $checkpoints, [1 => 600, 3 => 2000], 3000);

        self::assertSame([1 => 600, 2 => 1300, 3 => 2000, 4 => 2500], $filled['arrivals']);
        self::assertSame([1 => true, 2 => false, 3 => true, 4 => false], $filled['pinned']);
    }

    #[Test]
    public function pinAtKilometreZero(): void
    {
        // Départ réel 2 min après le coup de pistolet (repère au km 0)
        $checkpoints = [self::cp(1, 0), self::cp(2, 5000)];
        $filled = $this->calc->fillAuto($this->flat(10000), 10000, $checkpoints, [1 => 120], 3120);

        self::assertSame([1 => 120, 2 => 1620], $filled['arrivals']);
    }

    #[Test]
    public function pinEqualToTargetIsReportedAndFollowingAutosStayAtItsDeparture(): void
    {
        $checkpoints = [self::cp(1, 5000), self::cp(2, 7500)];
        $filled = $this->calc->fillAuto($this->flat(10000), 10000, $checkpoints, [1 => 3000], 3000);

        self::assertSame([1 => 3000, 2 => 3000], $filled['arrivals']);
        $r = $this->calc->compute($this->flat(10000), 10000, $checkpoints, $filled['arrivals'], 3000, null, pinned: $filled['pinned']);
        self::assertContains(['checkpointId' => 1, 'code' => 'after_finish'], $r['errors']);
        self::assertSame(3000, $r['finish']['arrivalSeconds']);
    }

    #[Test]
    public function pinAfterTargetIsAnError(): void
    {
        $checkpoints = [self::cp(1, 2500), self::cp(2, 5000), self::cp(3, 7500)];
        $filled = $this->calc->fillAuto($this->flat(10000), 10000, $checkpoints, [2 => 4000], 3000);

        // Avant le temps fixé : réparti normalement ; après : intervalle impossible -> au départ du repère fixé
        self::assertSame([1 => 2000, 2 => 4000, 3 => 4000], $filled['arrivals']);

        $r = $this->calc->compute($this->flat(10000), 10000, $checkpoints, $filled['arrivals'], 3000, null, pinned: $filled['pinned']);
        self::assertContains(['checkpointId' => 2, 'code' => 'after_finish'], $r['errors']);
        self::assertContains(['checkpointId' => null, 'code' => 'not_increasing'], $r['errors']);
        self::assertSame(3000, $r['finish']['arrivalSeconds']);
    }

    #[Test]
    public function withoutTargetAutosAfterLastPinHaveNoTime(): void
    {
        $checkpoints = [self::cp(1, 2000), self::cp(2, 4000), self::cp(3, 6000), self::cp(4, 8000)];
        $filled = $this->calc->fillAuto($this->flat(10000), 10000, $checkpoints, [1 => 600, 3 => 2000], null);

        self::assertSame([1 => 600, 2 => 1300, 3 => 2000, 4 => null], $filled['arrivals']);

        $r = $this->calc->compute($this->flat(10000), 10000, $checkpoints, $filled['arrivals'], null, null, pinned: $filled['pinned']);
        self::assertNull($r['rows'][3]['arrivalSeconds']);
        self::assertFalse($r['rows'][3]['pinned']);
        self::assertFalse($r['finish']['pinned']);
        self::assertFalse($r['totals']['complete']);

        // Ni cible ni temps fixé : aucun temps
        $empty = $this->calc->fillAuto([], 10000, $checkpoints, [], null);
        self::assertSame([1 => null, 2 => null, 3 => null, 4 => null], $empty['arrivals']);
    }

    #[Test]
    public function aidStationStopsInsideAndAtTheEdgesOfAnInterval(): void
    {
        // Ravito auto (5 min) avant le temps fixé, ravito auto (10 min) après
        $checkpoints = [
            self::cp(1, 5000, 'aid_station', 300),
            self::cp(2, 10000),
            self::cp(3, 15000, 'aid_station', 600),
        ];
        $filled = $this->calc->fillAuto($this->flat(20000), 20000, $checkpoints, [2 => 3300], 6900);
        self::assertSame([1 => 1500, 2 => 3300, 3 => 4800], $filled['arrivals']);

        $r = $this->calc->compute($this->flat(20000), 20000, $checkpoints, $filled['arrivals'], 6900, null, pinned: $filled['pinned']);
        self::assertSame([], $r['errors']);
        self::assertSame(1500, $r['finish']['segmentMovingSeconds']);

        // Ravito FIXÉ : l'intervalle suivant part de son heure de départ (arrivée + arrêt)
        $checkpoints = [self::cp(1, 5000, 'aid_station', 300), self::cp(2, 10000)];
        $filled = $this->calc->fillAuto($this->flat(20000), 20000, $checkpoints, [1 => 1500], 6300);
        self::assertSame([1 => 1500, 2 => 3300], $filled['arrivals']);
    }

    #[Test]
    public function infeasibleIntervalPlacesAutosAtTheLeftDeparture(): void
    {
        // Temps fixés décroissants : l'auto entre les deux est placé au départ du premier
        $checkpoints = [
            self::cp(1, 2000),
            self::cp(2, 4000, 'aid_station', 60),
            self::cp(3, 5000),
            self::cp(4, 6000),
            self::cp(5, 8000),
        ];
        $filled = $this->calc->fillAuto($this->flat(10000), 10000, $checkpoints, [1 => 2000, 4 => 1500], 3000);

        self::assertSame(2000, $filled['arrivals'][2]);
        self::assertSame(2060, $filled['arrivals'][3]); // départ du ravito précédent
        self::assertSame(2250, $filled['arrivals'][5]); // intervalle suivant normal : 1500 + 1500 / 2

        $r = $this->calc->compute($this->flat(10000), 10000, $checkpoints, $filled['arrivals'], 3000, null, pinned: $filled['pinned']);
        self::assertContains(['checkpointId' => 4, 'code' => 'not_increasing'], $r['errors']);

        // Arrêts plus longs que le temps disponible entre deux temps fixés
        $checkpoints = [self::cp(1, 2000), self::cp(2, 3000, 'aid_station', 600), self::cp(3, 4000)];
        $filled = $this->calc->fillAuto($this->flat(10000), 10000, $checkpoints, [1 => 600, 3 => 1000], 3000);
        self::assertSame([1 => 600, 2 => 600, 3 => 1000], $filled['arrivals']);
    }

    #[Test]
    public function zeroEffortIntervalPlacesAutosAtTheLeftDeparture(): void
    {
        // Aucune distance connue, tous les repères au km 0
        $checkpoints = [self::cp(1, 0), self::cp(2, 0)];
        $filled = $this->calc->fillAuto([], 0, $checkpoints, [], 600);

        self::assertSame([1 => 0, 2 => 0], $filled['arrivals']);
    }

    #[Test]
    public function fillAutoIsMonotonicKeepsPinsAndNeverTouchesTheTarget(): void
    {
        mt_srand(20261115);
        for ($case = 0; $case < 300; ++$case) {
            $route = mt_rand(5000, 120000);
            $points = mt_rand(0, 1) ? $this->track($route, static fn (int $d): float => 800 + 300 * sin($d / 4000), 500) : [];
            $checkpoints = [];
            $n = mt_rand(1, 8);
            for ($i = 0; $i < $n; ++$i) {
                $aid = mt_rand(0, 2) === 0;
                $checkpoints[] = self::cp($i + 1, mt_rand(0, $route), $aid ? 'aid_station' : 'point', $aid ? mt_rand(0, 20) * 60 : null);
            }
            usort($checkpoints, static fn (array $a, array $b): int => $a['distanceM'] <=> $b['distanceM']);

            // Temps fixés cohérents : allure 4 à 8 min/km depuis le départ, arrêts compris
            $target = null;
            $pinned = [];
            $clock = 0;
            $previous = 0;
            foreach ($checkpoints as $cp) {
                $clock += (int) (($cp['distanceM'] - $previous) / 1000 * mt_rand(240, 480));
                $previous = $cp['distanceM'];
                if (mt_rand(0, 2) === 0) {
                    $pinned[$cp['id']] = $clock;
                }
                $clock += 'aid_station' === $cp['type'] ? (int) $cp['stopSeconds'] : 0;
            }
            if (mt_rand(0, 3) > 0) {
                $target = $clock + (int) (($route - $previous) / 1000 * mt_rand(240, 480)) + 60;
            }

            $filled = $this->calc->fillAuto($points, $route, $checkpoints, $pinned, $target);
            foreach ($pinned as $id => $seconds) {
                self::assertSame($seconds, $filled['arrivals'][$id]);
                self::assertTrue($filled['pinned'][$id]);
            }

            $r = $this->calc->compute($points, $route, $checkpoints, $filled['arrivals'], $target, null, pinned: $filled['pinned']);
            self::assertSame($target, $r['finish']['arrivalSeconds']);
            $departure = 0;
            foreach ($r['rows'] as $row) {
                if (null === $row['arrivalSeconds']) {
                    self::assertNull($target); // seulement sans cible, après le dernier temps fixé
                    continue;
                }
                self::assertGreaterThanOrEqual($departure, $row['arrivalSeconds']);
                $departure = (int) $row['departureSeconds'];
            }
            self::assertAutoTimesRounded($r);
            if (null !== $target) {
                self::assertGreaterThanOrEqual($departure, $target);
                self::assertTrue($r['totals']['complete']);
            }
        }
    }

    #[Test]
    public function autoTimesStayStrictlyIncreasingNextToCloseCheckpoints(): void
    {
        // Ravito (3 min) puis pointage 10 m plus loin : arrondi aux 10 s, le pointage tomberait pile sur le départ du ravito
        $checkpoints = [self::cp(1, 10000, 'aid_station', 180), self::cp(2, 10010)];
        self::assertSame([1 => 3510, 2 => 3694], $this->calc->distribute([], 20000, $checkpoints, 7200));
        $this->assertAutoPlanHasNoError([], 20000, $checkpoints, [], 7200);

        // Repère 5 m après le départ
        $checkpoints = [self::cp(1, 5), self::cp(2, 12000)];
        self::assertSame([1 => 3, 2 => 7780], $this->calc->distribute([], 25000, $checkpoints, 16200));
        $this->assertAutoPlanHasNoError([], 25000, $checkpoints, [], 16200);

        // Ravito (5 min) 5 m avant l'arrivée : le dernier tronçon garde du temps
        $checkpoints = [self::cp(1, 19995, 'aid_station', 300)];
        self::assertSame([1 => 6898], $this->calc->distribute([], 20000, $checkpoints, 7200));
        $this->assertAutoPlanHasNoError([], 20000, $checkpoints, [], 7200);

        // Repère 10 m avant l'arrivée : pas sur le temps visé
        $checkpoints = [self::cp(1, 19990)];
        self::assertSame([1 => 7196], $this->calc->distribute([], 20000, $checkpoints, 7200));
        $this->assertAutoPlanHasNoError([], 20000, $checkpoints, [], 7200);

        // Temps fixés cohérents 1:00:00 (10 km) et 1:00:40 (10,1 km), auto 10 m après le premier
        $checkpoints = [self::cp(1, 10000), self::cp(2, 10010), self::cp(3, 10100)];
        $filled = $this->calc->fillAuto([], 20000, $checkpoints, [1 => 3600, 3 => 3640], 7200);
        self::assertSame([1 => 3600, 2 => 3604, 3 => 3640], $filled['arrivals']);
        $this->assertAutoPlanHasNoError([], 20000, $checkpoints, [1 => 3600, 3 => 3640], 7200);

        // Même distance : temps égaux permis ; 3 m plus loin : 1 s de plus
        $checkpoints = [self::cp(1, 10000), self::cp(2, 10000), self::cp(3, 10003)];
        self::assertSame([1 => 3600, 2 => 3600, 3 => 3601], $this->calc->distribute([], 20000, $checkpoints, 7200));
        $this->assertAutoPlanHasNoError([], 20000, $checkpoints, [], 7200);

        // Fenêtre au plus juste : 4 s pour 4 tronçons de 1 m
        $checkpoints = [self::cp(1, 1), self::cp(2, 2), self::cp(3, 3)];
        self::assertSame([1 => 1, 2 => 2, 3 => 3], $this->calc->distribute([], 4, $checkpoints, 4));
        $this->assertAutoPlanHasNoError([], 4, $checkpoints, [], 4);

        // Avec tracé (effort = km + D+ / 100) : ravito au sommet, pointage 8 m après, repère 6 m avant l'arrivée
        $points = $this->track(30000, static fn (int $d): float => 500 + 150 * sin($d / 2500));
        $checkpoints = [self::cp(1, 12000, 'aid_station', 240), self::cp(2, 12008), self::cp(3, 29994)];
        $this->assertAutoPlanHasNoError($points, 30000, $checkpoints, [], 4 * 3600 + 7);
    }

    #[Test]
    public function consistentPinsAndFeasibleTargetNeverProduceErrors(): void
    {
        mt_srand(5150);
        for ($case = 0; $case < 400; ++$case) {
            [$points, $route, $checkpoints, $pinned, $target] = $this->randomConsistentPlan();
            $this->assertAutoPlanHasNoError($points, $route, $checkpoints, $pinned, $target);
        }
    }

    /**
     * Même générateur que consistentPinsAndFeasibleTargetNeverProduceErrors(), avec une stratégie d'allure et une
     * intensité tirées au hasard : temps fixés et cible intacts, temps croissants, aucune erreur de compute().
     */
    #[Test]
    public function consistentPinsAndFeasibleTargetNeverProduceErrorsWithAnyStrategy(): void
    {
        mt_srand(20261116);
        $strategies = PacingStrategy::cases();
        $intensities = PacingIntensity::cases();
        $used = [];
        for ($case = 0; $case < 400; ++$case) {
            [$points, $route, $checkpoints, $pinned, $target] = $this->randomConsistentPlan();
            $strategy = $strategies[mt_rand(0, \count($strategies) - 1)];
            $intensity = $intensities[mt_rand(0, \count($intensities) - 1)];
            $used[$strategy->value.'/'.$intensity->value] = true;

            $this->assertAutoPlanHasNoError($points, $route, $checkpoints, $pinned, $target, $strategy, $intensity);
        }
        self::assertCount(15, $used); // toutes les combinaisons tirées
    }

    /**
     * Plan aléatoire cohérent : repères souvent à quelques mètres les uns des autres, temps fixés croissants laissant
     * au moins 1 s par tronçon de longueur non nulle (arrêts compris), cible atteignable ou absente.
     *
     * @return array{list<array{int, ?float, float, float}>, int, list<array{id: int, name: string, type: string, distanceM: int, stopSeconds: ?int, cutoffSeconds: ?int}>, array<int, int>, ?int}
     */
    private function randomConsistentPlan(): array
    {
        $route = mt_rand(500, 100000);
        $points = 0 === mt_rand(0, 2) ? $this->track($route, static fn (int $d): float => 700 + 250 * sin($d / 3000), 250) : [];
        $checkpoints = [];
        $distances = [];
        $n = mt_rand(1, 9);
        for ($i = 0; $i < $n; ++$i) {
            // Beaucoup de repères à quelques mètres du départ, de l'arrivée ou d'un autre repère
            $draw = mt_rand(0, 9);
            $distance = match (true) {
                0 === $draw => mt_rand(0, 15),
                1 === $draw => $route - mt_rand(0, 15),
                $draw <= 4 && [] !== $distances => min($route, $distances[array_rand($distances)] + mt_rand(0, 15)),
                default => mt_rand(0, $route),
            };
            $distances[] = $distance;
            $aid = 0 === mt_rand(0, 2);
            $checkpoints[] = self::cp($i + 1, $distance, $aid ? 'aid_station' : 'point', $aid ? mt_rand(0, 20) * 60 + mt_rand(0, 59) : null);
        }
        usort($checkpoints, static fn (array $a, array $b): int => $a['distanceM'] <=> $b['distanceM']);

        // Plan cohérent : au moins 1 s sur chaque tronçon de longueur non nulle, arrêts compris
        $secondsPerMetre = mt_rand(150, 600) / 1000;
        $clock = 0;
        $previous = 0;
        $pinned = [];
        foreach ($checkpoints as $cp) {
            $gap = $cp['distanceM'] - $previous;
            $clock += $gap > 0 ? max(1, (int) ($gap * $secondsPerMetre)) : 0;
            $previous = $cp['distanceM'];
            if (0 === mt_rand(0, 3)) {
                $pinned[$cp['id']] = $clock;
            }
            $clock += 'aid_station' === $cp['type'] && $cp['distanceM'] < $route ? (int) $cp['stopSeconds'] : 0;
        }
        $gap = $route - $previous;
        $target = 0 === mt_rand(0, 4) ? null : $clock + ($gap > 0 ? max(1, (int) ($gap * $secondsPerMetre)) : 0) + mt_rand(0, 3600);

        return [$points, $route, $checkpoints, $pinned, $target];
    }

    #[Test]
    public function pinningAnAutoAtItsOwnValue(): void
    {
        // Cas exact (tronçons égaux) : aucun autre temps ne bouge
        $checkpoints = [self::cp(1, 2000), self::cp(2, 4000), self::cp(3, 6000), self::cp(4, 8000)];
        $before = $this->calc->fillAuto($this->flat(10000), 10000, $checkpoints, [1 => 700], 3200);
        $after = $this->calc->fillAuto($this->flat(10000), 10000, $checkpoints, [1 => 700, 3 => (int) $before['arrivals'][3]], 3200);
        self::assertSame($before['arrivals'], $after['arrivals']);
        self::assertTrue($after['pinned'][3]);

        // Cas général : l'arrondi aux 10 s du temps fixé peut décaler un voisin d'un pas d'arrondi au plus
        $points = $this->track(30000, static fn (int $d): float => 500 + 150 * sin($d / 2500));
        $checkpoints = [self::cp(1, 3100), self::cp(2, 7700), self::cp(3, 12900), self::cp(4, 18300), self::cp(5, 23600)];
        $before = $this->calc->fillAuto($points, 30000, $checkpoints, [], 3 * 3600 + 13);
        foreach ([1, 2, 3, 4, 5] as $id) {
            $after = $this->calc->fillAuto($points, 30000, $checkpoints, [$id => (int) $before['arrivals'][$id]], 3 * 3600 + 13);
            foreach ($before['arrivals'] as $other => $seconds) {
                self::assertEqualsWithDelta($seconds, $after['arrivals'][$other], SplitCalculator::ROUNDING_SECONDS);
            }
        }
    }

    // ── Stratégies d'allure ─────────────────────────────────────────────────────

    #[Test]
    public function pacedEffortsWeightEachSegmentByTheMultiplierAtItsMidpoint(): void
    {
        $checkpoints = [self::cp(1, 5000)];
        foreach (PacingIntensity::cases() as $intensity) {
            self::assertSame([5.0, 7.0], SplitCalculator::pacedEfforts($checkpoints, 10000, [5.0, 7.0], PacingStrategy::Even, $intensity));
        }
        // Milieux à 25 % et 75 % de la course : m = 1 + 0,06 * 0,25 puis 1 - 0,06 * 0,25
        self::assertEqualsWithDelta([5 * 1.015, 7 * 0.985], SplitCalculator::pacedEfforts($checkpoints, 10000, [5.0, 7.0], PacingStrategy::Negative, PacingIntensity::Medium), 1e-12);
        // Départ prudent marqué : 1er tronçon (milieu 12,5 %) plus lourd, 2e (milieu 62,5 %) inchangé
        self::assertEqualsWithDelta([1 + 0.06 * (1 - 0.125 / 0.3), 1.0], SplitCalculator::pacedEfforts([self::cp(1, 2500)], 10000, [1.0, 1.0], PacingStrategy::Conservative, PacingIntensity::Strong), 1e-12);
        // Distance nulle : x = 0 partout
        self::assertEqualsWithDelta([0.0, 2 * 1.03], SplitCalculator::pacedEfforts([self::cp(1, 0)], 0, [0.0, 2.0], PacingStrategy::Negative, PacingIntensity::Medium), 1e-12);
    }

    #[Test]
    public function evenStrategyIsTheHistoricalDistribution(): void
    {
        $points = $this->track(30000, static fn (int $d): float => 500 + 150 * sin($d / 2500));
        $checkpoints = [self::cp(1, 4000), self::cp(2, 9000, 'aid_station', 300), self::cp(3, 15000), self::cp(4, 21000, 'aid_station', 600), self::cp(5, 26000)];
        $reference = $this->calc->fillAuto($points, 30000, $checkpoints, [3 => 7300], 4 * 3600 + 7);
        foreach (PacingIntensity::cases() as $intensity) {
            self::assertSame($reference, $this->calc->fillAuto($points, 30000, $checkpoints, [3 => 7300], 4 * 3600 + 7, PacingStrategy::Even, $intensity));
        }
        self::assertSame(
            $this->calc->distribute($points, 30000, $checkpoints, 4 * 3600 + 7),
            $this->calc->distribute($points, 30000, $checkpoints, 4 * 3600 + 7, PacingStrategy::Even, PacingIntensity::Strong),
        );
    }

    /**
     * 100 km plats, un repère tous les 10 km : chaque tronçon reçoit cible * m(milieu) / somme des m (à l'arrondi
     * aux 10 s près) ; forme attendue de chaque stratégie ; arrivée exacte, aucune erreur.
     */
    #[Test]
    public function strategiesShapeTheAutoTimes(): void
    {
        $checkpoints = array_map(static fn (int $k): array => self::cp($k, $k * 10000), range(1, 9));
        $target = 360000; // tronçons d'environ 10 h : l'arrondi aux 10 s ne masque pas la forme
        foreach (PacingStrategy::cases() as $strategy) {
            foreach (PacingIntensity::cases() as $intensity) {
                $label = $strategy->value.'/'.$intensity->value;
                $filled = $this->calc->fillAuto([], 100000, $checkpoints, [], $target, $strategy, $intensity);
                self::assertSame($filled['arrivals'], $this->calc->distribute([], 100000, $checkpoints, $target, $strategy, $intensity), $label);
                $r = $this->calc->compute([], 100000, $checkpoints, $filled['arrivals'], $target, null, pinned: $filled['pinned']);
                self::assertSame([], $r['errors'], $label);
                self::assertSame($target, $r['finish']['arrivalSeconds'], $label);

                $segments = array_merge($r['rows'], [$r['finish']]);
                $weights = array_map(static fn (int $k): float => $strategy->multiplier(($k + 0.5) / 10, $intensity), range(0, 9));
                $total = array_sum($weights);
                $durations = [];
                foreach ($segments as $k => $row) {
                    self::assertEqualsWithDelta($target * $weights[$k] / $total, $row['segmentMovingSeconds'], SplitCalculator::ROUNDING_SECONDS, $label.' #'.$k);
                    $durations[] = (int) $row['segmentMovingSeconds'];
                }
                $deviations = array_column($segments, 'paceDeviationPct');

                switch ($strategy) {
                    case PacingStrategy::Even:
                        self::assertSame(array_fill(0, 10, 36000), $durations, $label);
                        self::assertSame(array_fill(0, 10, 0), $deviations, $label);
                        break;
                    case PacingStrategy::Conservative:
                        // Les 3 tronçons des 30 premiers % ralentis, de moins en moins ; les 7 suivants identiques
                        self::assertGreaterThan($durations[1], $durations[0], $label);
                        self::assertGreaterThan($durations[2], $durations[1], $label);
                        self::assertGreaterThan($durations[3], $durations[2], $label);
                        self::assertLessThanOrEqual(SplitCalculator::ROUNDING_SECONDS, max(\array_slice($durations, 3)) - min(\array_slice($durations, 3)), $label);
                        self::assertGreaterThan(0, $deviations[0], $label);
                        self::assertLessThanOrEqual(0, $deviations[9], $label);
                        break;
                    case PacingStrategy::Negative:
                        self::assertStrictlyMonotonic($durations, -1, $label);
                        self::assertGreaterThan(0, $deviations[0], $label);
                        self::assertLessThan(0, $deviations[9], $label);
                        break;
                    case PacingStrategy::Aggressive:
                    case PacingStrategy::Ultra:
                        self::assertStrictlyMonotonic($durations, 1, $label);
                        self::assertLessThan(0, $deviations[0], $label);
                        self::assertGreaterThan(0, $deviations[9], $label);
                        break;
                }

                // Effets annoncés de l'intensité moyenne
                if (PacingIntensity::Medium === $intensity) {
                    $firstHalf = array_sum(\array_slice($durations, 0, 5)) / ($target / 2) - 1;
                    match ($strategy) {
                        PacingStrategy::Negative => self::assertEqualsWithDelta(0.015, $firstHalf, 0.0005, $label),   // ~1,5 % plus lente
                        PacingStrategy::Aggressive => self::assertSame(-2, $deviations[0], $label),                // départ ~2 % plus rapide
                        PacingStrategy::Ultra => self::assertSame(14, $deviations[9] - $deviations[0], $label),     // fin ~15 % plus lente que le début
                        default => null,
                    };
                }
            }
        }
    }

    /** Un temps fixé coupe la course en intervalles : la stratégie ne déplace que les auto, chacun dans le sien. */
    #[Test]
    public function strategiesNeverMovePinsNorTheTarget(): void
    {
        $points = $this->track(60000, static fn (int $d): float => 800 + 300 * sin($d / 4000), 200);
        $checkpoints = [];
        for ($k = 1; $k <= 11; ++$k) {
            $checkpoints[] = self::cp($k, $k * 5000, 0 === $k % 4 ? 'aid_station' : 'point', 0 === $k % 4 ? 300 : null);
        }
        $pins = [6 => 4 * 3600];
        $target = 8 * 3600 + 1200;
        $even = $this->calc->fillAuto($points, 60000, $checkpoints, $pins, $target);
        foreach (PacingStrategy::cases() as $strategy) {
            foreach (PacingIntensity::cases() as $intensity) {
                $label = $strategy->value.'/'.$intensity->value;
                $this->assertAutoPlanHasNoError($points, 60000, $checkpoints, $pins, $target, $strategy, $intensity);
                $filled = $this->calc->fillAuto($points, 60000, $checkpoints, $pins, $target, $strategy, $intensity);
                self::assertSame(4 * 3600, $filled['arrivals'][6], $label);
                self::assertSame($even['pinned'], $filled['pinned'], $label);
                if (PacingStrategy::Even !== $strategy) {
                    self::assertNotSame($even['arrivals'], $filled['arrivals'], $label);
                }
            }
        }

        // Negative split : dans chaque intervalle, le premier auto est plus tard que la répartition régulière
        $negative = $this->calc->fillAuto($points, 60000, $checkpoints, $pins, $target, PacingStrategy::Negative, PacingIntensity::Strong);
        self::assertGreaterThan($even['arrivals'][1], $negative['arrivals'][1]);
        self::assertGreaterThan($even['arrivals'][7], $negative['arrivals'][7]);
    }

    #[Test]
    public function paceDeviationComparesEachSegmentWithTheAverageMovingPace(): void
    {
        // 280 s/km puis 320 s/km pour une moyenne de 300 s/km : -6,7 % et +6,7 %
        $r = $this->calc->compute($this->flat(10000), 10000, [self::cp(1, 5000)], [1 => 1400], 3000, null);
        self::assertSame(-7, $r['rows'][0]['paceDeviationPct']);
        self::assertSame(7, $r['finish']['paceDeviationPct']);

        // Arrondi au % le plus proche : +2,4 -> 2, -2,4 -> -2, +2,6 -> 3, -2,6 -> -3
        $r = $this->calc->compute([], 10000, [self::cp(1, 5000)], [1 => 5120], 10000, null);
        self::assertSame([2, -2], [$r['rows'][0]['paceDeviationPct'], $r['finish']['paceDeviationPct']]);
        $r = $this->calc->compute([], 10000, [self::cp(1, 5000)], [1 => 5130], 10000, null);
        self::assertSame([3, -3], [$r['rows'][0]['paceDeviationPct'], $r['finish']['paceDeviationPct']]);

        // Arrêts exclus des deux côtés : même allure en mouvement = 0 %
        $r = $this->calc->compute($this->flat(20000), 20000, [self::cp(1, 10000, 'aid_station', 300)], [1 => 3000], 6300, null);
        self::assertSame([0, 0], [$r['rows'][0]['paceDeviationPct'], $r['finish']['paceDeviationPct']]);

        // Repère sans temps : l'allure (et l'écart) part du dernier repère qui a un temps
        $r = $this->calc->compute([], 8000, [self::cp(1, 2000), self::cp(2, 4000), self::cp(3, 6000)], [1 => 600, 3 => 1800], 2400, null);
        self::assertSame([0, null, 0], array_column($r['rows'], 'paceDeviationPct'));
        self::assertSame(0, $r['finish']['paceDeviationPct']);

        // null : sans temps visé, arrêts >= temps visé, même distance, temps non croissant, distance nulle
        $r = $this->calc->compute([], 10000, [self::cp(1, 5000)], [1 => 1500], null, null);
        self::assertNull($r['rows'][0]['paceDeviationPct']);
        $r = $this->calc->compute([], 10000, [self::cp(1, 5000, 'aid_station', 3600)], [1 => 1500], 3000, null);
        self::assertNull($r['rows'][0]['paceDeviationPct']);
        $r = $this->calc->compute([], 6000, [self::cp(1, 3000), self::cp(2, 3000)], [1 => 900, 2 => 900], 1800, null);
        self::assertSame(0, $r['rows'][0]['paceDeviationPct']);
        self::assertNull($r['rows'][1]['paceDeviationPct']);
        $r = $this->calc->compute([], 8000, [self::cp(1, 2000), self::cp(2, 4000)], [1 => 600, 2 => 500], 2400, null);
        self::assertNull($r['rows'][1]['paceDeviationPct']);
        $r = $this->calc->compute([], 0, [], [], 600, null);
        self::assertNull($r['finish']['paceDeviationPct']);
    }

    #[Test]
    public function fillAutoRejectsWrongEffortCount(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->calc->fillAutoFromEfforts([self::cp(1, 5000)], 10000, [1.0], [], 3600);
    }

    #[Test]
    public function computeFlagsStopsExceedingTarget(): void
    {
        $r = $this->calc->compute([], 10000, [self::cp(1, 5000, 'aid_station', 3600)], [], 3000, null);

        self::assertContains(['checkpointId' => null, 'code' => 'stops_exceed_target'], $r['errors']);
        self::assertNull($r['totals']['movingSeconds']);
    }

    /**
     * fillAuto() puis compute() (comme ScenarioViewBuilder) : aucune erreur, temps fixés et cible intacts,
     * auto strictement croissants d'une distance à l'autre et arrondis comme documenté.
     *
     * @param list<array{int, ?float, float, float}>                                                              $points
     * @param list<array{id: int, name: string, type: string, distanceM: int, stopSeconds: ?int, cutoffSeconds: ?int}> $checkpoints
     * @param array<int, int>                                                                                       $pinned
     */
    private function assertAutoPlanHasNoError(array $points, int $route, array $checkpoints, array $pinned, ?int $target, PacingStrategy $strategy = PacingStrategy::Even, PacingIntensity $intensity = PacingIntensity::Medium): void
    {
        $filled = $this->calc->fillAuto($points, $route, $checkpoints, $pinned, $target, $strategy, $intensity);
        foreach ($pinned as $id => $seconds) {
            self::assertSame($seconds, $filled['arrivals'][$id]);
            self::assertTrue($filled['pinned'][$id]);
        }
        $r = $this->calc->compute($points, $route, $checkpoints, $filled['arrivals'], $target, null, pinned: $filled['pinned']);

        self::assertSame([], $r['errors'], (string) json_encode(['arrivals' => $filled['arrivals'], 'pinned' => $pinned, 'target' => $target, 'strategy' => $strategy->value.'/'.$intensity->value, 'checkpoints' => $checkpoints]));
        self::assertSame($target, $r['finish']['arrivalSeconds']);
        // Temps croissants (départ du point précédent atteint), auto compris ; sans cible, rien après le dernier temps fixé
        $departure = 0;
        foreach ($r['rows'] as $row) {
            if (null === $row['arrivalSeconds']) {
                self::assertNull($target);
                continue;
            }
            self::assertGreaterThanOrEqual($departure, $row['arrivalSeconds']);
            $departure = (int) $row['departureSeconds'];
        }
        self::assertAutoTimesRounded($r);
    }

    /**
     * @param list<int> $values
     * @param int       $direction 1 = strictement croissant, -1 = strictement décroissant
     */
    private static function assertStrictlyMonotonic(array $values, int $direction, string $message): void
    {
        for ($i = 1, $n = \count($values); $i < $n; ++$i) {
            self::assertGreaterThan(0, ($values[$i] - $values[$i - 1]) * $direction, $message.' '.json_encode($values));
        }
    }

    /**
     * Un temps auto est un multiple de 10 s, sauf s'il est à moins de 10 s du point précédent ou du suivant
     * (arrondi à la seconde pour ne pas coller deux temps).
     *
     * @param array{rows: list<array<string, mixed>>, finish: array<string, mixed>} $result compute()
     */
    private static function assertAutoTimesRounded(array $result): void
    {
        $timed = array_values(array_filter(
            array_merge($result['rows'], [$result['finish']]),
            static fn (array $row): bool => null !== $row['arrivalSeconds'],
        ));
        foreach ($timed as $i => $row) {
            if ($row['pinned'] || 'finish' === $row['type'] || 0 === $row['arrivalSeconds'] % SplitCalculator::ROUNDING_SECONDS) {
                continue;
            }
            $sincePrevious = $row['arrivalSeconds'] - ($i > 0 ? $timed[$i - 1]['departureSeconds'] : 0);
            $untilNext = isset($timed[$i + 1]) ? $timed[$i + 1]['arrivalSeconds'] - $row['departureSeconds'] : \PHP_INT_MAX;
            self::assertLessThan(SplitCalculator::ROUNDING_SECONDS, min($sincePrevious, $untilNext), sprintf('auto %d s (repère %d)', $row['arrivalSeconds'], $row['checkpointId']));
        }
    }
}
