<?php

namespace App\Controller\Stats;

use App\Repository\ActivityRepository;
use App\Repository\SportRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class StatsController extends AbstractController
{
    #[Route('/stats', name: 'app_stats')]
    public function index(Request $request, ActivityRepository $activityRepo, SportRepository $sportRepo): Response
    {
        $user   = $this->getUser();
        $period = $request->query->get('period', 'season');
        $now    = new \DateTimeImmutable();

        $from = match ($period) {
            'month' => new \DateTimeImmutable($now->format('Y-m-01')),
            'all'   => new \DateTimeImmutable('2000-01-01'),
            default => new \DateTimeImmutable($now->format('Y') . '-01-01'),
        };

        $totals     = $activityRepo->getSeasonStats($user, $from, $now);
        $rawMonthly = $activityRepo->getMonthlyVolumeBySport($user, $from, $now);
        $sportDist  = $activityRepo->getSportDistribution($user, $from, $now);
        $weeklyPace = $activityRepo->getWeeklyAvgPaceForSport($user, 'run', $now->modify('-12 weeks'));
        $hrStats    = $activityRepo->getWeightedHrStats($user, $from, $now);
        $records    = $activityRepo->getPersonalRecords($user);

        // Shape monthly chart data for Chart.js
        $chartVolume = $this->shapeMonthlyChart($rawMonthly);

        // Shape pace data
        $chartPace = array_map(static fn($r) => [
            'week'    => 'S' . $r['wk'],
            'paceSec' => (int)$r['avg_pace_sec'],
        ], $weeklyPace);

        // Compute zone distribution from weighted avg HR
        $zones = null;
        if (!empty($hrStats['weighted_avg_hr']) && !empty($hrStats['max_hr'])) {
            $pct   = (float)$hrStats['weighted_avg_hr'] / (float)$hrStats['max_hr'];
            $zones = match (true) {
                $pct < 0.60 => [50, 30, 12, 6, 2],
                $pct < 0.70 => [14, 52, 24, 8, 2],
                $pct < 0.80 => [5, 22, 52, 17, 4],
                $pct < 0.90 => [4, 18, 45, 26, 7],
                default     => [2, 8, 18, 42, 30],
            };
        }

        return $this->render('stats/index.html.twig', [
            'period'      => $period,
            'year'        => (int)$now->format('Y'),
            'totals'      => $totals,
            'chartVolume' => $chartVolume,
            'donutData'   => $sportDist,
            'chartPace'   => $chartPace,
            'zones'       => $zones,
            'records'     => $records,
        ]);
    }

    private function shapeMonthlyChart(array $raw): array
    {
        if (empty($raw)) {
            return ['months' => [], 'datasets' => []];
        }

        // Collect ordered unique months and sports
        $monthKeys = [];
        $sports    = [];
        foreach ($raw as $row) {
            $key = sprintf('%04d-%02d', $row['yr'], $row['mo']);
            $monthKeys[$key] = ['yr' => (int)$row['yr'], 'mo' => (int)$row['mo']];
            $slug = $row['sport'];
            if (!isset($sports[$slug])) {
                $sports[$slug] = ['slug' => $slug, 'color' => $row['color'], 'data' => []];
            }
        }

        ksort($monthKeys);
        $sortedMonths = array_values($monthKeys);

        // Index raw data by month key → sport
        $indexed = [];
        foreach ($raw as $row) {
            $key = sprintf('%04d-%02d', $row['yr'], $row['mo']);
            $indexed[$key][$row['sport']] = (float)$row['km'];
        }

        foreach ($sports as $slug => &$sport) {
            $sport['data'] = array_map(
                static fn($m) => round($indexed[sprintf('%04d-%02d', $m['yr'], $m['mo'])][$slug] ?? 0, 1),
                $sortedMonths
            );
        }

        return ['months' => $sortedMonths, 'datasets' => array_values($sports)];
    }
}
