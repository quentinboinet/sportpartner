<?php

namespace App\Controller\Dashboard;

use App\Repository\ActivityRepository;
use Rompetomp\InertiaBundle\Service\InertiaInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard')]
    public function index(
        ActivityRepository $activityRepo,
        InertiaInterface $inertia,
    ): Response {
        $user = $this->getUser();

        $recentActivities = $activityRepo->findByUserPaginated($user, 1, 10);
        $weeklyVolume = $activityRepo->findWeeklyVolume($user, 12);

        return $inertia->render('Dashboard/Index', [
            'recentActivities' => array_map(fn ($a) => [
                'id'            => $a->getId(),
                'name'          => $a->getName(),
                'type'          => $a->getType(),
                'distanceKm'    => $a->getDistanceKm(),
                'movingTime'    => $a->getMovingTimeSeconds(),
                'elevationGain' => $a->getTotalElevationGain(),
                'pace'          => $a->getPaceMinPerKm(),
                'startDate'     => $a->getStartDate()?->format('c'),
            ], $recentActivities),
            'weeklyVolume'     => $weeklyVolume,
            'stravaConnected'  => $user->isStravaConnected(),
            'isPro'            => $user->isPro(),
        ]);
    }
}
