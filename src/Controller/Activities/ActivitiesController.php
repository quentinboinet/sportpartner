<?php

namespace App\Controller\Activities;

use App\Repository\ActivityRepository;
use App\Repository\SportRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class ActivitiesController extends AbstractController
{
    #[Route('/activities/{id}', name: 'app_activity_show', requirements: ['id' => '\d+'])]
    public function show(int $id, ActivityRepository $activityRepo): Response
    {
        $activity = $activityRepo->find($id);

        if (!$activity || $activity->getUser()?->getId() !== $this->getUser()->getId()) {
            throw $this->createNotFoundException();
        }

        $zones = null;
        $avgHr = $activity->getAverageHeartrate();
        $maxHr = $activity->getMaxHeartrate();
        if ($avgHr && $maxHr && $maxHr > 0) {
            $pct   = $avgHr / $maxHr;
            $zones = match (true) {
                $pct < 0.60 => [50, 30, 12, 6, 2],
                $pct < 0.70 => [14, 52, 24, 8, 2],
                $pct < 0.80 => [5, 22, 52, 17, 4],
                $pct < 0.90 => [4, 18, 45, 26, 7],
                default     => [2, 8, 18, 42, 30],
            };
        }

        return $this->render('activities/show.html.twig', [
            'activity' => $activity,
            'zones'    => $zones,
        ]);
    }

    #[Route('/activities', name: 'app_activities')]
    public function index(Request $request, ActivityRepository $activityRepo, SportRepository $sportRepo): Response
    {
        $user        = $this->getUser();
        $filterSlug  = $request->query->get('sport');   // 'run', 'trail', 'ride', etc.
        $sport       = $filterSlug ? $sportRepo->findBySlug($filterSlug) : null;

        $activities  = $activityRepo->findByUserFiltered($user, $sport?->getSlug());
        $raw         = $activityRepo->getAggregateStats($user, $sport?->getSlug());
        $sports      = $sportRepo->findAllOrdered();

        $totalKm       = $raw['totalDistance'] ? round($raw['totalDistance'] / 1000, 1) : 0;
        $totalTSS      = $raw['totalTime']     ? (int) round($raw['totalTime'] / 3600 * 60) : 0;
        $totalCalories = $raw['totalDistance'] ? (int) round($raw['totalDistance'] / 1000 * 50) : 0;

        return $this->render('activities/index.html.twig', [
            'activities'    => $activities,
            'sports'        => $sports,
            'currentSport'  => $sport,
            'count'         => (int) ($raw['count'] ?? 0),
            'totalKm'       => $totalKm,
            'totalTSS'      => $totalTSS,
            'totalCalories' => $totalCalories,
        ]);
    }
}
