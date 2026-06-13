<?php

namespace App\Controller\Activities;

use App\Repository\ActivityRepository;
use App\Repository\SportRepository;
use App\Service\Strava\StravaService;
use App\Service\Weather\WeatherService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class ActivitiesController extends AbstractController
{
    #[Route('/activities/{id}', name: 'app_activity_show', requirements: ['id' => '\d+'])]
    public function show(int $id, Request $request, ActivityRepository $activityRepo, StravaService $strava, WeatherService $weather): Response
    {
        $activity = $activityRepo->find($id);

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        if (!$activity || $activity->getUser()?->getId() !== $user->getId()) {
            throw $this->createNotFoundException();
        }

        if ($user->isStravaConnected()) {
            if ($activity->getElevationProfile() === null) {
                $strava->fetchAltitudeStream($user, $activity);
            }
            if ($activity->getSplitsMetric() === null) {
                $strava->fetchSplitsMetric($user, $activity);
            }
            if ($activity->getHeartrateStream() === null) {
                $strava->fetchHeartrateStream($user, $activity);
            }
        }

        // Pace splits: array of seconds/km derived from splits_metric.average_speed (m/s)
        $splits = [];
        $splitsRaw = $activity->getSplitsMetric() ? json_decode($activity->getSplitsMetric(), true) : [];
        foreach ($splitsRaw as $split) {
            $speed = (float) ($split['average_speed'] ?? 0);
            if ($speed > 0) {
                $splits[] = (int) round(1000 / $speed);
            }
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

        $hrData = $activity->getHeartrateStream()
            ? json_decode($activity->getHeartrateStream(), true)
            : [];

        // Lazy-fetch weather: decode polyline first point, fetch once from Open-Meteo, store
        $wxDisplay = null;
        if ($activity->getWeatherData() === null && $activity->getSummaryPolyline()) {
            [$lat, $lon] = $this->decodePolylineFirstPoint($activity->getSummaryPolyline());
            $wxRaw      = $weather->fetchAndStore($activity, $lat, $lon);
            $wxDisplay  = $weather->getDisplay($wxRaw, $request->getLocale());
        } elseif ($activity->getWeatherData() && $activity->getWeatherData() !== '{}') {
            $wxRaw     = json_decode($activity->getWeatherData(), true);
            $wxDisplay = $weather->getDisplay($wxRaw, $request->getLocale());
        }

        return $this->render('activities/show.html.twig', [
            'activity'  => $activity,
            'zones'     => $zones,
            'splits'    => $splits,
            'hrData'    => $hrData ?? [],
            'wxDisplay' => $wxDisplay,
        ]);
    }

    /** @return array{float, float} */
    private function decodePolylineFirstPoint(string $encoded): array
    {
        $coords = [0, 0];
        $i      = 0;
        foreach ([0, 1] as $c) {
            $shift = $result = 0;
            do {
                $b      = ord($encoded[$i++]) - 63;
                $result |= ($b & 0x1f) << $shift;
                $shift  += 5;
            } while ($b >= 0x20 && $i < strlen($encoded));
            $coords[$c] = (($result & 1) ? ~($result >> 1) : ($result >> 1)) / 1e5;
        }
        return $coords;
    }

    #[Route('/activities', name: 'app_activities')]
    public function index(Request $request, ActivityRepository $activityRepo, SportRepository $sportRepo): Response
    {
        $user       = $this->getUser();
        $filterSlug = $request->query->get('sport');
        $sport      = $filterSlug ? $sportRepo->findBySlug($filterSlug) : null;
        $perPage    = 20;
        $page       = max(1, (int) $request->query->get('page', 1));

        $period = $request->query->get('period');
        $since  = match ($period) {
            '7d'  => new \DateTimeImmutable('-7 days midnight'),
            '30d' => new \DateTimeImmutable('-30 days midnight'),
            default => null,
        };

        $raw        = $activityRepo->getAggregateStats($user, $sport?->getSlug(), $since);
        $totalCount = (int) ($raw['count'] ?? 0);
        $totalPages = max(1, (int) ceil($totalCount / $perPage));
        $page       = min($page, $totalPages);

        $activities = $activityRepo->findByUserFiltered($user, $sport?->getSlug(), $page, $perPage, $since);
        $sports     = $sportRepo->findAllOrdered();

        $totalKm       = $raw['totalDistance'] ? round($raw['totalDistance'] / 1000, 1) : 0;
        $totalTSS      = $raw['totalTime']     ? (int) round($raw['totalTime'] / 3600 * 60) : 0;
        $totalCalories = $raw['totalDistance'] ? (int) round($raw['totalDistance'] / 1000 * 50) : 0;

        // Page window : always show first, last, and ±2 around current.
        $window     = range(max(1, $page - 2), min($totalPages, $page + 2));
        $showFirst  = !in_array(1, $window, true);
        $showLast   = !in_array($totalPages, $window, true);
        $dotsBefore = $showFirst && (min($window) > 2);
        $dotsAfter  = $showLast  && (max($window) < $totalPages - 1);

        return $this->render('activities/index.html.twig', [
            'activities'    => $activities,
            'sports'        => $sports,
            'currentSport'  => $sport,
            'currentPeriod' => $period,
            'count'         => $totalCount,
            'totalKm'       => $totalKm,
            'totalTSS'      => $totalTSS,
            'totalCalories' => $totalCalories,
            'page'          => $page,
            'totalPages'    => $totalPages,
            'window'        => $window,
            'showFirst'     => $showFirst,
            'showLast'      => $showLast,
            'dotsBefore'    => $dotsBefore,
            'dotsAfter'     => $dotsAfter,
        ]);
    }
}
