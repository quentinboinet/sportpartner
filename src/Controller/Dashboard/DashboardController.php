<?php

namespace App\Controller\Dashboard;

use App\Repository\ActivityRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard')]
    public function index(ActivityRepository $activityRepo): Response
    {
        $user = $this->getUser();

        $recentActivities = $activityRepo->findByUserPaginated($user, 1, 10);
        $weeklyVolume = $activityRepo->findWeeklyVolume($user, 12);

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
                'startDate'     => $a->getStartDate(),
            ], $recentActivities),
            'weeklyVolume'    => $weeklyVolume,
            'stravaConnected' => $user->isStravaConnected(),
            'isPro'           => $user->isPro(),
            'weeklyGoals'     => [
                'distanceKm' => $profile?->getWeeklyDistanceGoalKm() ?? 150,
                'sessions'   => $profile?->getWeeklySessionsGoal() ?? 7,
            ],
        ]);
    }
}
