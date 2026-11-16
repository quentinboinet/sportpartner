<?php

namespace App\Service\Goals;

use App\Entity\User;
use App\Entity\WeeklyGoal;
use App\Repository\ActivityRepository;
use App\Repository\WeeklyGoalRepository;
use Psr\Clock\ClockInterface;

/**
 * Avancement des objectifs par sport sur la semaine en cours (carte "Objectifs de la semaine" du
 * tableau de bord) : les objectifs, puis une seule requête agrégée par sport pour la semaine.
 *
 * Semaine = lundi 00:00:00 -> dimanche 23:59:59 dans le fuseau par défaut de PHP, comparée aux
 * startDate enregistrées : les mêmes bornes que YEARWEEK(startDate, 1) (findWeeklyVolume) et que
 * 'monday this week' / 'sunday this week' du libellé de semaine du tableau de bord.
 */
final class WeeklyGoalProgress
{
    public function __construct(
        private readonly WeeklyGoalRepository $goals,
        private readonly ActivityRepository $activities,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return list<SportGoalProgress> dans l'ordre des paramètres */
    public function forCurrentWeek(User $user): array
    {
        $goals = $this->goals->findForUser($user);
        if ($goals === []) {
            return [];
        }

        [$from, $to] = self::weekBounds($this->clock->now());

        return self::build($goals, $this->activities->sumBySportBetween($user, $from, $to));
    }

    /**
     * Lundi 00:00:00 et dimanche 23:59:59 de la semaine (ISO) de $now, dans le fuseau de $now.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public static function weekBounds(\DateTimeImmutable $now): array
    {
        $monday = $now->setTime(0, 0)->modify('-'.((int) $now->format('N') - 1).' days');

        return [$monday, $monday->modify('+6 days')->setTime(23, 59, 59)];
    }

    /**
     * @param list<WeeklyGoal>                                                                         $goals
     * @param array<int, array{distanceM: float, sessions: int, movingSeconds: int, elevationM: float}> $totals par id de sport, 0 = activités sans sport (comptées avec "other")
     *
     * @return list<SportGoalProgress>
     */
    public static function build(array $goals, array $totals): array
    {
        $progress = [];
        foreach ($goals as $goal) {
            $sport = $goal->getSport();
            if ($sport === null) {
                continue;
            }

            $week = $totals[(int) $sport->getId()] ?? null;
            if ($sport->getSlug() === 'other' && isset($totals[0])) {
                $week = self::sum($week, $totals[0]);
            }
            $week ??= ['distanceM' => 0.0, 'sessions' => 0, 'movingSeconds' => 0, 'elevationM' => 0.0];

            // Réalisé arrondi comme il est affiché : "atteint" et "encore ..." restent cohérents avec le texte
            $metrics = [];
            if ($goal->getDistanceKm() !== null) {
                $metrics[] = new GoalMetric(GoalMetric::DISTANCE, round($week['distanceM'] / 1000, 1), $goal->getDistanceKm());
            }
            if ($goal->getSessions() !== null) {
                $metrics[] = new GoalMetric(GoalMetric::SESSIONS, $week['sessions'], $goal->getSessions());
            }
            if ($goal->getDurationMinutes() !== null) {
                $metrics[] = new GoalMetric(GoalMetric::DURATION, (int) round($week['movingSeconds'] / 60), $goal->getDurationMinutes());
            }
            if ($goal->getElevationGainM() !== null) {
                $metrics[] = new GoalMetric(GoalMetric::ELEVATION, (int) round($week['elevationM']), $goal->getElevationGainM());
            }

            if ($metrics !== []) {
                $progress[] = new SportGoalProgress($sport, $metrics);
            }
        }

        return $progress;
    }

    /**
     * @param array{distanceM: float, sessions: int, movingSeconds: int, elevationM: float}|null $a
     * @param array{distanceM: float, sessions: int, movingSeconds: int, elevationM: float}      $b
     *
     * @return array{distanceM: float, sessions: int, movingSeconds: int, elevationM: float}
     */
    private static function sum(?array $a, array $b): array
    {
        if ($a === null) {
            return $b;
        }

        return [
            'distanceM'     => $a['distanceM'] + $b['distanceM'],
            'sessions'      => $a['sessions'] + $b['sessions'],
            'movingSeconds' => $a['movingSeconds'] + $b['movingSeconds'],
            'elevationM'    => $a['elevationM'] + $b['elevationM'],
        ];
    }
}
