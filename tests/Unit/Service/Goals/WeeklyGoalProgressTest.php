<?php

namespace App\Tests\Unit\Service\Goals;

use App\Entity\Sport;
use App\Entity\User;
use App\Entity\WeeklyGoal;
use App\Repository\ActivityRepository;
use App\Repository\WeeklyGoalRepository;
use App\Service\Goals\GoalMetric;
use App\Service\Goals\SportGoalProgress;
use App\Service\Goals\WeeklyGoalProgress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

class WeeklyGoalProgressTest extends TestCase
{
    private static function sport(int $id, string $slug): Sport
    {
        $sport = (new Sport())->setSlug($slug)->setIcon('bi-x')->setColor('#000000');
        (new \ReflectionProperty(Sport::class, 'id'))->setValue($sport, $id);

        return $sport;
    }

    /** @return array{distanceM: float, sessions: int, movingSeconds: int, elevationM: float} */
    private static function totals(float $distanceM = 0.0, int $sessions = 0, int $movingSeconds = 0, float $elevationM = 0.0): array
    {
        return ['distanceM' => $distanceM, 'sessions' => $sessions, 'movingSeconds' => $movingSeconds, 'elevationM' => $elevationM];
    }

    /** @return array<string, GoalMetric> */
    private static function metricsByKind(SportGoalProgress $progress): array
    {
        $out = [];
        foreach ($progress->metrics as $metric) {
            $out[$metric->kind] = $metric;
        }

        return $out;
    }

    // ── Bornes de la semaine ───────────────────────────────────────────

    /** @return iterable<string, array{string}> */
    public static function daysOfWeekProvider(): iterable
    {
        yield 'monday 00:00:00'    => ['2026-11-16 00:00:00'];
        yield 'monday morning'     => ['2026-11-16 09:30:00'];
        yield 'wednesday'          => ['2026-11-18 12:00:00'];
        yield 'saturday'           => ['2026-11-21 18:45:10'];
        yield 'sunday 23:59:59'    => ['2026-11-22 23:59:59'];
    }

    #[DataProvider('daysOfWeekProvider')]
    public function testWeekRunsFromMondayMidnightToSundayEndOfDay(string $now): void
    {
        [$from, $to] = WeeklyGoalProgress::weekBounds(new \DateTimeImmutable($now, new \DateTimeZone('UTC')));

        self::assertSame('2026-11-16 00:00:00', $from->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-22 23:59:59', $to->format('Y-m-d H:i:s'));
    }

    /** Mêmes bornes que le libellé du tableau de bord ('monday/sunday this week') et que YEARWEEK(…, 1). */
    #[DataProvider('daysOfWeekProvider')]
    public function testWeekMatchesDashboardLabelAndIsoWeek(string $now): void
    {
        $date = new \DateTimeImmutable($now, new \DateTimeZone('UTC'));
        [$from, $to] = WeeklyGoalProgress::weekBounds($date);

        self::assertSame($date->modify('monday this week')->format('Y-m-d'), $from->format('Y-m-d'));
        self::assertSame($date->modify('sunday this week')->format('Y-m-d'), $to->format('Y-m-d'));
        self::assertSame($date->format('oW'), $from->format('oW'));
        self::assertSame($date->format('oW'), $to->format('oW'));
        self::assertNotSame($date->format('oW'), $from->modify('-1 second')->format('oW'), 'la seconde avant lundi 00:00 est la semaine précédente');
        self::assertNotSame($date->format('oW'), $to->modify('+1 second')->format('oW'), 'la seconde après dimanche 23:59:59 est la semaine suivante');
    }

    public function testWeekAcrossYearEndAndTimezoneKept(): void
    {
        [$from, $to] = WeeklyGoalProgress::weekBounds(new \DateTimeImmutable('2027-01-01 10:00:00', new \DateTimeZone('UTC')));
        self::assertSame('2026-12-28 00:00:00', $from->format('Y-m-d H:i:s'));
        self::assertSame('2027-01-03 23:59:59', $to->format('Y-m-d H:i:s'));

        // Fuseau de l'horloge conservé (aucune conversion en UTC)
        $paris = new \DateTimeZone('Europe/Paris');
        [$from, $to] = WeeklyGoalProgress::weekBounds(new \DateTimeImmutable('2026-11-16 00:30:00', $paris));
        self::assertSame('2026-11-16 00:00:00', $from->format('Y-m-d H:i:s'));
        self::assertSame('Europe/Paris', $from->getTimezone()->getName());
        self::assertSame('2026-11-22 23:59:59', $to->format('Y-m-d H:i:s'));
    }

    // ── Calcul ─────────────────────────────────────────────────────────

    public function testRatiosRemainingAndCappedBars(): void
    {
        $run  = self::sport(1, 'run');
        $ride = self::sport(3, 'ride');
        $goals = [
            (new WeeklyGoal())->setSport($run)->setDistanceKm(30)->setSessions(3),
            (new WeeklyGoal())->setSport($ride)->setDistanceKm(150)->setDurationMinutes(300),
        ];
        $progress = WeeklyGoalProgress::build($goals, [
            1 => self::totals(18000.0, 2, 5400),
            3 => self::totals(160000.0, 1, 18600, 900.0),
        ]);

        self::assertCount(2, $progress);
        self::assertSame($run, $progress[0]->sport);
        $m = self::metricsByKind($progress[0]);
        self::assertSame([GoalMetric::DISTANCE, GoalMetric::SESSIONS], array_keys($m), 'cibles non renseignées ignorées');
        self::assertSame(18.0, $m['distance']->current);
        self::assertEqualsWithDelta(0.6, $m['distance']->ratio(), 1e-9);
        self::assertSame(60, $m['distance']->percent());
        self::assertSame(60, $m['distance']->barPercent());
        self::assertSame(12.0, $m['distance']->remaining());
        self::assertFalse($m['distance']->isReached());
        self::assertSame(2, $m['sessions']->current);
        self::assertSame(66, $m['sessions']->barPercent());
        self::assertSame(67, $m['sessions']->percent());
        self::assertSame(1, $m['sessions']->remaining());
        self::assertFalse($progress[0]->isReached());

        // Au-delà de la cible : valeur réelle et pourcentage réel, barre plafonnée à 100
        $m = self::metricsByKind($progress[1]);
        self::assertSame(160.0, $m['distance']->current);
        self::assertSame(107, $m['distance']->percent());
        self::assertSame(100, $m['distance']->barPercent());
        self::assertTrue($m['distance']->isReached());
        self::assertSame(0.0, $m['distance']->remaining());
        self::assertSame(310, $m['duration']->current, '18 600 s = 310 min');
        self::assertSame(100, $m['duration']->barPercent());
        self::assertSame(0, $m['duration']->remaining());
        self::assertTrue($progress[1]->isReached());
    }

    public function testSportWithoutActivityShowsZeroProgress(): void
    {
        $progress = WeeklyGoalProgress::build([(new WeeklyGoal())->setSport(self::sport(4, 'swim'))->setSessions(2)->setElevationGainM(100)], []);

        $m = self::metricsByKind($progress[0]);
        self::assertSame(0, $m['sessions']->current);
        self::assertSame(0, $m['sessions']->barPercent());
        self::assertSame(2, $m['sessions']->remaining());
        self::assertSame(0, $m['elevation']->current);
        self::assertSame(100, $m['elevation']->remaining());
        self::assertFalse($progress[0]->isReached());
    }

    public function testCurrentValuesAreRoundedLikeTheDisplay(): void
    {
        $goal = (new WeeklyGoal())->setSport(self::sport(2, 'trail'))->setDistanceKm(30)->setElevationGainM(1000)->setDurationMinutes(120);
        $m = self::metricsByKind(WeeklyGoalProgress::build([$goal], [2 => self::totals(29960.0, 1, 7170, 619.6)])[0]);

        // 29,96 km s'affiche "30" : atteint, rien à faire, barre pleine
        self::assertSame(30.0, $m['distance']->current);
        self::assertTrue($m['distance']->isReached());
        self::assertSame(100, $m['distance']->barPercent());
        self::assertSame(620, $m['elevation']->current);
        self::assertSame(380, $m['elevation']->remaining());
        self::assertSame(120, $m['duration']->current, '7 170 s arrondi à 120 min');
        // Presque atteint : barre jamais pleine tant que la cible n'est pas atteinte
        $almost = new GoalMetric(GoalMetric::DISTANCE, 29.9, 30.0);
        self::assertSame(99, $almost->barPercent());
        self::assertSame(100, $almost->percent());
        self::assertSame(0.1, $almost->remaining());
    }

    public function testOrderKeptAndOtherIncludesActivitiesWithoutSport(): void
    {
        $other = self::sport(8, 'other');
        $run   = self::sport(1, 'run');
        $goals = [
            (new WeeklyGoal())->setSport($other)->setSessions(3),
            (new WeeklyGoal())->setSport($run)->setSessions(1),
        ];
        $progress = WeeklyGoalProgress::build($goals, [
            8 => self::totals(0.0, 1),
            0 => self::totals(0.0, 1),
            1 => self::totals(5000.0, 1),
        ]);

        self::assertSame(['other', 'run'], array_map(static fn (SportGoalProgress $p): string => $p->sport->getSlug(), $progress));
        self::assertSame(2, $progress[0]->metrics[0]->current, '"Autre" compte aussi les activités sans sport');
        self::assertSame(1, $progress[1]->metrics[0]->current);
    }

    public function testGoalWithoutTargetOrSportIsSkipped(): void
    {
        self::assertSame([], WeeklyGoalProgress::build([
            (new WeeklyGoal())->setSport(self::sport(1, 'run')),
            (new WeeklyGoal())->setDistanceKm(10),
        ], []));
    }

    // ── Service ────────────────────────────────────────────────────────

    public function testForCurrentWeekQueriesTheWeekOfTheClock(): void
    {
        $user = new User();
        $goal = (new WeeklyGoal())->setSport(self::sport(1, 'run'))->setDistanceKm(20);

        $goals = $this->createMock(WeeklyGoalRepository::class);
        $goals->expects(self::once())->method('findForUser')->with($user)->willReturn([$goal]);
        $activities = $this->createMock(ActivityRepository::class);
        $activities->expects(self::once())->method('sumBySportBetween')
            ->with($user, self::callback(static fn (\DateTimeImmutable $d): bool => $d->format('Y-m-d H:i:s') === '2026-11-16 00:00:00'),
                self::callback(static fn (\DateTimeImmutable $d): bool => $d->format('Y-m-d H:i:s') === '2026-11-22 23:59:59'))
            ->willReturn([1 => self::totals(5000.0, 1)]);

        $service = new WeeklyGoalProgress($goals, $activities, new MockClock('2026-11-19 07:00:00', 'UTC'));
        $progress = $service->forCurrentWeek($user);

        self::assertCount(1, $progress);
        self::assertSame(5.0, $progress[0]->metrics[0]->current);
    }

    public function testNoGoalNoActivityQuery(): void
    {
        $goals = $this->createMock(WeeklyGoalRepository::class);
        $goals->method('findForUser')->willReturn([]);
        $activities = $this->createMock(ActivityRepository::class);
        $activities->expects(self::never())->method('sumBySportBetween');

        self::assertSame([], (new WeeklyGoalProgress($goals, $activities, new MockClock()))->forCurrentWeek(new User()));
    }
}
