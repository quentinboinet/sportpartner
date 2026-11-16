<?php

namespace App\Tests\Unit\Service\Share;

use App\Entity\RaceCheckpoint;
use App\Service\Share\PublicPlanBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Données de la page publique (/p/{token}) : liste blanche stricte, horaires identiques à la page plan pour le
 * scénario partagé, options, repli sur le premier scénario, plan sans horaires.
 */
final class PublicPlanBuilderTest extends TestCase
{
    use ShareFixtures;

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: list<RaceCheckpoint>}
     */
    private function buildDefault(bool $paces = true, bool $cutoffs = true, ?int $sharedScenarioId = 11): array
    {
        $race = self::race();
        $route = self::route($race);
        $cps = self::checkpoints($race);
        $cps[0]->setPublicMessage("Maillot rouge\ncôté gauche");
        $cps[2]->setPublicMessage('   ');
        $share = self::share($race)->setShowPaces($paces)->setShowCutoffs($cutoffs)
            ->setMessage('Merci de venir !')->setStartMessage('Sas 3')->setFinishMessage('Tente finishers');
        $scenarios = [
            self::scenario($race, 11, self::SENTINELS['scenarioName'], '08:00', 4 * 3600 + 30 * 60, 0),
            self::scenario($race, 12, self::SENTINELS['otherScenario'], '21:30', 5 * 3600 + 10 * 60, 1),
        ];
        $sv = $this->scenarioView($race, $route, $cps, $scenarios, [12 => [101 => 9999]]);
        $selected = PublicPlanBuilder::selectScenario($sv['scenarios'], $sharedScenarioId);
        $view = (new PublicPlanBuilder())->build($race, $share, $route, $sv['checkpoints'], $sv['routeDistanceM'], $selected);

        return [$view, $selected, $sv['checkpoints']];
    }

    #[Test]
    public function privateDataNeverAppears(): void
    {
        foreach ([[true, true], [false, false]] as [$paces, $cutoffs]) {
            [$view] = $this->buildDefault($paces, $cutoffs);
            $json = json_encode($view, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
            foreach (self::SENTINELS as $what => $sentinel) {
                self::assertStringNotContainsString($sentinel, $json, $what.' leaked');
            }
            self::assertStringNotContainsString((string) self::USER_ID, $json, 'user id leaked');
            self::assertStringNotContainsString('4242', $json, 'Strava athlete id leaked');
            // Aucun objet : seulement des scalaires, des tableaux et null
            array_walk_recursive($view, static function (mixed $value): void {
                self::assertTrue(null === $value || \is_scalar($value), 'non scalar value '.get_debug_type($value));
            });
        }
    }

    #[Test]
    public function viewContainsOnlyWhitelistedKeys(): void
    {
        [$view] = $this->buildDefault();

        self::assertSame(['race', 'displayName', 'message', 'showPaces', 'showCutoffs', 'showNutrition', 'scheduled', 'startTime', 'finishClock', 'finishElapsed', 'finishAt', 'avgPaceSecPerKm', 'distanceM', 'gainM', 'hasCutoffs', 'points', 'map'], array_keys($view));
        self::assertSame(['name', 'date', 'year', 'location'], array_keys($view['race']));
        foreach ($view['points'] as $point) {
            self::assertSame(['kind', 'number', 'name', 'type', 'distanceM', 'elapsedSeconds', 'clock', 'at', 'paceSecPerKm', 'cutoffSeconds', 'cutoffClock', 'message', 'nutrition'], array_keys($point));
            self::assertNull($point['nutrition'], 'nutrition option is off by default');
        }
        self::assertFalse($view['showNutrition']);
        self::assertSame(['points', 'checkpoints'], array_keys($view['map']));
        foreach ($view['map']['checkpoints'] as $mark) {
            self::assertSame(['number', 'name', 'type', 'distanceM', 'lat', 'lon'], array_keys($mark));
        }
    }

    #[Test]
    public function timesAreThoseOfTheSharedScenario(): void
    {
        [$view, $selected] = $this->buildDefault();

        self::assertSame(11, $selected['id']);
        self::assertTrue($view['scheduled']);
        self::assertSame('08:00', $view['startTime']);
        self::assertSame(['time' => '12:30', 'dayOffset' => 0], $view['finishClock']);
        self::assertSame('2027-06-12T12:30:00', $view['finishAt']);
        self::assertCount(6, $view['points']);
        self::assertSame(['start', 'checkpoint', 'checkpoint', 'checkpoint', 'checkpoint', 'finish'], array_column($view['points'], 'kind'));
        self::assertSame('2027-06-12T08:00:00', $view['points'][0]['at']);
        self::assertSame(0, $view['points'][0]['elapsedSeconds']);

        // Mêmes valeurs que la page plan (calc du scénario, ScenarioViewBuilder)
        foreach ($selected['calc']['rows'] as $i => $row) {
            $point = $view['points'][$i + 1];
            self::assertSame($i + 1, $point['number']);
            self::assertSame($row['name'], $point['name']);
            self::assertSame($row['arrivalSeconds'], $point['elapsedSeconds']);
            self::assertSame($row['clock'], $point['clock']);
            self::assertSame('2027-06-12T'.$row['clock']['time'].':00', $point['at']);
            self::assertSame($row['segmentPaceSecPerKm'], $point['paceSecPerKm']);
        }
        self::assertSame(['point', 'aid_station', 'point', 'aid_station'], array_column(\array_slice($view['points'], 1, 4), 'type'));
        self::assertSame(25000, $view['points'][5]['distanceM']);
        self::assertSame(16200, $view['points'][5]['elapsedSeconds']);
    }

    #[Test]
    public function nextDayPassagesCarryTheDayOffsetInClockAndAt(): void
    {
        // Scénario 12 : départ 21:30, 5 h 10 -> arrivée 02:40 le lendemain
        [$view, $selected] = $this->buildDefault(sharedScenarioId: 12);

        self::assertSame(12, $selected['id']);
        self::assertSame(['time' => '02:40', 'dayOffset' => 1], $view['finishClock']);
        self::assertSame('2027-06-13T02:40:00', $view['finishAt']);
        // Temps fixé du scénario 12 au repère 101 : 9999 s -> 00:16 J+1
        self::assertSame(9999, $view['points'][1]['elapsedSeconds']);
        self::assertSame(['time' => '00:16', 'dayOffset' => 1], $view['points'][1]['clock']);
        self::assertSame('2027-06-13T00:16:00', $view['points'][1]['at']);
        // Barrière 3 h après un départ à 21:30 -> 00:30 J+1
        self::assertSame(['time' => '00:30', 'dayOffset' => 1], $view['points'][2]['cutoffClock']);
    }

    #[Test]
    public function optionsHidePacesAndCutoffs(): void
    {
        [$withAll] = $this->buildDefault(true, true);
        self::assertNotNull($withAll['avgPaceSecPerKm']);
        self::assertTrue($withAll['hasCutoffs']);
        self::assertSame(10800, $withAll['points'][2]['cutoffSeconds']);
        self::assertSame(['time' => '11:00', 'dayOffset' => 0], $withAll['points'][2]['cutoffClock']);
        self::assertNotNull($withAll['points'][1]['paceSecPerKm']);

        [$none] = $this->buildDefault(false, false);
        self::assertNull($none['avgPaceSecPerKm']);
        self::assertFalse($none['hasCutoffs']);
        foreach ($none['points'] as $point) {
            self::assertNull($point['paceSecPerKm']);
            self::assertNull($point['cutoffSeconds']);
            self::assertNull($point['cutoffClock']);
        }
        // Les horaires restent affichés
        self::assertSame($withAll['points'][3]['clock'], $none['points'][3]['clock']);
    }

    #[Test]
    public function messagesArePublicOnesOnlyAndBlankIsNull(): void
    {
        [$view] = $this->buildDefault();

        self::assertSame('Merci de venir !', $view['message']);
        self::assertSame('Camille', $view['displayName']);
        self::assertSame('Sas 3', $view['points'][0]['message']);
        self::assertSame("Maillot rouge\ncôté gauche", $view['points'][1]['message']);
        self::assertNull($view['points'][2]['message']);
        self::assertNull($view['points'][3]['message'], 'blank public message is null');
        self::assertSame('Tente finishers', $view['points'][5]['message']);
    }

    #[Test]
    public function selectScenarioFallsBackToTheFirstByPosition(): void
    {
        $race = self::race();
        $sv = $this->scenarioView($race, null, [], [
            self::scenario($race, 21, 'A', '08:00', 3600, 0),
            self::scenario($race, 22, 'B', '09:00', 7200, 1),
        ]);

        self::assertSame(22, PublicPlanBuilder::selectScenario($sv['scenarios'], 22)['id'] ?? null);
        self::assertSame(21, PublicPlanBuilder::selectScenario($sv['scenarios'], null)['id'] ?? null, 'never chosen');
        self::assertSame(21, PublicPlanBuilder::selectScenario($sv['scenarios'], 999)['id'] ?? null, 'deleted');
        self::assertNull(PublicPlanBuilder::selectScenario([], 21));
    }

    #[Test]
    public function withoutTargetOrScenarioTimesAreNotDefined(): void
    {
        $race = self::race();
        $cps = self::checkpoints($race);
        $share = self::share($race);

        // Scénario sans temps visé : l'heure de départ reste affichée, aucun horaire de passage
        $sv = $this->scenarioView($race, null, $cps, [self::scenario($race, 31, 'Sans cible', '07:15', null)]);
        $view = (new PublicPlanBuilder())->build($race, $share, null, $sv['checkpoints'], $sv['routeDistanceM'], PublicPlanBuilder::selectScenario($sv['scenarios'], null));
        self::assertFalse($view['scheduled']);
        self::assertSame('07:15', $view['startTime']);
        self::assertNull($view['finishClock']);
        self::assertNull($view['map']);
        foreach (\array_slice($view['points'], 1) as $point) {
            self::assertNull($point['clock']);
            self::assertNull($point['elapsedSeconds']);
            self::assertNull($point['at']);
            self::assertNull($point['paceSecPerKm']);
        }
        // Les barrières (heure officielle) restent affichées avec l'option
        self::assertSame(['time' => '10:15', 'dayOffset' => 0], $view['points'][2]['cutoffClock']);

        // Aucun scénario : la course, les repères et les messages restent affichés
        $sv = $this->scenarioView($race, null, $cps, []);
        $view = (new PublicPlanBuilder())->build($race, $share->setStartMessage('Coucou'), null, $sv['checkpoints'], $sv['routeDistanceM'], null);
        self::assertFalse($view['scheduled']);
        self::assertNull($view['startTime']);
        self::assertSame('Coucou', $view['points'][0]['message']);
        self::assertSame('Ravito 1', $view['points'][2]['name']);
        self::assertSame(20000, $view['points'][5]['distanceM'], 'finish = last checkpoint without route or distance');
        self::assertSame(10800, $view['points'][2]['cutoffSeconds']);
        self::assertNull($view['points'][2]['cutoffClock'], 'no start time');
    }

    #[Test]
    public function withoutRaceDateThereIsNoAbsoluteTime(): void
    {
        $race = self::race(null);
        $cps = self::checkpoints($race);
        $sv = $this->scenarioView($race, null, $cps, [self::scenario($race, 41, 'A', '08:00', 3600 * 3)]);
        $view = (new PublicPlanBuilder())->build($race, self::share($race), null, $sv['checkpoints'], $sv['routeDistanceM'], $sv['scenarios'][0]);

        self::assertTrue($view['scheduled']);
        self::assertNull($view['race']['date']);
        self::assertNull($view['finishAt']);
        foreach ($view['points'] as $point) {
            self::assertNull($point['at']);
        }
        self::assertNotNull($view['points'][1]['clock']);
    }

    #[Test]
    public function mapIsDownsampledAndNumberedLikeTheTimeline(): void
    {
        $race = self::race();
        $route = self::route($race, 10); // 2501 points
        $cps = self::checkpoints($race);
        $sv = $this->scenarioView($race, $route, $cps, []);
        $view = (new PublicPlanBuilder())->build($race, self::share($race), $route, $sv['checkpoints'], $sv['routeDistanceM'], null);

        $points = $view['map']['points'];
        self::assertLessThanOrEqual(PublicPlanBuilder::MAX_MAP_POINTS + 1, \count($points));
        self::assertGreaterThan(400, \count($points));
        self::assertSame([0, 500.0, 45.0, 6.0], $points[0]);
        self::assertSame(25000, $points[\count($points) - 1][0], 'last point kept');
        self::assertSame([1, 2, 3, 4], array_column($view['map']['checkpoints'], 'number'));
        self::assertSame(['Col', 'Ravito 1', 'Sommet', 'Ravito 2'], array_column($view['map']['checkpoints'], 'name'));
        self::assertEqualsWithDelta(45.0 + 10000 / 111195.08, $view['map']['checkpoints'][1]['lat'], 1e-5);
        self::assertSame(500, $view['gainM']);
    }
}
