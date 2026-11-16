<?php

namespace App\Controller\Dashboard;

use App\Entity\User;
use App\Repository\ActivityRepository;
use App\Repository\RaceRepository;
use App\Service\Gear\GearUsageProvider;
use App\Service\Goals\WeeklyGoalProgress;
use App\Service\Goals\WeeklyVolumeSeries;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    private const WEEKS = 12;

    #[Route('/', name: 'app_dashboard')]
    public function index(
        ActivityRepository $activityRepo,
        RaceRepository $raceRepo,
        GearUsageProvider $gearUsage,
        WeeklyGoalProgress $goalProgress,
        ClockInterface $clock,
        #[CurrentUser] User $user,
    ): Response {
        $recentActivities = $activityRepo->findByUserPaginated($user, 1, 10);
        // 12 semaines ISO consécutives jusqu'à la semaine EN COURS (dernier élément), semaines vides à zéro :
        // anneaux et tuiles portent sur la semaine en cours, comme les objectifs par sport
        $now = $clock->now();
        $weeklyVolume = WeeklyVolumeSeries::fill(
            $activityRepo->findWeeklyVolume($user, WeeklyVolumeSeries::since($now, self::WEEKS)),
            $now,
            self::WEEKS,
        );

        // Compte à rebours "J-xxx" vers la prochaine course où l'on est inscrit (rien si aucune)
        $nextRace = $raceRepo->findNextRegistered($user);
        $nextRaceDays = $nextRace?->getRaceDate()
            ? (int) (new \DateTimeImmutable('today'))->diff($nextRace->getRaceDate())->format('%r%a')
            : null;

        $profile = $user->getProfile();

        return $this->render('dashboard/index.html.twig', [
            'recentActivities' => array_map(fn ($a) => [
                'id'            => $a->getId(),
                'name'          => $a->getName(),
                'type'          => $a->getType(),
                'distanceKm'    => $a->getDistanceKm(),
                'movingTime'    => $a->getMovingTimeSeconds(),
                'elevationGain' => $a->getTotalElevationGain(),
                'pace'          => $a->getPaceMinPerKm(),
                'speedKmh'      => $a->getSpeedKmh(),
                'startDate'     => $a->getStartDate(),
            ], $recentActivities),
            'weeklyVolume'    => $weeklyVolume,
            'stravaConnected' => $user->isStravaConnected(),
            'isPro'           => $user->isPro(),
            'nextRace'        => $nextRace,
            'nextRaceDays'    => $nextRaceDays,
            // Matériel bientôt usé ou usé (bandeau d'alerte, rien si tout va bien)
            'gearAlerts'      => $gearUsage->wearAlerts($user),
            'weeklyGoals'     => [
                'distanceKm' => $profile?->getWeeklyDistanceGoalKm() ?? 150,
                'sessions'   => $profile?->getWeeklySessionsGoal() ?? 7,
            ],
            // Objectifs par sport, semaine en cours (lundi -> dimanche), dans l'ordre des paramètres
            'sportGoals'      => $goalProgress->forCurrentWeek($user),
        ]);
    }
}
