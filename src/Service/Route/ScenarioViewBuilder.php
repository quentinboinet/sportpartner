<?php

namespace App\Service\Route;

use App\Entity\Race;
use App\Entity\RaceCheckpoint;
use App\Entity\RaceRoute;
use App\Entity\RaceScenario;
use App\Repository\RaceCheckpointRepository;
use App\Repository\RaceRouteRepository;
use App\Repository\RaceScenarioRepository;
use App\Repository\RaceScenarioSplitRepository;

/**
 * Prépare tout ce qu'il faut pour afficher les scénarios d'une course (page plan, page impression).
 *
 * Forme renvoyée par build() / buildWith() :
 *
 *   array{
 *     route: ?RaceRoute,
 *     checkpoints: list<RaceCheckpoint>,   // repères du parcours triés par distance, SANS ceux au-delà de
 *                                          // la fin du tracé : ce sont les lignes du tableau de temps
 *     routeDistanceM: int,                 // longueur du tracé, sinon distance de la course (km * 1000), sinon 0
 *     maxScenarios: int,                   // 6
 *     canAddScenario: bool,                // count(scenarios) < maxScenarios
 *     scenarios: list<array{
 *       scenario: RaceScenario,
 *       id: int,
 *       name: string,
 *       position: int,
 *       startTime: ?string,                // "HH:MM" ou null
 *       target: ?string,                   // temps cible au format de saisie "H:MM" ou "H:MM:SS", ou null
 *       targetSeconds: ?int,
 *       calc: SplitCalculator::compute() output (voir docblock de SplitCalculator::compute),
 *       inputs: array<int, string>,        // checkpointId => arrivée au format de saisie ("H:MM" ou "H:MM:SS"),
 *                                          // uniquement pour les repères de "checkpoints" qui ont un temps
 *     }>,
 *   }
 *
 * Usage dans RacePlanController::plan() (route et repères déjà chargés) :
 *   $scenarioView = $builder->buildWith($race, $route, $checkpoints);
 *
 * @phpstan-import-type CheckpointInput from SplitCalculator
 * @phpstan-import-type Result from SplitCalculator
 *
 * @phpstan-type ScenarioView array{scenario: RaceScenario, id: int, name: string, position: int, startTime: ?string, target: ?string, targetSeconds: ?int, calc: Result, inputs: array<int, string>}
 * @phpstan-type View array{route: ?RaceRoute, checkpoints: list<RaceCheckpoint>, routeDistanceM: int, maxScenarios: int, canAddScenario: bool, scenarios: list<ScenarioView>}
 */
final class ScenarioViewBuilder
{
    public const MAX_SCENARIOS = 6;

    public function __construct(
        private readonly RaceRouteRepository $routes,
        private readonly RaceCheckpointRepository $checkpoints,
        private readonly RaceScenarioRepository $scenarios,
        private readonly RaceScenarioSplitRepository $splits,
        private readonly SplitCalculator $calculator,
    ) {
    }

    /** @return View */
    public function build(Race $race): array
    {
        return $this->buildWith($race, $this->routes->findOneByRace($race), $this->checkpoints->findByRaceOrdered($race));
    }

    /**
     * @param list<RaceCheckpoint> $allCheckpoints tous les repères de la course (filtrés ici)
     *
     * @return View
     */
    public function buildWith(Race $race, ?RaceRoute $route, array $allCheckpoints): array
    {
        $checkpoints = self::routeCheckpoints($route, $allCheckpoints);
        $inputs = self::calculatorCheckpoints($checkpoints);
        $points = $route?->getPoints() ?? [];
        $distanceM = self::routeDistanceM($race, $route);

        $scenarios = $this->scenarios->findByRaceOrdered($race);
        $splitsByScenario = $this->splits->findForScenarios($scenarios);

        $views = [];
        foreach ($scenarios as $scenario) {
            $id = (int) $scenario->getId();
            $arrivals = [];
            $formatted = [];
            foreach ($checkpoints as $cp) {
                $cpId = (int) $cp->getId();
                if (isset($splitsByScenario[$id][$cpId])) {
                    $arrivals[$cpId] = $splitsByScenario[$id][$cpId];
                    $formatted[$cpId] = DurationFormat::formatInput($arrivals[$cpId]);
                }
            }
            $target = $scenario->getTargetSeconds();

            $views[] = [
                'scenario' => $scenario,
                'id' => $id,
                'name' => $scenario->getName(),
                'position' => $scenario->getPosition(),
                'startTime' => $scenario->getStartTime()?->format('H:i'),
                'target' => null !== $target ? DurationFormat::formatInput($target) : null,
                'targetSeconds' => $target,
                'calc' => $this->calculator->compute($points, $distanceM, $inputs, $arrivals, $target, $scenario->getStartTime()),
                'inputs' => $formatted,
            ];
        }

        return [
            'route' => $route,
            'checkpoints' => $checkpoints,
            'routeDistanceM' => $distanceM,
            'maxScenarios' => self::MAX_SCENARIOS,
            'canAddScenario' => \count($views) < self::MAX_SCENARIOS,
            'scenarios' => $views,
        ];
    }

    /**
     * Repères pris en compte dans les scénarios : triés par distance, sans ceux au-delà de la fin
     * du tracé (parcours remplacé par un plus court). Sans tracé : tous.
     *
     * @param list<RaceCheckpoint> $checkpoints
     *
     * @return list<RaceCheckpoint>
     */
    public static function routeCheckpoints(?RaceRoute $route, array $checkpoints): array
    {
        $end = $route?->getDistanceM();
        $kept = array_values(array_filter($checkpoints, static fn (RaceCheckpoint $c): bool => null === $end || $c->getDistanceM() <= $end));
        usort($kept, static fn (RaceCheckpoint $a, RaceCheckpoint $b): int => [$a->getDistanceM(), $a->getId()] <=> [$b->getDistanceM(), $b->getId()]);

        return $kept;
    }

    /**
     * Repères au format attendu par SplitCalculator.
     *
     * @param list<RaceCheckpoint> $checkpoints
     *
     * @return list<CheckpointInput>
     */
    public static function calculatorCheckpoints(array $checkpoints): array
    {
        return array_map(static fn (RaceCheckpoint $c): array => [
            'id' => (int) $c->getId(),
            'name' => $c->getName(),
            'type' => $c->getType()->value,
            'distanceM' => $c->getDistanceM(),
            'stopSeconds' => $c->getStopSeconds(),
            'cutoffSeconds' => $c->getCutoffSeconds(),
        ], $checkpoints);
    }

    /** Distance de l'arrivée : tracé GPX, sinon distance de la fiche course, sinon 0. */
    public static function routeDistanceM(Race $race, ?RaceRoute $route): int
    {
        if (null !== $route) {
            return $route->getDistanceM();
        }
        $km = $race->getDistanceKm();

        return null !== $km ? (int) round($km * 1000) : 0;
    }
}
