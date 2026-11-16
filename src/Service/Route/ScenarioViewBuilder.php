<?php

namespace App\Service\Route;

use App\Entity\PacingIntensity;
use App\Entity\PacingStrategy;
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
 *     efforts: list<float>,                // effort des n + 1 tronçons (SplitCalculator::routeEfforts), pour le
 *                                          // calcul en direct des temps auto côté navigateur
 *     maxScenarios: int,                   // 6
 *     canAddScenario: bool,                // count(scenarios) < maxScenarios
 *     strategies: list<PacingStrategy>,    // options du choix de stratégie d'allure (ordre d'affichage)
 *     intensities: list<PacingIntensity>,  // options de l'intensité
 *     suggestedStrategy: PacingStrategy,   // proposée pour un nouveau scénario (PacingStrategy::suggestedFor(type))
 *     suggestedIntensity: PacingIntensity, // moyenne
 *     scenarios: list<array{
 *       scenario: RaceScenario,
 *       id: int,
 *       name: string,
 *       position: int,
 *       startTime: ?string,                // "HH:MM" ou null
 *       target: ?string,                   // temps cible au format de saisie "H:MM" ou "H:MM:SS", ou null
 *       targetSeconds: ?int,
 *       strategy: PacingStrategy,          // stratégie d'allure du scénario (forme des temps auto)
 *       intensity: PacingIntensity,
 *       calc: SplitCalculator::compute() output (voir docblock de SplitCalculator::compute), calculé sur
 *             les temps remplis (fixés + auto, SplitCalculator::fillAuto avec la stratégie) ; chaque ligne a
 *             "pinned" et "paceDeviationPct",
 *       pinned: array<int, bool>,          // checkpointId => temps fixé (true) ou auto (false), pour chaque repère
 *       pinnedCount: int,                  // nombre de temps fixés
 *       inputs: array<int, string>,        // checkpointId => temps FIXÉ au format de saisie ("H:MM" ou "H:MM:SS"),
 *                                          // uniquement pour les repères fixés
 *       autos: array<int, string>,         // checkpointId => temps AUTO calculé, même format, pour les repères auto
 *                                          // qui ont un temps (absent = auto sans temps : pas de cible après le
 *                                          // dernier temps fixé)
 *     }>,
 *   }
 *
 * Usage dans RacePlanController::plan() (route et repères déjà chargés) :
 *   $scenarioView = $builder->buildWith($race, $route, $checkpoints);
 *
 * @phpstan-import-type CheckpointInput from SplitCalculator
 * @phpstan-import-type Result from SplitCalculator
 *
 * @phpstan-type ScenarioView array{scenario: RaceScenario, id: int, name: string, position: int, startTime: ?string, target: ?string, targetSeconds: ?int, strategy: PacingStrategy, intensity: PacingIntensity, calc: Result, pinned: array<int, bool>, pinnedCount: int, inputs: array<int, string>, autos: array<int, string>}
 * @phpstan-type View array{route: ?RaceRoute, checkpoints: list<RaceCheckpoint>, routeDistanceM: int, efforts: list<float>, maxScenarios: int, canAddScenario: bool, strategies: list<PacingStrategy>, intensities: list<PacingIntensity>, suggestedStrategy: PacingStrategy, suggestedIntensity: PacingIntensity, scenarios: list<ScenarioView>}
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
        $efforts = $this->calculator->routeEfforts($points, $distanceM, $inputs);

        $scenarios = $this->scenarios->findByRaceOrdered($race);
        $splitsByScenario = $this->splits->findForScenarios($scenarios);

        $views = [];
        foreach ($scenarios as $scenario) {
            $id = (int) $scenario->getId();
            $target = $scenario->getTargetSeconds();
            $strategy = $scenario->getStrategy();
            $intensity = $scenario->getStrategyIntensity();
            // Temps enregistrés = temps fixés ; les autres repères sont calculés (auto), selon la stratégie d'allure
            $filled = $this->calculator->fillAutoFromEfforts($inputs, $distanceM, $efforts, $splitsByScenario[$id] ?? [], $target, $strategy, $intensity);
            $pinnedInputs = [];
            $autoInputs = [];
            foreach ($filled['arrivals'] as $cpId => $seconds) {
                if (null === $seconds) {
                    continue;
                }
                if ($filled['pinned'][$cpId]) {
                    $pinnedInputs[$cpId] = DurationFormat::formatInput($seconds);
                } else {
                    $autoInputs[$cpId] = DurationFormat::formatInput($seconds);
                }
            }

            $views[] = [
                'scenario' => $scenario,
                'id' => $id,
                'name' => $scenario->getName(),
                'position' => $scenario->getPosition(),
                'startTime' => $scenario->getStartTime()?->format('H:i'),
                'target' => null !== $target ? DurationFormat::formatInput($target) : null,
                'targetSeconds' => $target,
                'strategy' => $strategy,
                'intensity' => $intensity,
                'calc' => $this->calculator->compute($points, $distanceM, $inputs, $filled['arrivals'], $target, $scenario->getStartTime(), pinned: $filled['pinned']),
                'pinned' => $filled['pinned'],
                'pinnedCount' => \count($pinnedInputs),
                'inputs' => $pinnedInputs,
                'autos' => $autoInputs,
            ];
        }

        return [
            'route' => $route,
            'checkpoints' => $checkpoints,
            'routeDistanceM' => $distanceM,
            'efforts' => $efforts,
            'maxScenarios' => self::MAX_SCENARIOS,
            'canAddScenario' => \count($views) < self::MAX_SCENARIOS,
            'strategies' => PacingStrategy::cases(),
            'intensities' => PacingIntensity::cases(),
            'suggestedStrategy' => PacingStrategy::suggestedFor($race->getType()),
            'suggestedIntensity' => PacingIntensity::Medium,
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
