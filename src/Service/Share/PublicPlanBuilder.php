<?php

namespace App\Service\Share;

use App\Entity\Race;
use App\Entity\RaceCheckpoint;
use App\Entity\RaceRoute;
use App\Entity\RaceShare;
use App\Service\Nutrition\FuelCatalog;
use App\Service\Nutrition\RaceNutritionViewBuilder;
use App\Service\Route\DurationFormat;
use App\Service\Route\RouteAnalyzer;
use App\Service\Route\ScenarioViewBuilder;

/**
 * Données de la page publique d'un plan de course (/p/{token}, RacePublicController) et de son fichier .ics.
 * Calcul pur, sans base de données : le contrôleur fournit la course, le partage, le tracé, les repères du parcours et
 * les scénarios déjà calculés (ScenarioViewBuilder::buildWith()).
 *
 * LISTE BLANCHE : le résultat ne contient que des valeurs scalaires recopiées une à une (jamais d'entité), et seulement
 * celles-ci : nom, date, année et lieu de la course ; nom affiché, message général et messages publics (départ,
 * repères, arrivée) du partage ; heure de départ, heures / temps prévus, allures (option showPaces) et barrières (option
 * showCutoffs) du SEUL scénario partagé ; distance, D+ et aperçu du tracé (points + repères numérotés). Jamais : e-mail
 * ou identifiant de l'utilisateur, notes privées (course, repères), autres scénarios, nom ou stratégie du scénario,
 * lien ou nom du fichier GPX, site, statut, données Strava, jeton du partage.
 *
 * Nutrition (option showNutrition, désactivée par défaut) : seulement pour le scénario partagé et seulement aux points
 * d'ASSISTANCE, ce que les proches doivent donner (liste « à emporter » de RaceNutritionCalculator::carryList() :
 * aliments à récupérer, boissons prises sur place) et les flasques à remplir ; chaque ligne n'a que la clé d'un produit
 * du catalogue OU le nom d'un produit personnalisé, une quantité et le type (boisson ou non). Option désactivée :
 * "nutrition" vaut null partout, aucune donnée du plan nutrition n'est lue.
 *
 * Horaires : "scheduled" seulement si le scénario partagé a un temps visé (sinon : "Horaires pas encore définis").
 * Les heures sont celles de SplitCalculator::compute() (mêmes valeurs que la page plan pour ce scénario) ; "at" est
 * l'heure locale "flottante" (sans fuseau, la course n'en a pas) du passage, "Y-m-d\TH:i:s", pour la mise en avant
 * du prochain passage le jour de la course (navigateur) et pour l'agenda.
 *
 * @phpstan-import-type ScenarioView from ScenarioViewBuilder
 * @phpstan-import-type NutritionView from RaceNutritionViewBuilder
 *
 * @phpstan-type Clock array{time: string, dayOffset: int}
 * @phpstan-type PublicLine array{key: ?string, label: ?string, qty: int, drink: bool}
 * @phpstan-type PublicNutrition array{give: list<PublicLine>, fill: list<array{key: ?string, label: ?string, ml: int, flasks: int}>, flasks: int, flaskMl: int}
 * @phpstan-type PublicPoint array{kind: string, number: ?int, name: ?string, type: ?string, distanceM: int, elapsedSeconds: ?int, clock: ?Clock, at: ?string, paceSecPerKm: ?float, cutoffSeconds: ?int, cutoffClock: ?Clock, message: ?string, nutrition: ?PublicNutrition}
 * @phpstan-type PublicMap array{points: list<array{0: int, 1: float|null, 2: float, 3: float}>, checkpoints: list<array{number: int, name: string, type: string, distanceM: int, lat: float, lon: float}>}
 * @phpstan-type PublicView array{race: array{name: string, date: ?string, year: int, location: ?string}, displayName: ?string, message: ?string, showPaces: bool, showCutoffs: bool, showNutrition: bool, scheduled: bool, startTime: ?string, finishClock: ?Clock, finishElapsed: ?int, finishAt: ?string, avgPaceSecPerKm: ?float, distanceM: int, gainM: ?int, hasCutoffs: bool, points: list<PublicPoint>, map: ?PublicMap}
 */
final class PublicPlanBuilder
{
    /** Points du tracé envoyés à la carte (aperçu allégé, comme la page de la course). */
    public const MAX_MAP_POINTS = 600;

    public const KIND_START = 'start';
    public const KIND_CHECKPOINT = 'checkpoint';
    public const KIND_FINISH = 'finish';

    public function __construct(private readonly RouteAnalyzer $analyzer = new RouteAnalyzer())
    {
    }

    /**
     * Scénario partagé : celui choisi s'il existe encore, sinon le premier par position (supprimé : la clé étrangère
     * est passée à NULL), sinon aucun.
     *
     * @param list<ScenarioView> $scenarios ScenarioViewBuilder::buildWith()['scenarios'] (triés par position)
     *
     * @return ScenarioView|null
     */
    public static function selectScenario(array $scenarios, ?int $sharedScenarioId): ?array
    {
        foreach ($scenarios as $view) {
            if (null !== $sharedScenarioId && $view['id'] === $sharedScenarioId) {
                return $view;
            }
        }

        return $scenarios[0] ?? null;
    }

    /**
     * @param list<RaceCheckpoint> $checkpoints    repères du parcours, triés (ScenarioViewBuilder::buildWith()['checkpoints'])
     * @param int                  $routeDistanceM ScenarioViewBuilder::routeDistanceM()
     * @param ScenarioView|null    $scenario       scénario partagé (selectScenario())
     * @param NutritionView|null   $nutrition      plan nutrition du scénario partagé (RaceNutritionViewBuilder), lu
     *                                             seulement si l'option showNutrition est active
     *
     * @return PublicView
     */
    public function build(Race $race, RaceShare $share, ?RaceRoute $route, array $checkpoints, int $routeDistanceM, ?array $scenario, ?array $nutrition = null): array
    {
        $showPaces = $share->isShowPaces();
        $showCutoffs = $share->isShowCutoffs();
        $handover = $share->isShowNutrition() && null !== $nutrition && null !== $scenario && $nutrition['scenarioId'] === $scenario['id']
            ? self::handover($nutrition) : null;
        $calc = null !== $scenario ? $scenario['calc'] : null;
        $target = null !== $scenario ? $scenario['targetSeconds'] : null;
        $scheduled = null !== $calc && null !== $target;
        $startTime = null !== $scenario ? $scenario['startTime'] : null;
        $startClock = null !== $scenario ? $scenario['scenario']->getStartTime() : null;
        $raceDate = $race->getRaceDate();

        $rowsById = [];
        foreach (null !== $calc ? $calc['rows'] : [] as $row) {
            if (null !== $row['checkpointId']) {
                $rowsById[$row['checkpointId']] = $row;
            }
        }

        $points = [[
            'kind' => self::KIND_START,
            'number' => null,
            'name' => null,
            'type' => null,
            'distanceM' => 0,
            'elapsedSeconds' => $scheduled ? 0 : null,
            'clock' => null !== $startTime ? ['time' => $startTime, 'dayOffset' => 0] : null,
            'at' => null !== $startTime ? self::at($raceDate, ['time' => $startTime, 'dayOffset' => 0]) : null,
            'paceSecPerKm' => null,
            'cutoffSeconds' => null,
            'cutoffClock' => null,
            'message' => self::text($share->getStartMessage()),
            'nutrition' => null,
        ]];

        $hasCutoffs = false;
        foreach (array_values($checkpoints) as $i => $cp) {
            $row = $rowsById[(int) $cp->getId()] ?? null;
            $timed = $scheduled && null !== $row && null !== $row['arrivalSeconds'];
            $clock = $timed ? $row['clock'] : null;
            $cutoff = $showCutoffs ? $cp->getCutoffSeconds() : null;
            $hasCutoffs = $hasCutoffs || null !== $cutoff;
            $points[] = [
                'kind' => self::KIND_CHECKPOINT,
                'number' => $i + 1,
                'name' => $cp->getName(),
                'type' => $cp->getType()->value,
                'distanceM' => $cp->getDistanceM(),
                'elapsedSeconds' => $timed ? $row['arrivalSeconds'] : null,
                'clock' => $clock,
                'at' => null !== $clock ? self::at($raceDate, $clock) : null,
                'paceSecPerKm' => $timed && $showPaces ? $row['segmentPaceSecPerKm'] : null,
                'cutoffSeconds' => $cutoff,
                'cutoffClock' => null !== $cutoff && null !== $startClock ? DurationFormat::formatClock($startClock, $cutoff) : null,
                'message' => self::text($cp->getPublicMessage()),
                'nutrition' => $handover[(string) $cp->getId()] ?? null,
            ];
        }

        $finish = null !== $calc ? $calc['finish'] : null;
        $lastDistance = [] === $checkpoints ? 0 : max(array_map(static fn (RaceCheckpoint $c): int => $c->getDistanceM(), $checkpoints));
        $finishDistance = null !== $finish ? $finish['distanceM'] : max(0, $routeDistanceM, $lastDistance);
        $finishClock = $scheduled && null !== $finish ? $finish['clock'] : null;
        $points[] = [
            'kind' => self::KIND_FINISH,
            'number' => null,
            'name' => null,
            'type' => null,
            'distanceM' => $finishDistance,
            'elapsedSeconds' => $scheduled ? $target : null,
            'clock' => $finishClock,
            'at' => null !== $finishClock ? self::at($raceDate, $finishClock) : null,
            'paceSecPerKm' => $scheduled && $showPaces && null !== $finish ? $finish['segmentPaceSecPerKm'] : null,
            'cutoffSeconds' => null,
            'cutoffClock' => null,
            'message' => self::text($share->getFinishMessage()),
            'nutrition' => null,
        ];

        return [
            'race' => [
                'name' => $race->getName(),
                'date' => $raceDate?->format('Y-m-d'),
                'year' => $race->getYear(),
                'location' => self::text($race->getLocation()),
            ],
            'displayName' => self::text($share->getDisplayName()),
            'message' => self::text($share->getMessage()),
            'showPaces' => $showPaces,
            'showCutoffs' => $showCutoffs,
            'showNutrition' => null !== $handover,
            'scheduled' => $scheduled,
            'startTime' => $startTime,
            'finishClock' => $finishClock,
            'finishElapsed' => $scheduled ? $target : null,
            'finishAt' => null !== $finishClock ? self::at($raceDate, $finishClock) : null,
            'avgPaceSecPerKm' => $scheduled && $showPaces && null !== $calc ? $calc['totals']['avgPaceSecPerKm'] : null,
            'distanceM' => $finishDistance,
            'gainM' => $race->getElevationGain() ?? $route?->getGainM(),
            'hasCutoffs' => $hasCutoffs,
            'points' => $points,
            'map' => null !== $route ? $this->map($route, $checkpoints) : null,
        ];
    }

    /**
     * Ce que les proches donnent à chaque point d'assistance (liste blanche) : aliments à récupérer (carry), boissons
     * prises sur place (eatHere) et flasques à remplir (fill). Clé du catalogue, ou nom d'un produit personnalisé.
     *
     * @param NutritionView $nutrition
     *
     * @return array<string, PublicNutrition> checkpointId => données (points d'assistance qui ont quelque chose)
     */
    private static function handover(array $nutrition): array
    {
        $products = [];
        foreach ($nutrition['products'] as $product) {
            $products[$product['key']] = $product;
        }
        $name = static function (string $key) use ($products): array {
            $custom = FuelCatalog::isCustomKey($key);

            return [
                'key' => $custom ? null : (string) $key,
                'label' => $custom ? (string) ($products[$key]['label'] ?? '') : null,
                'drink' => 'drink' === ($products[$key]['kind'] ?? 'food'),
            ];
        };

        $out = [];
        foreach ($nutrition['carry'] as $stop) {
            if ('start' === $stop['type'] || !$stop['assistance']) {
                continue;
            }
            $give = [];
            foreach ($stop['carry'] ?? [] as $line) {
                $n = $name($line['key']);
                $give[] = ['key' => $n['key'], 'label' => $n['label'], 'qty' => (int) $line['qty'], 'drink' => $n['drink']];
            }
            foreach ($stop['eatHere'] as $line) {
                $n = $name($line['key']);
                if ($n['drink']) {
                    $give[] = ['key' => $n['key'], 'label' => $n['label'], 'qty' => (int) $line['qty'], 'drink' => true];
                }
            }
            $fill = [];
            foreach ($stop['fill'] ?? [] as $line) {
                $n = $name($line['key']);
                $fill[] = ['key' => $n['key'], 'label' => $n['label'], 'ml' => (int) $line['ml'], 'flasks' => (int) $line['flasks']];
            }
            if ([] !== $give || [] !== $fill) {
                $out[(string) $stop['anchor']] = ['give' => $give, 'fill' => $fill, 'flasks' => (int) $stop['flasks'], 'flaskMl' => (int) $nutrition['settings']['flaskMl']];
            }
        }

        return $out;
    }

    /**
     * Aperçu du tracé pour la carte et le profil (Stimulus route-preview) : au plus MAX_MAP_POINTS points
     * [distanceM, ele, lat, lon] (le dernier toujours gardé) et les repères numérotés comme la frise.
     *
     * @param list<RaceCheckpoint> $checkpoints
     *
     * @return PublicMap|null null si le tracé a moins de deux points
     */
    private function map(RaceRoute $route, array $checkpoints): ?array
    {
        $points = $route->getPoints();
        if (\count($points) < 2) {
            return null;
        }

        $step = max(1, (int) ceil(\count($points) / self::MAX_MAP_POINTS));
        $preview = [];
        foreach ($points as $i => $p) {
            if (0 === $i % $step) {
                $preview[] = [(int) $p[0], null !== $p[1] ? (float) $p[1] : null, (float) $p[2], (float) $p[3]];
            }
        }
        $last = $points[\count($points) - 1];
        if (0 !== (\count($points) - 1) % $step) {
            $preview[] = [(int) $last[0], null !== $last[1] ? (float) $last[1] : null, (float) $last[2], (float) $last[3]];
        }

        $marks = [];
        foreach (array_values($checkpoints) as $i => $cp) {
            $position = $this->analyzer->positionAt($points, $cp->getDistanceM());
            if (null === $position) {
                continue;
            }
            $marks[] = [
                'number' => $i + 1,
                'name' => $cp->getName(),
                'type' => $cp->getType()->value,
                'distanceM' => $cp->getDistanceM(),
                'lat' => $position[0],
                'lon' => $position[1],
            ];
        }

        return ['points' => $preview, 'checkpoints' => $marks];
    }

    /**
     * Heure locale flottante d'un passage : date de la course + n jours, heure de l'horloge (secondes à 0).
     * Calcul en UTC pour ne subir aucun changement d'heure.
     *
     * @param Clock $clock
     */
    private static function at(?\DateTimeImmutable $raceDate, array $clock): ?string
    {
        if (null === $raceDate) {
            return null;
        }
        $day = new \DateTimeImmutable($raceDate->format('Y-m-d'), new \DateTimeZone('UTC'));
        if ($clock['dayOffset'] > 0) {
            $day = $day->modify(sprintf('+%d days', $clock['dayOffset']));
        }

        return $day->format('Y-m-d').'T'.$clock['time'].':00';
    }

    private static function text(?string $value): ?string
    {
        $value = null !== $value ? trim($value) : null;

        return null === $value || '' === $value ? null : $value;
    }
}
