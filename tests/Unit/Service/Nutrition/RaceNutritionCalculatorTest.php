<?php

namespace App\Tests\Unit\Service\Nutrition;

use App\Entity\RaceType;
use App\Service\Nutrition\RaceNutritionCalculator;
use App\Service\Nutrition\RaceNutritionCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Calcul du plan nutrition d'un scénario (RaceNutritionCalculator) : diffusion d'erreur, répartition boisson / gels /
 * solide, solide en début de course, unités entières, tronçons courts, lignes manuelles comptées à leur valeur réelle,
 * plafonds, apports aux points, statuts, scénario sans temps visé. Le miroir JS (race_nutrition_calculator.js) doit
 * avoir les mêmes constantes.
 *
 * @phpstan-import-type Input from RaceNutritionCalculator
 * @phpstan-import-type Result from RaceNutritionCalculator
 */
final class RaceNutritionCalculatorTest extends TestCase
{
    private RaceNutritionCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new RaceNutritionCalculator();
    }

    /**
     * Course : départ, repères 1..n, arrivée ; $seconds = durées en mouvement des n + 1 tronçons.
     *
     * @param list<?int>                                         $seconds
     * @param list<array{0: string, 1: bool}>                    $points  type et assistance de chaque repère
     * @param array<string, mixed>                               $settings
     * @param array<string, list<array{key: string, qty: int}>> $manual
     *
     * @return Input
     */
    private static function input(array $seconds, array $points = [], array $settings = [], array $manual = []): array
    {
        $defaults = RaceNutritionCatalog::defaultSettings(RaceType::Trail);
        unset($defaults['flaskMl']);
        $calcPoints = [['anchor' => 'start', 'type' => 'start', 'assistance' => false]];
        $segments = [];
        foreach ($seconds as $i => $s) {
            $anchor = $i < \count($seconds) - 1 ? (string) (100 + $i) : 'finish';
            $segments[] = ['anchor' => $anchor, 'seconds' => $s];
            if ($i < \count($seconds) - 1) {
                [$type, $assistance] = $points[$i] ?? ['point', false];
                $calcPoints[] = ['anchor' => $anchor, 'type' => $type, 'assistance' => $assistance];
            }
        }

        /** @var Input $input */
        $input = [
            'settings' => array_merge($defaults, $settings),
            'products' => RaceNutritionCatalog::calculatorProducts(RaceNutritionCatalog::products([], [])),
            'points' => $calcPoints,
            'segments' => $segments,
            'manual' => $manual,
        ];

        return $input;
    }

    /** @param Result $result */
    private static function qty(array $result, string $rowKey, string $product): int
    {
        foreach ($result['rows'] as $row) {
            if ($row['key'] === $rowKey) {
                foreach ($row['items'] as $line) {
                    if ($line['key'] === $product) {
                        return $line['qty'];
                    }
                }
            }
        }

        return 0;
    }

    /**
     * Glucides (centièmes) des lignes d'une catégorie (gel / solid / drink) sur tout le plan.
     *
     * @param Input  $input
     * @param Result $result
     */
    private static function carbsOfCategory(array $input, array $result, string $cat): int
    {
        $products = array_column($input['products'], null, 'key');
        $sum = 0;
        foreach ($result['rows'] as $row) {
            foreach ($row['items'] as $line) {
                if ($products[$line['key']]['cat'] === $cat) {
                    $sum += RaceNutritionCalculator::lineHundredths($products[$line['key']], $line['qty']);
                }
            }
        }

        return $sum;
    }

    #[Test]
    public function rowsAlternatePointsAndLegsIdentifiedByTheirEndPoint(): void
    {
        $result = $this->calc->compute(self::input([3600, 3600, 1800], [['aid_station', false], ['point', true]]));

        self::assertSame(['p:start', 's:100', 'p:100', 's:101', 'p:101', 's:finish'], array_column($result['rows'], 'key'));
        self::assertSame(['point', 'segment', 'point', 'segment', 'point', 'segment'], array_column($result['rows'], 'place'));
        self::assertTrue($result['ready']);
        self::assertSame(9000, $result['totals']['movingSeconds']);
    }

    #[Test]
    public function errorDiffusionTracksTheCumulativeTarget(): void
    {
        // Marathon à 3 h 15, ravitos tous les ~23 min, gels seulement (route) : le cumul suit la cible à une unité près
        $input = self::input(array_merge(array_fill(0, 8, 1385), [715]), array_fill(0, 8, ['aid_station', false]),
            ['mixDrink' => 0, 'mixGel' => 100, 'mixSolid' => 0, 'fluidPerHour' => 0]);
        $result = $this->calc->compute($input);

        $elapsed = 0;
        $cumulative = 0;
        foreach ($result['rows'] as $row) {
            $cumulative += $row['carbsH'];
            if ('segment' !== $row['place']) {
                continue;
            }
            $elapsed += (int) $row['seconds'];
            $target = intdiv(70 * 100 * $elapsed * 2 + 3600, 7200);
            self::assertLessThanOrEqual(1250, abs($cumulative - $target), 'within half a gel of 70 g/h x elapsed at '.$row['key']);
            foreach ($row['items'] as $line) {
                self::assertSame('gel', $line['key']);
                self::assertIsInt($line['qty']);
            }
        }
        self::assertSame('within', $result['totals']['status']);
        self::assertEqualsWithDelta(70, (int) $result['totals']['carbsPerHour'], 7);
    }

    #[Test]
    public function liquidShareComesFromTheDrinkAndWaterFillsTheVolume(): void
    {
        // 4 h, 70 g/h, 30 % boisson : isotonique (30 g / 500 ml) -> 21 g/h = 350 ml/h ; 500 ml/h au total
        $input = self::input([3600, 3600, 3600, 3600], [], ['mixDrink' => 30, 'mixGel' => 70, 'mixSolid' => 0]);
        $result = $this->calc->compute($input);

        self::assertSame(2000, $result['totals']['fluidMl'], '500 ml/h over 4 h');
        $isotonic = 0;
        $water = 0;
        foreach ($result['rows'] as $row) {
            foreach ($row['items'] as $line) {
                if (\in_array($line['key'], ['isotonic', 'water'], true)) {
                    self::assertSame(0, $line['qty'] % RaceNutritionCalculator::FLUID_STEP_ML, 'drinks in 250 ml steps');
                }
            }
            $isotonic += self::qty($result, $row['key'], 'isotonic');
            $water += self::qty($result, $row['key'], 'water');
        }
        self::assertEqualsWithDelta(1400, $isotonic, 250, '350 ml/h of the carb drink');
        self::assertSame(2000 - $isotonic, $water);
        self::assertEqualsWithDelta(70 * 4 * 30, self::carbsOfCategory($input, $result, 'drink'), 1500, 'about 30 % of the carbs from the drink');
    }

    #[Test]
    public function drinkCannotExceedTheFluidTargetAndGelsMakeUpTheRest(): void
    {
        // 100 % boisson voulus mais 500 ml/h d'isotonique ne donnent que 30 g/h : les gels complètent jusqu'à 70 g/h
        $input = self::input([3600, 3600, 3600], [], ['mixDrink' => 100, 'mixGel' => 0, 'mixSolid' => 0]);
        $result = $this->calc->compute($input);
        self::assertSame(1500, $result['totals']['fluidMl']);
        self::assertSame(0, self::carbsOfCategory($input, $result, 'gel'), 'gels share 0 % : no gel at all');

        $input = self::input([3600, 3600, 3600], [], ['mixDrink' => 80, 'mixGel' => 20, 'mixSolid' => 0]);
        $result = $this->calc->compute($input);
        self::assertSame(9000, self::carbsOfCategory($input, $result, 'drink'), 'all the fluid is the carb drink (1.5 L = 90 g)');
        self::assertSame('within', $result['totals']['status'], 'gels fill the gap up to the target');
    }

    #[Test]
    public function solidShareFollowsTheMixAndSolidOnlyPlansUseSolids(): void
    {
        $input = self::input(array_fill(0, 6, 3600), [], ['mixDrink' => 0, 'mixGel' => 50, 'mixSolid' => 50, 'solidEarly' => false, 'fluidPerHour' => 0]);
        $result = $this->calc->compute($input);
        $solid = self::carbsOfCategory($input, $result, 'solid');
        $gel = self::carbsOfCategory($input, $result, 'gel');
        self::assertEqualsWithDelta(0.5, $solid / ($solid + $gel), 0.12, 'about half of the food carbs from solids');

        $input = self::input(array_fill(0, 3, 3600), [], ['mixDrink' => 0, 'mixGel' => 0, 'mixSolid' => 100, 'fluidPerHour' => 0]);
        $result = $this->calc->compute($input);
        self::assertSame(0, self::carbsOfCategory($input, $result, 'gel'));
        self::assertSame('within', $result['totals']['status'], 'bars only, still on target');

        // Part solide demandée mais aucun produit solide choisi : les gels prennent tout
        $input = self::input(array_fill(0, 3, 3600), [], ['solid' => null, 'fluidPerHour' => 0]);
        $result = $this->calc->compute($input);
        self::assertSame(0, self::carbsOfCategory($input, $result, 'solid'));
        self::assertSame('within', $result['totals']['status']);
    }

    #[Test]
    public function solidEarlyMovesSolidsToTheFirstHalf(): void
    {
        $share = static function (bool $early): array {
            $input = self::input(array_fill(0, 8, 3600), [], ['mixDrink' => 0, 'mixGel' => 50, 'mixSolid' => 50, 'solidEarly' => $early, 'fluidPerHour' => 0]);
            $result = (new RaceNutritionCalculator())->compute($input);
            $first = 0;
            $second = 0;
            foreach ($result['rows'] as $i => $row) {
                $bars = self::qty($result, $row['key'], 'bar');
                if ($i < 8) {
                    $first += $bars;
                } else {
                    $second += $bars;
                }
            }

            return [$first, $second];
        };

        [$evenFirst, $evenSecond] = $share(false);
        [$earlyFirst, $earlySecond] = $share(true);
        self::assertGreaterThan($earlySecond, $earlyFirst, 'solid early: more bars in the first half');
        self::assertGreaterThan($evenFirst, $earlyFirst);
        self::assertLessThan($evenSecond, $earlySecond);
    }

    #[Test]
    public function legsOfJustOverOneUnitStillReachTheTargetOnAverage(): void
    {
        // 70 g/h en tronçons de 28 min = 32,7 g par tronçon (1,3 gel) : le plafond laisse passer 2 gels quand il faut
        $input = self::input(array_fill(0, 10, 1680), [], ['mixDrink' => 0, 'mixGel' => 100, 'mixSolid' => 0, 'fluidPerHour' => 0]);
        $result = $this->calc->compute($input);
        self::assertEqualsWithDelta(70, (int) $result['totals']['carbsPerHour'], 4);

        // Même chose pour l'eau : 600 ml/h en tronçons de 30 min = 300 ml (1,2 pas de 250 ml)
        $input = self::input(array_fill(0, 10, 1800), [], ['fluidPerHour' => 600, 'drink' => null]);
        $result = $this->calc->compute($input);
        self::assertEqualsWithDelta(600, (int) $result['totals']['fluidPerHour'], 50);
    }

    #[Test]
    public function veryShortLegsCarryTheirShareOver(): void
    {
        // Tronçons de 5 min : la plupart ne reçoivent rien, une unité arrive quand le report suffit
        $input = self::input(array_fill(0, 12, 300), [], ['mixDrink' => 0, 'mixGel' => 100, 'mixSolid' => 0, 'fluidPerHour' => 0]);
        $result = $this->calc->compute($input);

        $empty = 0;
        foreach ($result['rows'] as $row) {
            if ('segment' === $row['place']) {
                $empty += [] === $row['items'] ? 1 : 0;
                self::assertLessThanOrEqual(1, self::qty($result, $row['key'], 'gel'), 'at most one gel on a 5 min leg');
                self::assertSame('none', $row['status'], 'no status on a leg shorter than 20 min');
            }
        }
        self::assertGreaterThanOrEqual(9, $empty);
        self::assertEqualsWithDelta(70, (int) $result['totals']['carbsPerHour'], 13, 'one hour: about 70 g in total');
    }

    #[Test]
    public function manualRowsCountWithTheirRealCarbs(): void
    {
        $base = self::input([3600, 3600, 3600, 3600], [], ['mixDrink' => 0, 'mixGel' => 100, 'mixSolid' => 0, 'fluidPerHour' => 0]);
        $auto = $this->calc->compute($base);

        // Tronçon 1 vide (fixé à la main) : rattrapé ensuite, sans dépasser 150 % de la cible par tronçon
        $empty = $base;
        $empty['manual'] = ['s:100' => []];
        $result = $this->calc->compute($empty);
        self::assertTrue($result['rows'][1]['manual']);
        self::assertSame([], $result['rows'][1]['items']);
        self::assertGreaterThan(self::qty($auto, 's:101', 'gel'), self::qty($result, 's:101', 'gel'), 'next leg catches up');
        foreach (['s:101', 's:102', 's:finish'] as $key) {
            self::assertLessThanOrEqual(10500, self::qty($result, $key, 'gel') * 2500, "cap 150 % of 70 g on $key");
        }

        // Tronçon 1 chargé à la main (6 gels) : les suivants mangent moins
        $heavy = $base;
        $heavy['manual'] = ['s:100' => [['key' => 'gel', 'qty' => 6]]];
        $result = $this->calc->compute($heavy);
        self::assertSame([['key' => 'gel', 'qty' => 6]], $result['rows'][1]['items'], 'manual row kept as entered');
        self::assertLessThan(self::qty($auto, 's:101', 'gel'), self::qty($result, 's:101', 'gel'));
        self::assertSame(15000, $result['rows'][1]['carbsH']);
    }

    #[Test]
    public function autoLegsNeverExceedTheirCap(): void
    {
        mt_srand(7);
        for ($case = 0; $case < 300; ++$case) {
            $seconds = array_map(static fn (): int => mt_rand(0, 3) === 0 ? mt_rand(60, 900) : mt_rand(900, 3 * 3600), range(0, mt_rand(1, 8)));
            $manual = mt_rand(0, 1) ? ['s:100' => []] : [];
            $input = self::input($seconds, [], ['carbsPerHour' => mt_rand(30, 120), 'fluidPerHour' => mt_rand(0, 20) * 50], $manual);
            $result = $this->calc->compute($input);
            $products = array_column($input['products'], null, 'key');
            $elapsed = 0;
            foreach ($result['rows'] as $i => $row) {
                if ('segment' !== $row['place']) {
                    continue;
                }
                $a = $elapsed;
                $elapsed += (int) $row['seconds'];
                if ($row['manual']) {
                    continue;
                }
                $food = 0;
                $drink = 0;
                $fluid = 0;
                // Les solides mangés au point de départ du tronçon comptent avec lui
                foreach (array_merge($row['items'], $result['rows'][$i - 1]['manual'] ? [] : $result['rows'][$i - 1]['items']) as $line) {
                    $carbs = RaceNutritionCalculator::lineHundredths($products[$line['key']], $line['qty']);
                    if ('drink' === $products[$line['key']]['kind']) {
                        $drink += $carbs;
                        $fluid += $line['qty'];
                    } else {
                        $food += $carbs;
                    }
                }
                $target = intdiv(2 * $input['settings']['carbsPerHour'] * 100 * $elapsed + 3600, 7200) - intdiv(2 * $input['settings']['carbsPerHour'] * 100 * $a + 3600, 7200);
                $cap = max(intdiv(2 * $target * 150 + 100, 200), $target + 3500);
                self::assertLessThanOrEqual(max($cap - $drink, 3500), $food, "food cap, case $case ".$row['key']);
                $fluidTarget = $input['settings']['fluidPerHour'] * ($elapsed - $a);
                self::assertLessThanOrEqual(max(intdiv($fluidTarget * 150, 360000), intdiv($fluidTarget, 3600) + 250), $fluid, "fluid cap, case $case");
                self::assertSame(0, $fluid % 250);
            }
        }
    }

    #[Test]
    public function solidsAreEatenAtAidStationsAndCrewPointsWhenThePointIsAuto(): void
    {
        $settings = ['mixDrink' => 0, 'mixGel' => 50, 'mixSolid' => 50, 'solidEarly' => false, 'fluidPerHour' => 0];
        $points = [['aid_station', false], ['point', true], ['point', false]];
        $result = $this->calc->compute(self::input([3600, 3600, 3600, 3600], $points, $settings));

        foreach (['s:101', 's:102'] as $legFromEligiblePoint) {
            self::assertSame(0, self::qty($result, $legFromEligiblePoint, 'bar'), "no bar on $legFromEligiblePoint : eaten at its starting point");
        }
        self::assertGreaterThan(0, self::qty($result, 'p:100', 'bar') + self::qty($result, 'p:101', 'bar'), 'bars at the aid station / crew point');
        self::assertSame([], $result['rows'][0]['items'], 'never anything auto at the start');
        self::assertSame([], $result['rows'][6]['items'], 'plain point: no auto item');

        // Point manuel : le solide reste sur le tronçon
        $result = $this->calc->compute(self::input([3600, 3600, 3600, 3600], $points, $settings, ['p:100' => []]));
        self::assertSame([], $result['rows'][2]['items']);
        self::assertGreaterThan(0, self::qty($result, 's:101', 'bar') + self::qty($result, 's:102', 'bar') + self::qty($result, 's:finish', 'bar'));
    }

    #[Test]
    public function noTargetTimeMeansNoAutoPlanButManualEntriesCount(): void
    {
        $input = self::input([null, null, null], [], [], ['p:start' => [['key' => 'gel', 'qty' => 2]], 's:finish' => [['key' => 'water', 'qty' => 500]]]);
        $result = $this->calc->compute($input);

        self::assertFalse($result['ready']);
        self::assertSame(5000, $result['totals']['carbsH']);
        self::assertSame(500, $result['totals']['fluidMl']);
        self::assertNull($result['totals']['carbsPerHour']);
        self::assertNull($result['totals']['movingSeconds']);
        self::assertSame('none', $result['totals']['status']);
        foreach ($result['rows'] as $row) {
            if (!$row['manual']) {
                self::assertSame([], $row['items']);
            }
            self::assertNull($row['carbsPerHour']);
        }
        self::assertSame([['key' => 'gel', 'qty' => 2], ['key' => 'water', 'qty' => 500]], $result['totals']['products']);

        // Durées nulles : pas de calcul auto non plus
        self::assertFalse($this->calc->compute(self::input([0, 0]))['ready']);
    }

    /** @return iterable<string, array{?int, int, string}> */
    public static function statuses(): iterable
    {
        yield 'no rate' => [null, 70, 'none'];
        yield 'lower bound (70 - 14)' => [56, 70, 'within'];
        yield 'just below' => [55, 70, 'below'];
        yield 'upper bound (70 + 14)' => [84, 70, 'within'];
        yield 'just above' => [85, 70, 'above'];
        yield 'zero target, zero rate' => [0, 0, 'within'];
        yield 'zero target, some rate' => [1, 0, 'above'];
    }

    #[Test]
    #[DataProvider('statuses')]
    public function statusUsesATwentyPercentTolerance(?int $rate, int $target, string $expected): void
    {
        self::assertSame($expected, RaceNutritionCalculator::status($rate, $target));
    }

    #[Test]
    public function legRateIncludesWhatIsTakenAtItsStartingPoint(): void
    {
        $input = self::input([3600, 3600], [['aid_station', false]], ['fluidPerHour' => 0], [
            'p:100' => [['key' => 'bar', 'qty' => 1]],
            's:finish' => [['key' => 'gel', 'qty' => 1]],
        ]);
        $result = $this->calc->compute($input);
        self::assertSame(60, $result['rows'][3]['carbsPerHour'], '35 g at the point + 25 g on the leg in 1 h');
        self::assertSame('within', $result['rows'][3]['status']);
        self::assertNull($result['rows'][2]['carbsPerHour'], 'no rate on a point row');
    }

    #[Test]
    public function lineCarbsFollowTheActivityFuelRule(): void
    {
        $gel = ['key' => 'gel', 'kind' => 'food', 'cat' => 'gel', 'tenths' => 225];
        $drink = ['key' => 'isotonic', 'kind' => 'drink', 'cat' => 'drink', 'tenths' => 305];
        self::assertSame(4500, RaceNutritionCalculator::lineHundredths($gel, 2));
        self::assertSame(1525, RaceNutritionCalculator::lineHundredths($drink, 250), '30.5 g / 500 ml x 250 ml');
        self::assertSame(0, RaceNutritionCalculator::lineHundredths(null, 3));
        self::assertSame(0, RaceNutritionCalculator::lineHundredths($gel, 0));
    }

    #[Test]
    public function javascriptMirrorHasTheSameConstants(): void
    {
        $js = (string) file_get_contents(__DIR__.'/../../../../assets/controllers/race_nutrition_calculator.js');
        $constants = [
            'CAP_PCT' => RaceNutritionCalculator::CAP_PCT,
            'STATUS_TOLERANCE_PCT' => RaceNutritionCalculator::STATUS_TOLERANCE_PCT,
            'STATUS_MIN_SECONDS' => RaceNutritionCalculator::STATUS_MIN_SECONDS,
            'FLUID_STEP_ML' => RaceNutritionCalculator::FLUID_STEP_ML,
            'SIGMA_SCALE' => RaceNutritionCalculator::SIGMA_SCALE,
        ];
        foreach ($constants as $name => $value) {
            self::assertMatchesRegularExpression('/export const '.$name.' = '.$value.';/', $js, $name);
        }
        self::assertStringContainsString("export const WATER_KEY = '".RaceNutritionCatalog::WATER_KEY."';", $js);
        self::assertStringNotContainsString('Math.round(', $js, 'integer maths only (no float rounding)');
    }
}
