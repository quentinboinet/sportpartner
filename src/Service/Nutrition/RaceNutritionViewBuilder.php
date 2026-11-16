<?php

namespace App\Service\Nutrition;

use App\Entity\Race;
use App\Entity\RaceCheckpoint;
use App\Entity\RaceNutritionItem;
use App\Entity\RaceNutritionPlan;
use App\Repository\ActivityFuelRepository;
use App\Repository\RaceNutritionItemRepository;
use App\Repository\RaceNutritionPlanRepository;
use App\Service\Route\ScenarioViewBuilder;

/**
 * Données du plan nutrition de chaque scénario d'une course (section Nutrition de la page plan, fiche imprimable,
 * bracelet, page partagée). Part de ScenarioViewBuilder::buildWith() : les durées des tronçons sont celles des temps
 * de passage ENREGISTRÉS du scénario (fixés + auto), arrêts exclus.
 *
 * Forme d'un plan (build() renvoie scenarioId => plan) :
 *   array{
 *     scenarioId: int,
 *     hasPlan: bool,                 // réglages enregistrés (sinon : défauts du type de course)
 *     settings: Settings,            // RaceNutritionCatalog::defaultSettings() / settingsOf()
 *     products: list<Product>,       // RaceNutritionCatalog::products() : catalogue + personnalisés
 *     input: Input,                  // entrée de RaceNutritionCalculator::compute() (aussi transmise au JS)
 *     result: Result,                // RaceNutritionCalculator::compute()
 *     carry: list<Stop>,             // RaceNutritionCalculator::carryList()
 *     manualCount: int,              // lignes manuelles affichées
 *   }
 * Durées : tronçon i = arrivée au repère i - départ du repère précédent (arrivée + arrêt du ravito), 0 au minimum ;
 * null dès qu'un temps manque (scénario sans temps visé) : pas de calcul auto.
 *
 * @phpstan-import-type View from ScenarioViewBuilder
 * @phpstan-import-type ScenarioView from ScenarioViewBuilder
 * @phpstan-import-type Product from RaceNutritionCatalog
 * @phpstan-import-type Settings from RaceNutritionCatalog
 * @phpstan-import-type Input from RaceNutritionCalculator
 * @phpstan-import-type Result from RaceNutritionCalculator
 * @phpstan-import-type Stop from RaceNutritionCalculator
 * @phpstan-import-type Line from RaceNutritionCalculator
 *
 * @phpstan-type NutritionView array{scenarioId: int, hasPlan: bool, settings: Settings, products: list<Product>, input: Input, result: Result, carry: list<Stop>, manualCount: int}
 */
final class RaceNutritionViewBuilder
{
    public function __construct(
        private readonly RaceNutritionPlanRepository $plans,
        private readonly RaceNutritionItemRepository $items,
        private readonly ActivityFuelRepository $fuel,
        private readonly RaceNutritionCalculator $calculator,
    ) {
    }

    /**
     * @param View $view ScenarioViewBuilder::buildWith() de la course
     *
     * @return array<int, NutritionView> scenarioId => plan
     */
    public function build(Race $race, array $view): array
    {
        $scenarios = array_map(static fn (array $s) => $s['scenario'], $view['scenarios']);
        $plans = $this->plans->findForScenarios($scenarios);
        $rowsByPlan = $this->items->findForPlans(array_values($plans));
        $remembered = $this->fuel->findLatestCarbsByItemKey($race->getUser(), FuelCatalog::builtInKeys());

        $out = [];
        foreach ($view['scenarios'] as $scenario) {
            $plan = $plans[$scenario['id']] ?? null;
            $rows = null !== $plan ? ($rowsByPlan[(int) $plan->getId()] ?? []) : [];
            $out[$scenario['id']] = $this->buildOne($race, $view['checkpoints'], $scenario, $plan, $rows, $remembered);
        }

        return $out;
    }

    /**
     * Plan d'un scénario (calcul pur à partir des données déjà chargées).
     *
     * @param list<RaceCheckpoint>    $checkpoints repères du tableau (ScenarioViewBuilder::buildWith()['checkpoints'])
     * @param ScenarioView            $scenario
     * @param list<RaceNutritionItem> $rows        lignes manuelles du plan
     * @param array<string, float>    $remembered  glucides retenus pour l'utilisateur (ActivityFuelRepository)
     *
     * @return NutritionView
     */
    public function buildOne(Race $race, array $checkpoints, array $scenario, ?RaceNutritionPlan $plan, array $rows, array $remembered): array
    {
        $settings = null !== $plan ? RaceNutritionCatalog::settingsOf($plan) : RaceNutritionCatalog::defaultSettings($race->getType());
        $products = RaceNutritionCatalog::products(null !== $plan ? $plan->getProducts() : [], $remembered);
        $input = self::input($settings, $products, $checkpoints, $scenario, self::manualMap($rows, $checkpoints));
        $result = $this->calculator->compute($input);

        return [
            'scenarioId' => $scenario['id'],
            'hasPlan' => null !== $plan,
            'settings' => $settings,
            'products' => $products,
            'input' => $input,
            'result' => $result,
            'carry' => $this->calculator->carryList($input, $result, $settings['flaskMl']),
            'manualCount' => \count($input['manual']),
        ];
    }

    /**
     * Entrée du calcul.
     *
     * @param Settings                  $settings
     * @param list<Product>             $products
     * @param list<RaceCheckpoint>      $checkpoints
     * @param ScenarioView              $scenario
     * @param array<string, list<Line>> $manual
     *
     * @return Input
     */
    public static function input(array $settings, array $products, array $checkpoints, array $scenario, array $manual): array
    {
        $points = [['anchor' => 'start', 'type' => RaceNutritionCalculator::TYPE_START, 'assistance' => false]];
        foreach ($checkpoints as $cp) {
            $points[] = ['anchor' => (string) $cp->getId(), 'type' => $cp->getType()->value, 'assistance' => $cp->hasAssistance()];
        }

        return [
            'settings' => [
                'carbsPerHour' => $settings['carbsPerHour'],
                'fluidPerHour' => $settings['fluidPerHour'],
                'mixDrink' => $settings['mixDrink'],
                'mixGel' => $settings['mixGel'],
                'mixSolid' => $settings['mixSolid'],
                'solidEarly' => $settings['solidEarly'],
                'gel' => $settings['gel'],
                'solid' => $settings['solid'],
                'drink' => $settings['drink'],
            ],
            'products' => RaceNutritionCatalog::calculatorProducts($products),
            'points' => $points,
            'segments' => self::segments($scenario),
            'manual' => $manual,
        ];
    }

    /**
     * Durées en mouvement des n + 1 tronçons d'un scénario, d'après SplitCalculator::compute() (temps enregistrés).
     *
     * @param ScenarioView $scenario
     *
     * @return list<array{anchor: string, seconds: ?int}>
     */
    public static function segments(array $scenario): array
    {
        $segments = [];
        $departure = 0;
        $known = true;
        foreach ($scenario['calc']['rows'] as $row) {
            $arrival = $row['arrivalSeconds'];
            $seconds = $known && null !== $arrival ? max(0, $arrival - $departure) : null;
            $known = $known && null !== $arrival && null !== $row['departureSeconds'];
            $departure = $row['departureSeconds'] ?? $departure;
            $segments[] = ['anchor' => (string) $row['checkpointId'], 'seconds' => $seconds];
        }
        $target = $scenario['targetSeconds'];
        $segments[] = ['anchor' => 'finish', 'seconds' => $known && null !== $target ? max(0, $target - $departure) : null];

        return $segments;
    }

    /**
     * Lignes manuelles affichées : départ, arrivée et repères du tableau (celles d'un repère au-delà du tracé sont
     * gardées en base mais ignorées).
     *
     * @param list<RaceNutritionItem> $rows
     * @param list<RaceCheckpoint>    $checkpoints
     *
     * @return array<string, list<Line>>
     */
    public static function manualMap(array $rows, array $checkpoints): array
    {
        $inTable = [];
        foreach ($checkpoints as $cp) {
            $inTable[(int) $cp->getId()] = true;
        }
        $manual = [];
        foreach ($rows as $row) {
            $id = $row->getCheckpoint()?->getId();
            if (null === $id || isset($inTable[$id])) {
                $manual[$row->rowKey()] = array_values(array_map(
                    static fn (array $line): array => ['key' => (string) $line['key'], 'qty' => (int) $line['qty']],
                    $row->getItems(),
                ));
            }
        }

        return $manual;
    }
}
