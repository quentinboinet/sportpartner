<?php

namespace App\Tests\Unit\Service\Notifications;

use App\Entity\Gear;
use App\Entity\GearBrand;
use App\Entity\GearType;
use App\Entity\Sport;
use App\Service\Gear\GearUsage;
use App\Service\Goals\GoalMetric;
use App\Service\Goals\SportGoalProgress;
use App\Service\Notifications\RaceTodoRow;
use App\Service\Notifications\RecentActivityRow;
use App\Service\Notifications\StravaState;
use App\Service\Notifications\TodoBuilder;
use App\Service\Notifications\TodoInput;
use App\Service\Notifications\TodoItem;
use App\Service\Notifications\TodoText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TodoBuilderTest extends TestCase
{
    /** Lundi 16 novembre 2026 (semaine ISO 2026-W47). */
    private const MONDAY = '2026-11-16 10:00:00';
    /** Dimanche 22 novembre 2026, même semaine. */
    private const SUNDAY = '2026-11-22 18:00:00';

    private static function now(string $at = self::MONDAY, string $tz = 'Europe/Paris'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($at, new \DateTimeZone($tz));
    }

    /** @return list<TodoItem> */
    private static function build(TodoInput $input, ?\DateTimeImmutable $now = null): array
    {
        return (new TodoBuilder())->build($input, $now ?? self::now());
    }

    /**
     * @param list<TodoItem> $items
     *
     * @return list<string>
     */
    private static function keys(array $items): array
    {
        return array_map(static fn (TodoItem $i): string => $i->key, $items);
    }

    private static function race(int $id, int $inDays, ?string $location = null, bool $route = false, int $checkpoints = 0, int $scenarios = 0, int $timed = 0, string $name = 'Marathon d\'Annecy'): RaceTodoRow
    {
        $date = self::now()->setTime(0, 0)->modify(($inDays >= 0 ? '+' : '').$inDays.' days');

        return new RaceTodoRow($id, $name, $date, $location, $route, $checkpoints, $scenarios, $timed);
    }

    private static function readyRace(int $id, int $inDays, ?string $location = null): RaceTodoRow
    {
        return self::race($id, $inDays, $location, true, 3, 1, 1);
    }

    private static function activity(int $id, int $daysAgo, ?string $slug, ?int $moving = 3600, ?int $elapsed = null, bool $gear = false, bool $fuel = false, ?string $name = 'Sortie', string $time = '08:00'): RecentActivityRow
    {
        $start = self::now()->modify('-'.$daysAgo.' days')->modify($time);

        return new RecentActivityRow($id, $name, $start, $slug, $moving, $elapsed, $gear, $fuel);
    }

    /** @return array{gear: Gear, usage: GearUsage} */
    private static function wear(int $id, GearType $type, float $km, int $maxKm, string $brand = 'Nike', string $model = 'Pegasus 40', ?string $nickname = null): array
    {
        $gear = (new Gear())->setType($type)->setBrand((new GearBrand())->setName($brand))->setModel($model)->setNickname($nickname)->setMaxDistanceM($maxKm * 1000);
        (new \ReflectionProperty(Gear::class, 'id'))->setValue($gear, $id);

        return ['gear' => $gear, 'usage' => new GearUsage(10, $km * 1000, 36000, null, 0, $maxKm * 1000)];
    }

    private static function sport(string $slug, int $id = 1): Sport
    {
        $sport = (new Sport())->setSlug($slug)->setIcon('bi-x')->setColor('#000000');
        (new \ReflectionProperty(Sport::class, 'id'))->setValue($sport, $id);

        return $sport;
    }

    private static function item(string $key, TodoInput $input, ?\DateTimeImmutable $now = null): ?TodoItem
    {
        foreach (self::build($input, $now) as $item) {
            if ($item->key === $key) {
                return $item;
            }
        }

        return null;
    }

    // ── Rien à signaler ───────────────────────────────────────────────

    public function testNothingToReportForAConnectedUserWithoutData(): void
    {
        self::assertSame([], self::build(new TodoInput()));
    }

    // ── Courses ───────────────────────────────────────────────────────

    /** @return iterable<string, array{int, ?string, ?string, ?string, ?string}> */
    public static function readyRaceProvider(): iterable
    {
        // jours => clé (sans race:7:), gravité, titre, route
        yield 'J0'         => [0, 'countdown:j0', TodoItem::URGENT, 'notifications.race_today', 'app_races_show'];
        yield 'J-1'        => [1, 'countdown:j1', TodoItem::URGENT, 'notifications.race_tomorrow', 'app_races_show'];
        yield 'J-2'        => [2, 'countdown:j7', TodoItem::INFO, 'notifications.race_in_days', 'app_races_show'];
        yield 'J-7'        => [7, 'countdown:j7', TodoItem::INFO, 'notifications.race_in_days', 'app_races_show'];
        yield 'J-8'        => [8, 'countdown:j15', TodoItem::INFO, 'notifications.race_in_days', 'app_races_show'];
        yield 'J-15'       => [15, 'countdown:j15', TodoItem::INFO, 'notifications.race_in_days', 'app_races_show'];
        yield 'J-16'       => [16, null, null, null, null];
        yield 'J-21'       => [21, null, null, null, null];
        yield 'yesterday'  => [-1, null, null, null, null];
    }

    #[DataProvider('readyRaceProvider')]
    public function testCountdownOfARaceWithAPlan(int $days, ?string $key, ?string $severity, ?string $title, ?string $route): void
    {
        $items = self::build(new TodoInput(races: [self::readyRace(7, $days)]));
        if ($key === null) {
            self::assertSame([], $items);

            return;
        }

        self::assertCount(1, $items);
        self::assertSame('race:7:'.$key, $items[0]->key);
        self::assertSame($severity, $items[0]->severity);
        self::assertSame($title, $items[0]->title->key);
        self::assertSame('Marathon d\'Annecy', $items[0]->title->params['%name%']);
        self::assertSame($route, $items[0]->route);
        self::assertSame(['id' => 7], $items[0]->routeParams);
        if ($days >= 2) {
            self::assertSame($days, $items[0]->title->params['%count%']);
        }
    }

    /** @return iterable<string, array{int, ?string, ?string, ?string, ?string}> */
    public static function noPlanRaceProvider(): iterable
    {
        yield 'J0 : jour J, pas le plan'      => [0, 'countdown:j0', TodoItem::URGENT, 'notifications.race_today', 'app_races_show'];
        yield 'J-1 : demain, pas le plan'     => [1, 'countdown:j1', TodoItem::URGENT, 'notifications.race_tomorrow', 'app_races_show'];
        yield 'J-2 : fusionné'                => [2, 'noplan:j7', TodoItem::WARNING, 'notifications.race_no_plan', 'app_race_plan'];
        yield 'J-15 : fusionné'               => [15, 'noplan:j15', TodoItem::WARNING, 'notifications.race_no_plan', 'app_race_plan'];
        yield 'J-16 : rappel du plan seul'    => [16, 'noplan:j21', TodoItem::INFO, 'notifications.race_no_plan', 'app_race_plan'];
        yield 'J-21 : rappel du plan seul'    => [21, 'noplan:j21', TodoItem::INFO, 'notifications.race_no_plan', 'app_race_plan'];
        yield 'J-22 : trop loin'              => [22, null, null, null, null];
    }

    #[DataProvider('noPlanRaceProvider')]
    public function testRaceWithoutAnyPlan(int $days, ?string $key, ?string $severity, ?string $title, ?string $route): void
    {
        $items = self::build(new TodoInput(races: [self::race(9, $days)]));
        if ($key === null) {
            self::assertSame([], $items);

            return;
        }

        self::assertCount(1, $items);
        self::assertSame('race:9:'.$key, $items[0]->key);
        self::assertSame($severity, $items[0]->severity);
        self::assertSame($title, $items[0]->title->key);
        self::assertSame($route, $items[0]->route);
        self::assertSame(['id' => 9], $items[0]->routeParams);
    }

    /** @return iterable<string, array{RaceTodoRow, string}> */
    public static function planStateProvider(): iterable
    {
        yield 'GPX seul'                       => [self::race(4, 18, route: true), 'race:4:nosplits:j21'];
        yield 'repères seuls'                  => [self::race(4, 18, checkpoints: 2), 'race:4:nosplits:j21'];
        yield 'scénario sans temps visé'       => [self::race(4, 10, scenarios: 1), 'race:4:nosplits:j15'];
        yield 'GPX + scénario sans temps visé' => [self::race(4, 5, route: true, scenarios: 2), 'race:4:nosplits:j7'];
        yield 'rien du tout'                   => [self::race(4, 5), 'race:4:noplan:j7'];
        yield 'temps visé sans GPX = prêt'     => [self::race(4, 5, scenarios: 1, timed: 1), 'race:4:countdown:j7'];
    }

    #[DataProvider('planStateProvider')]
    public function testPlanStateDecidesTheMessage(RaceTodoRow $race, string $key): void
    {
        $items = self::build(new TodoInput(races: [$race]));

        self::assertSame([$key], self::keys($items));
        $expectedTitle = match (true) {
            str_contains($key, 'nosplits') => 'notifications.race_no_splits',
            str_contains($key, 'noplan')   => 'notifications.race_no_plan',
            default                        => 'notifications.race_in_days',
        };
        self::assertSame($expectedTitle, $items[0]->title->key);
    }

    /** Une seule ligne par course, jamais deux messages contradictoires, de J0 à J-21 et quel que soit le plan. */
    public function testNeverTwoItemsForOneRace(): void
    {
        foreach (range(0, 21) as $days) {
            foreach ([self::race(1, $days), self::race(1, $days, route: true), self::readyRace(1, $days)] as $race) {
                $items = self::build(new TodoInput(races: [$race]));
                self::assertLessThanOrEqual(1, count($items), "J-$days ".$race->planState());
                $expected = $race->planState() === RaceTodoRow::PLAN_READY && $days > 15 ? 0 : 1;
                self::assertCount($expected, $items, "J-$days ".$race->planState());
            }
        }
    }

    public function testWeatherSubtitleWhenAPlaceIsKnownWithinTheForecastWindow(): void
    {
        $withLocation = self::build(new TodoInput(races: [self::readyRace(1, 7, 'Annecy')]))[0];
        self::assertNotNull($withLocation->subtitle);
        self::assertSame('notifications.race_weather', $withLocation->subtitle->key);

        $blankLocation = self::build(new TodoInput(races: [self::race(2, 0, '   ')]))[0];
        self::assertNull($blankLocation->subtitle, 'lieu vide');

        $gpxOnly = self::build(new TodoInput(races: [self::race(3, 1, null, true)]))[0];
        self::assertSame('notifications.race_weather', $gpxOnly->subtitle?->key, 'départ du GPX');

        $merged = self::build(new TodoInput(races: [self::race(4, 15, 'Chamonix')]))[0];
        self::assertSame('notifications.race_weather', $merged->subtitle?->key, 'J-15 : prévision disponible');

        $tooFar = self::build(new TodoInput(races: [self::race(5, 16, 'Chamonix')]))[0];
        self::assertNull($tooFar->subtitle, 'J-16 : hors prévision');
    }

    public function testRacesAreSortedByDateThenId(): void
    {
        $items = self::build(new TodoInput(races: [self::readyRace(30, 9), self::readyRace(20, 3), self::readyRace(10, 9)]));

        self::assertSame(['race:20:countdown:j7', 'race:10:countdown:j15', 'race:30:countdown:j15'], self::keys($items));
    }

    /** Jours comptés en dates de calendrier : le passage à l'heure d'été (29/03/2026) ne décale rien. */
    public function testDaysAreCalendarDaysAcrossDaylightSavingTime(): void
    {
        $now = new \DateTimeImmutable('2026-03-28 23:30:00', new \DateTimeZone('Europe/Paris'));
        $race = new RaceTodoRow(1, 'Semi', new \DateTimeImmutable('2026-03-30', new \DateTimeZone('Europe/Paris')), null, false, 0, 0, 1);

        $items = self::build(new TodoInput(races: [$race]), $now);

        self::assertSame('race:1:countdown:j7', $items[0]->key);
        self::assertSame(2, $items[0]->title->params['%count%']);
        self::assertSame(1, TodoBuilder::dayIndex(new \DateTimeImmutable('2026-03-30 00:00', new \DateTimeZone('Europe/Paris')))
            - TodoBuilder::dayIndex(new \DateTimeImmutable('2026-03-29 23:59', new \DateTimeZone('Europe/Paris'))));
    }

    // ── Usure du matériel ─────────────────────────────────────────────

    public function testWornShoesAreUrgentAndWarnShoesAWarning(): void
    {
        $items = self::build(new TodoInput(wearAlerts: [
            self::wear(12, GearType::Shoes, 760.4, 700, nickname: 'Rouges'),
            self::wear(13, GearType::Shoes, 600, 700, 'Hoka', 'Clifton 9'),
        ]));

        self::assertSame(['gear:12:worn', 'gear:13:warn'], self::keys($items));
        [$worn, $warn] = $items;
        self::assertSame(TodoItem::URGENT, $worn->severity);
        self::assertSame('notifications.gear_shoes_worn', $worn->title->key);
        self::assertSame('Nike Pegasus 40 (Rouges)', $worn->title->params['%name%']);
        self::assertSame('notifications.gear_shoes_usage', $worn->subtitle?->key);
        self::assertSame(['%km%' => 760, '%pct%' => 108], $worn->subtitle->params);
        self::assertSame('app_settings', $worn->route);
        self::assertSame(['tab' => 'gear'], $worn->routeParams);
        self::assertSame('gear-12', $worn->fragment);

        self::assertSame(TodoItem::WARNING, $warn->severity);
        self::assertSame('notifications.gear_shoes_warn', $warn->title->key);
        self::assertSame('Hoka Clifton 9', $warn->title->params['%name%']);
        self::assertSame(['%km%' => 600, '%pct%' => 85], $warn->subtitle?->params);
    }

    public function testBikeWordingAndGenericBrand(): void
    {
        $items = self::build(new TodoInput(wearAlerts: [
            self::wear(5, GearType::Bike, 8000, 8000, GearBrand::OTHER, 'Gravel alu'),
            self::wear(6, GearType::Bike, 6500, 8000, 'Canyon', 'Grail'),
        ]));

        self::assertSame(['gear:5:worn', 'gear:6:warn'], self::keys($items));
        self::assertSame('notifications.gear_bike_worn', $items[0]->title->key);
        self::assertSame('Gravel alu', $items[0]->title->params['%name%'], 'la marque « Autre » n\'est pas affichée');
        self::assertSame('notifications.gear_bike_usage', $items[0]->subtitle?->key);
        self::assertSame('notifications.gear_bike_warn', $items[1]->title->key);
    }

    /** Clé = matériel + état : passer de « bientôt usé » à « usé » change la clé (l'alerte masquée revient). */
    public function testWearKeyChangesWhenTheStatusGetsWorse(): void
    {
        $warn = self::build(new TodoInput(wearAlerts: [self::wear(12, GearType::Shoes, 699.9, 700)]));
        $worn = self::build(new TodoInput(wearAlerts: [self::wear(12, GearType::Shoes, 700, 700)]));

        self::assertSame(['gear:12:warn'], self::keys($warn));
        self::assertSame(['gear:12:worn'], self::keys($worn));
    }

    public function testGearBelowWarnThresholdIsIgnored(): void
    {
        self::assertSame([], self::build(new TodoInput(wearAlerts: [self::wear(1, GearType::Shoes, 559.9, 700)])));
    }

    // ── Activités sans matériel ──────────────────────────────────────

    public function testRecentActivitiesWithoutGearAreAggregated(): void
    {
        $input = new TodoInput(
            activeGearTypes: [GearType::Shoes],
            recentActivities: [
                self::activity(101, 3, 'run'),
                self::activity(102, 1, 'trail', name: 'Trail du Semnoz'),
                self::activity(103, 2, 'walk', gear: true),            // a déjà du matériel
                self::activity(104, 0, 'ride'),                         // pas de vélo actif
                self::activity(105, 0, 'other'),                        // « Autre » : rien d'attendu
                self::activity(106, 0, null),                           // sans sport
                self::activity(107, 0, 'swim'),
                self::activity(108, 14, 'run'),                         // hors des 14 jours
            ],
        );

        $item = self::item('gear:missing:102', $input);
        self::assertNotNull($item);
        self::assertSame(TodoItem::INFO, $item->severity);
        self::assertSame('notifications.gear_missing_many', $item->title->key);
        self::assertSame(['%count%' => 2], $item->title->params);
        self::assertSame('notifications.gear_missing_latest', $item->subtitle?->key);
        self::assertSame('Trail du Semnoz', $item->subtitle->params['%name%']);
        self::assertSame('app_activity_show', $item->route);
        self::assertSame(['id' => 102], $item->routeParams);
        self::assertSame('act-gear-select', $item->fragment);
    }

    public function testOneActivityWithoutGearAndBikeCompatibility(): void
    {
        $input = new TodoInput(activeGearTypes: [GearType::Bike], recentActivities: [self::activity(7, 13, 'ride', name: null), self::activity(8, 1, 'run')]);
        $items = self::build($input);

        self::assertSame(['gear:missing:7'], self::keys($items), 'J-13 compte, la course à pied n\'a pas de chaussures actives');
        self::assertSame('notifications.gear_missing_one', $items[0]->title->key);
        self::assertSame('notifications.gear_missing_only', $items[0]->subtitle?->key);
        $name = $items[0]->subtitle->params['%name%'];
        self::assertInstanceOf(TodoText::class, $name);
        self::assertSame('notifications.activity_unnamed', $name->key);
    }

    public function testNoGearItemWithoutActiveGearOfTheRightType(): void
    {
        self::assertSame([], self::build(new TodoInput(activeGearTypes: [], recentActivities: [self::activity(1, 0, 'run', moving: 1800)])));
    }

    /** Une nouvelle activité sans matériel change la clé : l'alerte masquée revient. */
    public function testNewUntaggedActivityChangesTheKey(): void
    {
        $before = self::build(new TodoInput(activeGearTypes: [GearType::Shoes], recentActivities: [self::activity(1, 2, 'run', 1800)]));
        $after = self::build(new TodoInput(activeGearTypes: [GearType::Shoes], recentActivities: [self::activity(1, 2, 'run', 1800), self::activity(2, 0, 'run', 1800)]));

        self::assertSame(['gear:missing:1'], self::keys($before));
        self::assertSame(['gear:missing:2'], self::keys($after));
    }

    // ── Ravitaillement ────────────────────────────────────────────────

    /** @return iterable<string, array{RecentActivityRow, bool}> */
    public static function fuelProvider(): iterable
    {
        yield '1h30 pile'                      => [self::activity(1, 1, 'run', 5400), true];
        yield '1h29m59'                        => [self::activity(1, 1, 'run', 5399), false];
        yield 'sans temps en mouvement'        => [self::activity(1, 1, 'trail', null, 6000), true];
        yield 'temps en mouvement à 0'         => [self::activity(1, 1, 'hike', 0, 7200), true];
        yield 'aucune durée'                   => [self::activity(1, 1, 'run', null, null), false];
        yield 'vélo'                           => [self::activity(1, 1, 'ride', 10800), true];
        yield 'sans sport (= autre)'           => [self::activity(1, 1, null, 10800), true];
        yield 'natation : pas de carte'        => [self::activity(1, 1, 'swim', 10800), false];
        yield 'musculation : pas de carte'     => [self::activity(1, 1, 'weight_training', 10800), false];
        yield 'ravitaillement déjà noté'       => [self::activity(1, 1, 'run', 10800, fuel: true), false];
        yield 'il y a 13 jours'                => [self::activity(1, 13, 'run', 10800), true];
        yield 'il y a 14 jours : trop ancien'  => [self::activity(1, 14, 'run', 10800), false];
    }

    #[DataProvider('fuelProvider')]
    public function testFuelReminderRules(RecentActivityRow $activity, bool $expected): void
    {
        self::assertSame($expected ? ['fuel:activity:1'] : [], self::keys(self::build(new TodoInput(recentActivities: [$activity]))));
    }

    public function testFuelReminderContent(): void
    {
        $items = self::build(new TodoInput(recentActivities: [self::activity(88, 1, 'trail', 7500, name: 'Grand Colombier')]));

        self::assertSame(['fuel:activity:88'], self::keys($items));
        $fuel = $items[0];
        self::assertSame(TodoItem::INFO, $fuel->severity);
        self::assertSame('notifications.fuel_missing', $fuel->title->key);
        self::assertSame('Grand Colombier', $fuel->title->params['%name%']);
        self::assertSame('app_activity_show', $fuel->route);
        self::assertSame(['id' => 88], $fuel->routeParams);
        self::assertSame('sp-fuel-title', $fuel->fragment);
        $duration = $fuel->subtitle?->params['%duration%'];
        self::assertInstanceOf(TodoText::class, $duration);
        self::assertSame('objectives.by_sport.hours_minutes', $duration->key);
        self::assertSame(['%h%' => '2', '%m%' => '05'], $duration->params);
        $when = $fuel->subtitle->params['%when%'];
        self::assertInstanceOf(TodoText::class, $when);
        self::assertSame('notifications.when_yesterday', $when->key);
    }

    public function testAtMostThreeFuelRemindersMostRecentFirst(): void
    {
        $items = self::build(new TodoInput(recentActivities: [
            self::activity(1, 6, 'run', 6000),
            self::activity(2, 0, 'run', 6000, time: '07:00'),
            self::activity(3, 4, 'ride', 9000),
            self::activity(4, 0, 'run', 6000, time: '18:00'),
            self::activity(5, 9, 'trail', 9000),
        ]));

        self::assertSame(['fuel:activity:4', 'fuel:activity:2', 'fuel:activity:3'], self::keys($items));
        $whenToday = $items[0]->subtitle?->params['%when%'];
        self::assertInstanceOf(TodoText::class, $whenToday);
        self::assertSame('notifications.when_today', $whenToday->key);
        $whenDays = $items[2]->subtitle?->params['%when%'];
        self::assertInstanceOf(TodoText::class, $whenDays);
        self::assertSame(['notifications.when_days', ['%count%' => 4]], [$whenDays->key, $whenDays->params]);
    }

    // ── Objectifs par sport ──────────────────────────────────────────

    public function testReachedGoalAnyDayOfTheWeek(): void
    {
        $goal = new SportGoalProgress(self::sport('ride'), [new GoalMetric(GoalMetric::DISTANCE, 160.0, 150.0), new GoalMetric(GoalMetric::SESSIONS, 2, 2)]);
        $items = self::build(new TodoInput(goals: [$goal]));

        self::assertSame(['goal:ride:2026-W47:reached'], self::keys($items));
        self::assertSame(TodoItem::SUCCESS, $items[0]->severity);
        self::assertSame('notifications.goal_reached', $items[0]->title->key);
        $lc = $items[0]->title->params['%sport_lc%'];
        self::assertInstanceOf(TodoText::class, $lc);
        self::assertSame(['sport.ride', true], [$lc->key, $lc->lowercase]);
        self::assertSame('app_dashboard', $items[0]->route);
        self::assertSame('sport-goals-title', $items[0]->fragment);
    }

    public function testUnreachedGoalIsOnlyRemindedOnSunday(): void
    {
        $goal = new SportGoalProgress(self::sport('run'), [
            new GoalMetric(GoalMetric::DISTANCE, 18.0, 30.0),
            new GoalMetric(GoalMetric::SESSIONS, 2, 3),
            new GoalMetric(GoalMetric::DURATION, 300, 300),
            new GoalMetric(GoalMetric::ELEVATION, 700, 1000),
        ]);

        foreach (['2026-11-16 00:00:00', self::MONDAY, '2026-11-21 23:59:59'] as $weekday) {
            self::assertSame([], self::build(new TodoInput(goals: [$goal]), self::now($weekday)), $weekday);
        }
        foreach (['2026-11-22 00:00:00', self::SUNDAY, '2026-11-22 23:59:59'] as $sunday) {
            self::assertSame(['goal:run:2026-W47:left'], self::keys(self::build(new TodoInput(goals: [$goal]), self::now($sunday))), $sunday);
        }

        $item = self::build(new TodoInput(goals: [$goal]), self::now(self::SUNDAY))[0];
        self::assertSame(TodoItem::WARNING, $item->severity);
        self::assertSame('notifications.goal_left', $item->title->key);
        $left = $item->title->params['%left%'];
        self::assertIsArray($left);
        // Durée atteinte : absente de la liste
        self::assertSame(
            [['notifications.left_distance', ['%value%' => 12.0]], ['notifications.left_sessions_one', []], ['notifications.left_elevation', ['%value%' => 300]]],
            array_map(static fn (TodoText $t): array => [$t->key, $t->params], $left),
        );
    }

    /** @return iterable<string, array{int, int, string, array<string, string>}> */
    public static function durationLeftProvider(): iterable
    {
        yield '45 min'  => [255, 300, 'objectives.by_sport.minutes', ['%m%' => '45']];
        yield '2h'      => [180, 300, 'objectives.by_sport.hours', ['%h%' => '2']];
        yield '1h05'    => [235, 300, 'objectives.by_sport.hours_minutes', ['%h%' => '1', '%m%' => '05']];
    }

    /** @param array<string, string> $params */
    #[DataProvider('durationLeftProvider')]
    public function testDurationLeftIsFormattedLikeTheGoalsCard(int $current, int $target, string $key, array $params): void
    {
        $goal = new SportGoalProgress(self::sport('trail'), [new GoalMetric(GoalMetric::DURATION, $current, $target), new GoalMetric(GoalMetric::SESSIONS, 0, 2)]);
        $left = self::build(new TodoInput(goals: [$goal]), self::now(self::SUNDAY))[0]->title->params['%left%'];
        self::assertIsArray($left);

        self::assertSame([$key, $params], [$left[0]->key, $left[0]->params]);
        self::assertSame(['notifications.left_sessions_many', ['%count%' => 2]], [$left[1]->key, $left[1]->params]);
    }

    public function testGoalKeyUsesTheIsoWeekAcrossTheYearEnd(): void
    {
        $goal = new SportGoalProgress(self::sport('swim'), [new GoalMetric(GoalMetric::SESSIONS, 1, 3)]);

        // Dimanche 3 janvier 2027 = semaine ISO 53 de 2026
        self::assertSame(['goal:swim:2026-W53:left'], self::keys(self::build(new TodoInput(goals: [$goal]), self::now('2027-01-03 12:00:00'))));
    }

    public function testReachedAndUnreachedGoalsKeepTheSettingsOrder(): void
    {
        $goals = [
            new SportGoalProgress(self::sport('ride', 3), [new GoalMetric(GoalMetric::SESSIONS, 1, 2)]),
            new SportGoalProgress(self::sport('run', 1), [new GoalMetric(GoalMetric::SESSIONS, 0, 2)]),
            new SportGoalProgress(self::sport('trail', 2), [new GoalMetric(GoalMetric::SESSIONS, 3, 2)]),
        ];

        self::assertSame(
            ['goal:ride:2026-W47:left', 'goal:run:2026-W47:left', 'goal:trail:2026-W47:reached'],
            self::keys(self::build(new TodoInput(goals: $goals), self::now(self::SUNDAY))),
        );
    }

    // ── Strava ────────────────────────────────────────────────────────

    public function testStravaNotConnectedIsLowPriorityAndLast(): void
    {
        $items = self::build(new TodoInput(
            races: [self::readyRace(1, 10)],
            recentActivities: [self::activity(2, 1, 'run', 6000)],
            strava: new StravaState(connected: false, hasAccessToken: false, hasRefreshToken: false),
        ));

        self::assertSame(['race:1:countdown:j15', 'fuel:activity:2', 'strava:connect'], self::keys($items));
        $connect = $items[2];
        self::assertSame(TodoItem::INFO, $connect->severity);
        self::assertSame('notifications.strava_connect', $connect->title->key);
        self::assertSame('app_oauth_strava', $connect->route);
    }

    /** @return iterable<string, array{StravaState, bool}> */
    public static function reconnectProvider(): iterable
    {
        $now = self::now();
        yield 'jeton de rafraîchissement présent'        => [new StravaState(true, true, true, $now->modify('-1 day')), false];
        yield 'sans rafraîchissement, jeton expiré'      => [new StravaState(true, true, false, $now->modify('-1 second')), true];
        yield 'sans rafraîchissement, expire maintenant' => [new StravaState(true, true, false, $now), true];
        yield 'sans rafraîchissement, encore valable'    => [new StravaState(true, true, false, $now->modify('+1 hour')), false];
        yield 'sans rafraîchissement, sans expiration'   => [new StravaState(true, true, false, null), true];
        yield 'sans aucun jeton'                         => [new StravaState(true, false, false, $now->modify('+1 hour')), true];
        yield 'non connecté'                             => [new StravaState(false, false, false, null), false];
    }

    #[DataProvider('reconnectProvider')]
    public function testStravaReconnectIsDetectedFromStoredTokensOnly(StravaState $state, bool $expected): void
    {
        $keys = self::keys(self::build(new TodoInput(strava: $state)));

        self::assertSame($expected, in_array('strava:reconnect', $keys, true));
        if ($expected) {
            $item = self::item('strava:reconnect', new TodoInput(strava: $state));
            self::assertSame(TodoItem::WARNING, $item?->severity);
            self::assertSame('app_oauth_strava', $item->route);
        }
    }

    // ── Ordre et clés ─────────────────────────────────────────────────

    public function testUrgentThenWarningsThenInfo(): void
    {
        // race() et activity() comptent depuis le lundi 16/11 ; « maintenant » est le dimanche 22/11 (6 jours plus tard)
        $input = new TodoInput(
            races: [
                self::readyRace(40, 6 + 12, 'Lyon'), // J-12, plan prêt : info
                self::race(50, 6 + 18),              // J-18, rappel du plan : info
                self::race(30, 6 + 9),               // J-9, sans plan : attention (fusionné)
                self::readyRace(20, 6 + 1),          // demain : urgent
                self::race(10, 6),                   // jour J : urgent
            ],
            wearAlerts: [self::wear(12, GearType::Shoes, 720, 700), self::wear(13, GearType::Bike, 6600, 8000)],
            activeGearTypes: [GearType::Shoes],
            recentActivities: [self::activity(88, 1, 'run', 6000, gear: true), self::activity(89, 2, 'run', 1200)],
            goals: [
                new SportGoalProgress(self::sport('ride', 3), [new GoalMetric(GoalMetric::SESSIONS, 3, 2)]),
                new SportGoalProgress(self::sport('run', 1), [new GoalMetric(GoalMetric::DISTANCE, 20.0, 32.5)]),
            ],
            strava: new StravaState(true, true, false, self::now(self::SUNDAY)->modify('-1 day')),
        );

        $items = self::build($input, self::now(self::SUNDAY));

        self::assertSame([
            'race:10:countdown:j0',
            'race:20:countdown:j1',
            'gear:12:worn',
            'race:30:noplan:j15',
            'gear:13:warn',
            'strava:reconnect',
            'goal:run:2026-W47:left',
            'race:40:countdown:j15',
            'race:50:noplan:j21',
            'fuel:activity:88',
            'gear:missing:89',
            'goal:ride:2026-W47:reached',
        ], self::keys($items));
        self::assertSame(
            [TodoItem::URGENT, TodoItem::URGENT, TodoItem::URGENT, TodoItem::WARNING, TodoItem::WARNING, TodoItem::WARNING, TodoItem::WARNING,
                TodoItem::INFO, TodoItem::INFO, TodoItem::INFO, TodoItem::INFO, TodoItem::SUCCESS],
            array_map(static fn (TodoItem $i): string => $i->severity, $items),
        );
    }

    public function testKeysAreUniqueAndStable(): void
    {
        $input = new TodoInput(
            races: [self::race(1, 3), self::readyRace(2, 3)],
            wearAlerts: [self::wear(3, GearType::Shoes, 690, 700)],
            activeGearTypes: [GearType::Shoes],
            recentActivities: [self::activity(4, 1, 'run', 6000), self::activity(5, 1, 'trail', 6000, fuel: true)],
            strava: new StravaState(false, false, false),
        );

        $first = self::keys(self::build($input));
        self::assertSame($first, self::keys(self::build($input)));
        self::assertSame($first, array_values(array_unique($first)));
        foreach ($first as $key) {
            self::assertMatchesRegularExpression('/^(race:\d+:(countdown|noplan|nosplits):j(0|1|7|15|21)|gear:(\d+:(warn|worn)|missing:\d+)|goal:[a-z_]+:\d{4}-W\d{2}:(reached|left)|fuel:activity:\d+|strava:(connect|reconnect))$/', $key);
        }
    }

    public function testRaceBuckets(): void
    {
        $buckets = [];
        foreach (range(0, 21) as $days) {
            $buckets[$days] = TodoBuilder::raceBucket($days);
        }

        self::assertSame(['j0', 'j1'], [$buckets[0], $buckets[1]]);
        self::assertSame(array_fill(2, 6, 'j7'), array_slice($buckets, 2, 6, true));
        self::assertSame(array_fill(8, 8, 'j15'), array_slice($buckets, 8, 8, true));
        self::assertSame(array_fill(16, 6, 'j21'), array_slice($buckets, 16, 6, true));
    }

    // ── Renfo (espace Santé) ──────────────────────────────────────────

    /** @return iterable<string, array{string, bool}> */
    public static function strengthReminderDayProvider(): iterable
    {
        // Semaine ISO 2026-W47 : lundi 16 -> dimanche 22 novembre
        yield 'lundi'               => ['2026-11-16 10:00:00', false];
        yield 'mercredi soir'       => ['2026-11-18 23:59:59', false];
        yield 'jeudi minuit'        => ['2026-11-19 00:00:00', true];
        yield 'vendredi'            => ['2026-11-20 12:00:00', true];
        yield 'samedi soir'         => ['2026-11-21 23:59:59', true];
        yield 'dimanche'            => ['2026-11-22 08:00:00', false];
    }

    #[DataProvider('strengthReminderDayProvider')]
    public function testStrengthReminderFromThursdayToSaturday(string $at, bool $expected): void
    {
        $goal = new SportGoalProgress(self::sport('weight_training', 7), [new GoalMetric(GoalMetric::SESSIONS, 0, 2)]);
        $items = self::build(new TodoInput(goals: [$goal]), self::now($at));

        if (!$expected) {
            self::assertNotContains('health:strength:2026-W47', self::keys($items), $at);

            return;
        }
        self::assertSame(['health:strength:2026-W47'], self::keys($items), $at);
        $item = $items[0];
        self::assertSame(TodoItem::INFO, $item->severity);
        self::assertSame('bi-heart-pulse', $item->icon);
        self::assertSame(['notifications.health_strength_none', ['%count%' => 2]], [$item->title->key, $item->title->params]);
        self::assertSame('notifications.health_strength_none_sub', $item->subtitle?->key);
        self::assertSame(['app_health', [], null], [$item->route, $item->routeParams, $item->fragment]);
    }

    public function testStrengthReminderOnSundayIsTheUsualGoalLeftReminder(): void
    {
        $goal = new SportGoalProgress(self::sport('weight_training', 7), [new GoalMetric(GoalMetric::SESSIONS, 0, 2)]);

        self::assertSame(['goal:weight_training:2026-W47:left'], self::keys(self::build(new TodoInput(goals: [$goal]), self::now(self::SUNDAY))));
    }

    /** @return iterable<string, array{SportGoalProgress}> */
    public static function noStrengthReminderProvider(): iterable
    {
        yield 'une séance déjà faite'   => [new SportGoalProgress(self::sport('weight_training', 7), [new GoalMetric(GoalMetric::SESSIONS, 1, 2)])];
        yield 'autre sport'             => [new SportGoalProgress(self::sport('run', 1), [new GoalMetric(GoalMetric::SESSIONS, 0, 2)])];
        yield 'cible en durée seulement' => [new SportGoalProgress(self::sport('weight_training', 7), [new GoalMetric(GoalMetric::DURATION, 0, 60)])];
        yield 'objectif atteint'        => [new SportGoalProgress(self::sport('weight_training', 7), [new GoalMetric(GoalMetric::SESSIONS, 2, 2)])];
    }

    #[DataProvider('noStrengthReminderProvider')]
    public function testNoStrengthReminder(SportGoalProgress $goal): void
    {
        $keys = self::keys(self::build(new TodoInput(goals: [$goal]), self::now('2026-11-20 12:00:00')));

        self::assertSame([], array_values(array_filter($keys, static fn (string $k): bool => str_starts_with($k, 'health:'))), implode(',', $keys));
    }

    public function testStrengthReminderWithOtherTargetsKeepsOneItemAndAStableWeeklyKey(): void
    {
        $goal = new SportGoalProgress(self::sport('weight_training', 7), [new GoalMetric(GoalMetric::DURATION, 0, 90), new GoalMetric(GoalMetric::SESSIONS, 0, 3)]);
        $thursday = self::keys(self::build(new TodoInput(goals: [$goal]), self::now('2026-11-19 09:00:00')));
        $saturday = self::keys(self::build(new TodoInput(goals: [$goal]), self::now('2026-11-21 20:00:00')));
        $nextWeek = self::keys(self::build(new TodoInput(goals: [$goal]), self::now('2026-11-26 09:00:00')));

        self::assertSame(['health:strength:2026-W47'], $thursday);
        self::assertSame($thursday, $saturday);
        self::assertSame(['health:strength:2026-W48'], $nextWeek);
        self::assertMatchesRegularExpression('/^health:strength:\d{4}-W\d{2}$/', $thursday[0]);
    }

    public function testStrengthReminderRanksWithInfoItemsBeforeFuel(): void
    {
        $items = self::build(new TodoInput(
            recentActivities: [self::activity(2, 0, 'run', 6000)],
            goals: [
                new SportGoalProgress(self::sport('ride', 3), [new GoalMetric(GoalMetric::SESSIONS, 3, 2)]),
                new SportGoalProgress(self::sport('weight_training', 7), [new GoalMetric(GoalMetric::SESSIONS, 0, 2)]),
            ],
            strava: new StravaState(connected: false, hasAccessToken: false, hasRefreshToken: false),
        ), self::now('2026-11-19 09:00:00'));

        self::assertSame(['health:strength:2026-W47', 'fuel:activity:2', 'goal:ride:2026-W47:reached', 'strava:connect'], self::keys($items));
    }

    public function testActivitiesCreatedByAHealthSessionNeverAskForFuel(): void
    {
        $start = self::now()->modify('-1 day');
        $health = new RecentActivityRow(5, 'Mobilité longue', $start, 'other', 6000, 6200, false, false, true);
        $plain = new RecentActivityRow(6, 'Autre sortie', $start, 'other', 6000, 6200, false, false);

        self::assertSame(['fuel:activity:6'], self::keys(self::build(new TodoInput(recentActivities: [$health, $plain]))));
        self::assertFalse($plain->fromHealthSession);
    }
}
