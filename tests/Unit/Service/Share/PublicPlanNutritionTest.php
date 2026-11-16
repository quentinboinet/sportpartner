<?php

namespace App\Tests\Unit\Service\Share;

use App\Entity\RaceNutritionItem;
use App\Entity\RaceNutritionPlan;
use App\Repository\ActivityFuelRepository;
use App\Repository\RaceNutritionItemRepository;
use App\Repository\RaceNutritionPlanRepository;
use App\Service\Nutrition\RaceNutritionCalculator;
use App\Service\Nutrition\RaceNutritionViewBuilder;
use App\Service\Share\PublicPlanBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Option « nutrition » de la page publique (PublicPlanBuilder) : désactivée = aucune donnée nutrition ; activée =
 * seulement aux points d'assistance et seulement pour le scénario partagé, en valeurs scalaires de la liste blanche.
 */
final class PublicPlanNutritionTest extends TestCase
{
    use ShareFixtures;

    private const CUSTOM_LABEL = 'SENTINEL-CUSTOM-PRODUCT';

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function build(bool $option, int $nutritionScenario = 11): array
    {
        $race = self::race();
        $route = self::route($race);
        $cps = self::checkpoints($race);
        $cps[2]->setAssistance(true); // Sommet : assistance
        $share = self::share($race)->setShowNutrition($option);
        $scenarios = [
            self::scenario($race, 11, self::SENTINELS['scenarioName'], '08:00', 4 * 3600 + 30 * 60, 0),
            self::scenario($race, 12, self::SENTINELS['otherScenario'], '21:30', 5 * 3600 + 10 * 60, 1),
        ];
        $sv = $this->scenarioView($race, $route, $cps, $scenarios);

        $plan = (new RaceNutritionPlan())->setScenario($scenarios[0])
            ->setProducts([['key' => 'custom_1', 'kind' => 'food', 'cat' => 'solid', 'label' => self::CUSTOM_LABEL, 'carbs' => 30.0]]);
        $rows = [(new RaceNutritionItem())->setPlan($plan)->setPlace('segment')->setCheckpoint($cps[3])->setItems([['key' => 'custom_1', 'qty' => 2]])];
        $builder = new RaceNutritionViewBuilder($this->createStub(RaceNutritionPlanRepository::class), $this->createStub(RaceNutritionItemRepository::class),
            $this->createStub(ActivityFuelRepository::class), new RaceNutritionCalculator());
        $index = 11 === $nutritionScenario ? 0 : 1;
        $nutrition = $builder->buildOne($race, $sv['checkpoints'], $sv['scenarios'][$index], 11 === $nutritionScenario ? $plan : null, 11 === $nutritionScenario ? $rows : [], []);

        $selected = PublicPlanBuilder::selectScenario($sv['scenarios'], 11);
        $view = (new PublicPlanBuilder())->build($race, $share, $route, $sv['checkpoints'], $sv['routeDistanceM'], $selected, $nutrition);

        return [$view, $nutrition];
    }

    #[Test]
    public function optionOffSendsNoNutritionData(): void
    {
        [$view] = $this->build(false);

        self::assertFalse($view['showNutrition']);
        foreach ($view['points'] as $point) {
            self::assertNull($point['nutrition']);
        }
        $json = json_encode($view, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString(self::CUSTOM_LABEL, $json);
        self::assertStringNotContainsString('isotonic', $json);
    }

    #[Test]
    public function optionOnShowsWhatToHandOnlyAtCrewPoints(): void
    {
        [$view, $nutrition] = $this->build(true);

        self::assertTrue($view['showNutrition']);
        $withNutrition = array_values(array_filter($view['points'], static fn (array $p): bool => null !== $p['nutrition']));
        self::assertSame(['Sommet'], array_column($withNutrition, 'name'), 'crew point only (not the start, not official aid stations)');
        $sommet = $withNutrition[0]['nutrition'];
        self::assertSame(['give', 'fill', 'flasks', 'flaskMl'], array_keys($sommet));
        foreach ($sommet['give'] as $line) {
            self::assertSame(['key', 'label', 'qty', 'drink'], array_keys($line));
        }
        foreach ($sommet['fill'] as $line) {
            self::assertSame(['key', 'label', 'ml', 'flasks'], array_keys($line));
        }
        self::assertContains(['key' => null, 'label' => self::CUSTOM_LABEL, 'qty' => 2, 'drink' => false], $sommet['give'], 'custom product by its name, catalogue products by key only');
        self::assertSame(500, $sommet['flaskMl']);

        // Liste blanche : seulement des scalaires, aucune donnée privée
        array_walk_recursive($view, static function (mixed $value): void {
            self::assertTrue(null === $value || \is_scalar($value));
        });
        $json = json_encode($view, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        foreach (self::SENTINELS as $what => $sentinel) {
            self::assertStringNotContainsString($sentinel, $json, $what.' leaked');
        }
        self::assertSame(11, $nutrition['scenarioId']);
    }

    #[Test]
    public function nutritionOfAnotherScenarioIsIgnored(): void
    {
        [$view] = $this->build(true, 12);

        self::assertFalse($view['showNutrition'], 'only the shared scenario can be shown');
        foreach ($view['points'] as $point) {
            self::assertNull($point['nutrition']);
        }
    }
}
