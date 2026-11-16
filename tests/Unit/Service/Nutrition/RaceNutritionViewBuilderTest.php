<?php

namespace App\Tests\Unit\Service\Nutrition;

use App\Entity\RaceCheckpoint;
use App\Entity\RaceNutritionItem;
use App\Entity\RaceNutritionPlan;
use App\Entity\RaceType;
use App\Repository\ActivityFuelRepository;
use App\Repository\RaceNutritionItemRepository;
use App\Repository\RaceNutritionPlanRepository;
use App\Service\Nutrition\RaceNutritionCalculator;
use App\Service\Nutrition\RaceNutritionViewBuilder;
use App\Tests\Unit\Service\Share\ShareFixtures;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Données du plan nutrition d'un scénario (RaceNutritionViewBuilder) à partir du VRAI ScenarioViewBuilder : durées des
 * tronçons = temps de passage enregistrés, arrêts exclus ; réglages par défaut selon le type de course ; glucides
 * proposés = dernière valeur de l'utilisateur ; lignes d'un repère hors du tracé ignorées.
 */
final class RaceNutritionViewBuilderTest extends TestCase
{
    use ShareFixtures;

    /**
     * @param array<int, RaceNutritionPlan>            $plans
     * @param array<int, list<RaceNutritionItem>>      $rows
     * @param array<string, float>                     $remembered
     */
    private function builder(array $plans = [], array $rows = [], array $remembered = []): RaceNutritionViewBuilder
    {
        $planRepo = $this->createStub(RaceNutritionPlanRepository::class);
        $planRepo->method('findForScenarios')->willReturn($plans);
        $itemRepo = $this->createStub(RaceNutritionItemRepository::class);
        $itemRepo->method('findForPlans')->willReturn($rows);
        $fuel = $this->createStub(ActivityFuelRepository::class);
        $fuel->method('findLatestCarbsByItemKey')->willReturn($remembered);

        return new RaceNutritionViewBuilder($planRepo, $itemRepo, $fuel, new RaceNutritionCalculator());
    }

    #[Test]
    public function legDurationsAreTheSavedMovingTimes(): void
    {
        $race = self::race();
        $route = self::route($race);
        $scenarios = [self::scenario($race, 11, 'A', '08:00', 4 * 3600 + 30 * 60), self::scenario($race, 12, 'Sans temps', null, null, 1)];
        $view = $this->scenarioView($race, $route, self::checkpoints($race), $scenarios, [11 => [102 => 5400]]);
        $plans = $this->builder()->build($race, $view);

        $segments = $plans[11]['input']['segments'];
        self::assertSame(['101', '102', '103', '104', 'finish'], array_column($segments, 'anchor'));
        self::assertSame(4 * 3600 + 30 * 60 - 180 - 300, array_sum(array_column($segments, 'seconds')), 'moving time = target - aid station stops');
        $rows = $view['scenarios'][0]['calc']['rows'];
        self::assertSame(5400 - (int) $rows[0]['arrivalSeconds'], $segments[1]['seconds'], 'leg 2 = pinned arrival at Ravito 1 - departure from the Col');
        self::assertSame((int) $rows[2]['arrivalSeconds'] - 5400 - 180, $segments[2]['seconds'], 'stop at Ravito 1 excluded');
        self::assertTrue($plans[11]['result']['ready']);

        self::assertSame([null, null, null, null, null], array_column($plans[12]['input']['segments'], 'seconds'), 'no target time: durations unknown');
        self::assertFalse($plans[12]['result']['ready']);
    }

    #[Test]
    public function defaultsDependOnTheRaceTypeAndUseRememberedCarbs(): void
    {
        $race = self::race()->setType(RaceType::Trail);
        $view = $this->scenarioView($race, self::route($race), self::checkpoints($race), [self::scenario($race, 11, 'A', '08:00', 3 * 3600)]);
        $plan = $this->builder([], [], ['gel' => 22.0])->build($race, $view)[11];

        self::assertFalse($plan['hasPlan']);
        self::assertSame(['mixDrink' => 30, 'mixGel' => 40, 'mixSolid' => 30, 'solidEarly' => true], array_intersect_key($plan['settings'], array_flip(['mixDrink', 'mixGel', 'mixSolid', 'solidEarly'])), 'trail defaults');
        $road = $this->builder()->build($race->setType(RaceType::Road), $view)[11]['settings'];
        self::assertSame([30, 70, 0, false], [$road['mixDrink'], $road['mixGel'], $road['mixSolid'], $road['solidEarly']], 'road defaults: no solid');
        $unknown = $this->builder()->build($race->setType(null), $view)[11]['settings'];
        self::assertSame([30, 40, 30, false], [$unknown['mixDrink'], $unknown['mixGel'], $unknown['mixSolid'], $unknown['solidEarly']]);
        $gel = array_values(array_filter($plan['products'], static fn (array $p): bool => 'gel' === $p['key']))[0];
        self::assertSame(22.0, $gel['carbs'], 'last carbs saved by the user in activity fueling');
        self::assertSame(220, array_column($plan['input']['products'], 'tenths', 'key')['gel']);
        self::assertSame(['start', '101', '102', '103', '104'], array_column($plan['input']['points'], 'anchor'));
        self::assertSame('aid_station', $plan['input']['points'][2]['type']);
    }

    #[Test]
    public function storedPlanAndManualRowsAreUsedRowsBeyondTheRouteAreIgnored(): void
    {
        $race = self::race();
        $route = self::route($race);
        $cps = self::checkpoints($race);
        $beyond = (new RaceCheckpoint())->setRace($race)->setName('Après')->setDistanceM(30000);
        self::setId($beyond, 109);
        $scenario = self::scenario($race, 11, 'A', '08:00', 3 * 3600);
        $view = $this->scenarioView($race, $route, array_merge($cps, [$beyond]), [$scenario]);
        self::assertCount(4, $view['checkpoints'], 'checkpoint beyond the route end not in the table');

        $plan = (new RaceNutritionPlan())->setScenario($scenario)->setCarbsPerHour(90)->setMixDrink(0)->setMixGel(100)->setMixSolid(0)
            ->setProducts([['key' => 'gel', 'kind' => 'food', 'cat' => 'gel', 'label' => null, 'carbs' => 30.0]]);
        self::setId($plan, 5);
        $rows = [
            (new RaceNutritionItem())->setPlan($plan)->setPlace('point')->setCheckpoint(null)->setItems([['key' => 'gel', 'qty' => 1]]),
            (new RaceNutritionItem())->setPlan($plan)->setPlace('segment')->setCheckpoint($cps[1])->setItems([]),
            (new RaceNutritionItem())->setPlan($plan)->setPlace('segment')->setCheckpoint($beyond)->setItems([['key' => 'gel', 'qty' => 9]]),
        ];
        $built = $this->builder([11 => $plan], [5 => $rows], ['gel' => 22.0])->build($race, $view)[11];

        self::assertTrue($built['hasPlan']);
        self::assertSame(90, $built['settings']['carbsPerHour']);
        self::assertSame(30.0, array_column($built['products'], 'carbs', 'key')['gel'], 'carbs saved in the plan win over the remembered value');
        self::assertSame(['p:start' => [['key' => 'gel', 'qty' => 1]], 's:102' => []], $built['input']['manual']);
        self::assertSame(2, $built['manualCount']);
        self::assertSame([], $built['result']['rows'][3]['items'], 'manual empty leg kept empty');
        self::assertNotEmpty($built['carry'], 'carry list computed');
    }
}
