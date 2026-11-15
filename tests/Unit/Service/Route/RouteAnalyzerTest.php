<?php

namespace App\Tests\Unit\Service\Route;

use App\Service\Route\GpxParser;
use App\Service\Route\RouteAnalyzer;
use PHPUnit\Framework\TestCase;

final class RouteAnalyzerTest extends TestCase
{
    private RouteAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new RouteAnalyzer();
    }

    /**
     * Tracé synthétique : un point tous les $stepM mètres, altitude = $ele(distance).
     *
     * @return list<array{int, ?float, float, float}>
     */
    private function track(int $lengthM, int $stepM, callable $ele): array
    {
        $points = [];
        for ($d = 0; $d <= $lengthM; $d += $stepM) {
            $points[] = [$d, round((float) $ele($d), 1), 45.0 + $d / 111195.08, 6.0];
        }

        return $points;
    }

    /**
     * Bruit pseudo-aléatoire déterministe dans [-$amp, $amp].
     */
    private function noise(int $seed, float $amp): \Closure
    {
        mt_srand($seed);
        $values = [];

        return static function (int $d) use (&$values, $amp): float {
            return $values[$d] ??= (mt_rand() / mt_getrandmax() * 2 - 1) * $amp;
        };
    }

    public function testElevationAtInterpolatesSkipsNullsAndClamps(): void
    {
        $points = [[0, 100.0, 45.0, 6.0], [100, 200.0, 45.001, 6.0], [200, null, 45.002, 6.0], [300, 100.0, 45.003, 6.0]];

        $this->assertSame(150.0, $this->analyzer->elevationAt($points, 50));
        $this->assertSame(200.0, $this->analyzer->elevationAt($points, 100));
        $this->assertSame(150.0, $this->analyzer->elevationAt($points, 200)); // entre 200 m (100) et 100 m (300)
        $this->assertSame(100.0, $this->analyzer->elevationAt($points, -50));
        $this->assertSame(100.0, $this->analyzer->elevationAt($points, 5000));

        $this->assertNull($this->analyzer->elevationAt([], 10));
        $this->assertNull($this->analyzer->elevationAt([[0, null, 45.0, 6.0], [100, null, 45.001, 6.0]], 50));
    }

    public function testPositionAtInterpolatesAndClamps(): void
    {
        $points = [[0, null, 45.0, 6.0], [1000, null, 45.01, 6.02], [1000, null, 45.02, 6.02], [2000, null, 45.03, 6.04]];

        $this->assertSame([45.005, 6.01], $this->analyzer->positionAt($points, 500));
        $this->assertSame([45.02, 6.02], $this->analyzer->positionAt($points, 1000)); // distance dupliquée : dernier point
        $this->assertSame([45.0, 6.0], $this->analyzer->positionAt($points, -10));
        $this->assertSame([45.03, 6.04], $this->analyzer->positionAt($points, 99999));
        $this->assertNull($this->analyzer->positionAt([], 0));
    }

    public function testCleanClimbGivesExactGain(): void
    {
        // 5 km à 10 % : 500 m de D+
        $points = $this->track(5000, 10, static fn (int $d): float => 1000 + $d * 0.1);

        $this->assertSame(['gain' => 500, 'loss' => 0], $this->analyzer->gainLoss($points, 0, 5000));
        $this->assertSame(['gain' => 200, 'loss' => 0], $this->analyzer->gainLoss($points, 1000, 3000));
        $this->assertSame(['gain' => 200, 'loss' => 0], $this->analyzer->gainLoss($points, 3000, 1000));
        $this->assertSame(['gain' => 500, 'loss' => 0], $this->analyzer->gainLoss($points, -100, 99999));
        $this->assertSame(['gain' => 0, 'loss' => 0], $this->analyzer->gainLoss($points, 2500, 2500));
    }

    public function testNoisyFlatTrackGivesAlmostNoGain(): void
    {
        // ±1,2 m de bruit (crête à crête 2,4 m < seuil 3 m) : sommer naïvement donnerait des centaines de mètres.
        $noise = $this->noise(42, 1.2);
        $points = $this->track(10000, 10, static fn (int $d): float => 800 + $noise($d));

        $naive = 0.0;
        for ($i = 1, $n = \count($points); $i < $n; ++$i) {
            $naive += max(0.0, (float) $points[$i][1] - (float) $points[$i - 1][1]);
        }
        $this->assertGreaterThan(300, $naive);

        $gl = $this->analyzer->gainLoss($points, 0, 10000);
        $this->assertLessThanOrEqual(3, $gl['gain']);
        $this->assertLessThanOrEqual(3, $gl['loss']);
    }

    public function testNoisyClimbAndDescent(): void
    {
        // 500 m de montée puis 500 m de descente, avec ±1 m de bruit
        $noise = $this->noise(7, 1.0);
        $points = $this->track(10000, 10, static fn (int $d): float => 500 + ($d <= 5000 ? $d : 10000 - $d) * 0.1 + $noise($d));

        $gl = $this->analyzer->gainLoss($points, 0, 10000);
        $this->assertEqualsWithDelta(500, $gl['gain'], 3);
        $this->assertEqualsWithDelta(500, $gl['loss'], 3);
    }

    public function testSmallBumpBelowThresholdIsIgnored(): void
    {
        // Bosse de 2 m sur le plat : sous le seuil ; bosse de 20 m : comptée en entier
        $small = $this->track(1000, 10, static fn (int $d): float => 300 + ($d >= 400 && $d <= 600 ? 2 - abs($d - 500) / 50 : 0));
        $big = $this->track(1000, 10, static fn (int $d): float => 300 + ($d >= 400 && $d <= 600 ? 20 - abs($d - 500) / 5 : 0));

        $this->assertSame(['gain' => 0, 'loss' => 0], $this->analyzer->gainLoss($small, 0, 1000));
        $this->assertSame(['gain' => 20, 'loss' => 20], $this->analyzer->gainLoss($big, 0, 1000));
    }

    public function testSegmentGainsAddUpToTotal(): void
    {
        $route = (new GpxParser())->parse((string) file_get_contents(__DIR__.'/../../../fixtures/gpx/trail_loop.gpx'));
        $marks = [];
        foreach ([137, 480, 801, 1234, 1570, 1999, 2400, 2777, 3050] as $i => $d) {
            $marks[] = ['name' => 'CP'.$i, 'distanceM' => $d];
        }
        $segments = $this->analyzer->segments($route->points, $marks, $route->distanceM);

        $this->assertCount(10, $segments);
        $this->assertEqualsWithDelta($route->gainM, array_sum(array_column($segments, 'gainM')), \count($segments));
        $this->assertEqualsWithDelta($route->lossM, array_sum(array_column($segments, 'lossM')), \count($segments));
        $this->assertSame($route->distanceM, array_sum(array_column($segments, 'distanceM')));

        // Idem sur un profil bruité dense
        $noise = $this->noise(99, 2.0);
        $points = $this->track(20000, 10, static fn (int $d): float => 1000 + 300 * sin($d / 1500) + $noise($d));
        $total = $this->analyzer->gainLoss($points, 0, 20000);
        $cuts = [['name' => 'a', 'distanceM' => 3333], ['name' => 'b', 'distanceM' => 7071], ['name' => 'c', 'distanceM' => 12345], ['name' => 'd', 'distanceM' => 17000]];
        $segments = $this->analyzer->segments($points, $cuts, 20000);
        $this->assertEqualsWithDelta($total['gain'], array_sum(array_column($segments, 'gainM')), \count($segments));
        $this->assertEqualsWithDelta($total['loss'], array_sum(array_column($segments, 'lossM')), \count($segments));
    }

    public function testSegmentsSortClampAndDegenerateCases(): void
    {
        // 2 km : 1 km à +10 % puis 1 km à plat
        $points = $this->track(2000, 10, static fn (int $d): float => 100 + min($d, 1000) * 0.1);
        $marks = [
            ['name' => 'Ravito', 'distanceM' => 1500],
            ['name' => 'Col', 'distanceM' => 1000],
            ['name' => 'Avant départ', 'distanceM' => -20],
            ['name' => 'Col bis', 'distanceM' => 1000],
            ['name' => 'Après arrivée', 'distanceM' => 2500],
        ];

        $segments = $this->analyzer->segments($points, $marks, 2000);

        $this->assertSame(
            [['Départ', 'Avant départ'], ['Avant départ', 'Col'], ['Col', 'Col bis'], ['Col bis', 'Ravito'], ['Ravito', 'Après arrivée'], ['Après arrivée', 'Arrivée']],
            array_map(static fn (array $s): array => [$s['fromName'], $s['toName']], $segments),
        );
        $this->assertSame([0, 1000, 0, 500, 500, 0], array_column($segments, 'distanceM'));

        $climb = $segments[1];
        $this->assertSame(0, $climb['fromDistanceM']);
        $this->assertSame(1000, $climb['toDistanceM']);
        $this->assertSame(100, $climb['gainM']);
        $this->assertSame(0, $climb['lossM']);
        $this->assertSame(100.0, $climb['startEle']);
        $this->assertSame(200.0, $climb['endEle']);
        $this->assertSame(10.0, $climb['avgGradePct']);

        // Segments de longueur nulle : pas de division par zéro
        foreach ([0, 2, 5] as $i) {
            $this->assertNull($segments[$i]['avgGradePct']);
            $this->assertSame(0, $segments[$i]['gainM']);
        }
        $this->assertSame(0.0, $segments[3]['avgGradePct']);
    }

    public function testSegmentsWithoutMarksOrElevation(): void
    {
        $points = [[0, null, 45.0, 6.0], [1000, null, 45.009, 6.0]];

        $segments = $this->analyzer->segments($points, [], 1000, 'Start', 'Finish');
        $this->assertSame([[
            'fromName' => 'Start',
            'toName' => 'Finish',
            'fromDistanceM' => 0,
            'toDistanceM' => 1000,
            'distanceM' => 1000,
            'gainM' => 0,
            'lossM' => 0,
            'startEle' => null,
            'endEle' => null,
            'avgGradePct' => null,
        ]], $segments);

        // Sans tracé du tout (course sans GPX) : les segments restent calculables
        $segments = $this->analyzer->segments([], [['name' => 'CP', 'distanceM' => 400]], 1000);
        $this->assertSame([400, 600], array_column($segments, 'distanceM'));
        $this->assertSame([0, 0], array_column($segments, 'gainM'));
    }
}
