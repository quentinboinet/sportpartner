<?php

namespace App\Controller\Stats;

use App\Entity\User;
use App\Repository\ActivityRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
class StatsController extends AbstractController
{
    #[Route('/stats', name: 'app_stats')]
    public function index(Request $request, ActivityRepository $activityRepo, TranslatorInterface $translator, #[CurrentUser] User $user): Response
    {
        $now  = new \DateTimeImmutable();
        $to   = $now;

        $fromParam = $request->query->get('from');
        $toParam   = $request->query->get('to');

        // Preset period (default); overridden below when a valid custom range is given.
        $period = $request->query->get('period', 'season');
        $from   = match ($period) {
            '7d'    => new \DateTimeImmutable('-7 days midnight'),
            'month' => new \DateTimeImmutable($now->format('Y-m-01')),
            'all'   => new \DateTimeImmutable('2000-01-01'),
            default => new \DateTimeImmutable($now->format('Y') . '-01-01'),
        };
        $granularity = match ($period) {
            '7d', 'month' => 'day',
            default       => 'month',
        };

        if ($fromParam && $toParam) {
            try {
                $customFrom  = new \DateTimeImmutable($fromParam . ' midnight');
                $customTo    = (new \DateTimeImmutable($toParam))->setTime(23, 59, 59);
                $days        = (int) $customFrom->diff($customTo)->days;
                $from        = $customFrom;
                $to          = $customTo;
                $granularity = $days <= 31 ? 'day' : ($days <= 120 ? 'week' : 'month');
                $period      = 'custom';
            } catch (\Throwable) {
                $fromParam = $toParam = null;
            }
        }

        $rawVolume = match ($granularity) {
            'day'   => $activityRepo->getDailyVolumeBySport($user, $from, $to),
            'week'  => $activityRepo->getWeeklyVolumeBySport($user, $from, $to),
            default => $activityRepo->getMonthlyVolumeBySport($user, $from, $to),
        };

        $totals     = $activityRepo->getSeasonStats($user, $from, $to);
        $sportDist  = $activityRepo->getSportDistribution($user, $from, $to);
        $weeklyPace = $activityRepo->getWeeklyAvgPaceForSport($user, 'run', $now->modify('-12 weeks'));
        $hrStats    = $activityRepo->getWeightedHrStats($user, $from, $to);
        $records    = $activityRepo->getPersonalRecords($user);

        $chartVolume = $this->shapeVolumeChart($rawVolume, $granularity, $from, $to);

        // Libellés traduits pour les graphiques (Chart.js affiche "label", pas le slug)
        $sportLabel = static fn (mixed $slug): string => $translator->trans('sport.'.$slug);
        foreach ($chartVolume['datasets'] as $i => $dataset) {
            $chartVolume['datasets'][$i]['label'] = $sportLabel($dataset['slug']);
        }
        foreach ($sportDist as $i => $row) {
            $sportDist[$i]['label'] = $sportLabel($row['slug']);
        }

        $chartPace = array_map(static fn($r) => [
            'week'    => 'S' . $r['wk'],
            'paceSec' => (int)$r['avg_pace_sec'],
        ], $weeklyPace);

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
            'customFrom'  => $fromParam ?? null,
            'customTo'    => $toParam   ?? null,
            'year'        => (int)$now->format('Y'),
            'granularity' => $granularity,
            'totals'      => $totals,
            'chartVolume' => $chartVolume,
            'donutData'   => $sportDist,
            'chartPace'   => $chartPace,
            'zones'       => $zones,
            'records'     => $records,
        ]);
    }

    /**
     * Shapes raw volume rows into { labels: string[], datasets: [...] }.
     * Fills every bucket (day / week / month) in the range with 0 when no data.
     *
     * @param array<int, array<string, mixed>> $raw
     *
     * @return array{labels: list<string>, granularity: string, datasets: list<array{slug: mixed, color: mixed, data: list<float>, label?: string}>}
     */
    private function shapeVolumeChart(array $raw, string $granularity, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        // ── Build ordered bucket list ─────────────────────────────────────────
        $buckets = [];   // key => label string

        if ($granularity === 'day') {
            $day = $from;
            while ($day <= $to) {
                $buckets[$day->format('Y-m-d')] = $day->format('d/m');
                $day = $day->modify('+1 day');
            }
        } elseif ($granularity === 'week') {
            // Align to Monday of the week containing $from
            $dow   = (int) $from->format('N');           // 1 = Mon
            $start = $from->modify('-' . ($dow - 1) . ' days');
            $week  = $start;
            while ($week <= $to) {
                $key            = $week->format('Y-m-d');
                $buckets[$key]  = 'S' . $week->format('W');
                $week = $week->modify('+7 days');
            }
        } else {
            // Monthly
            $month = new \DateTimeImmutable($from->format('Y-m-01'));
            while ($month <= $to) {
                $key           = $month->format('Y-m');
                $shortMonth    = ['Jan','Fév','Mar','Avr','Mai','Jun','Jul','Aoû','Sep','Oct','Nov','Déc'][(int)$month->format('n') - 1];
                $buckets[$key] = $from->format('Y') !== $to->format('Y')
                    ? $shortMonth . ' ' . $month->format('y')
                    : $shortMonth;
                $month = $month->modify('+1 month');
            }
        }

        if (empty($buckets)) {
            return ['labels' => [], 'granularity' => $granularity, 'datasets' => []];
        }

        // ── Collect sports and index raw data ─────────────────────────────────
        $sports  = [];
        $indexed = [];   // bucket_key → sport_slug → km

        foreach ($raw as $row) {
            $slug = $row['sport'];
            if (!isset($sports[$slug])) {
                $sports[$slug] = ['slug' => $slug, 'color' => $row['color']];
            }
            $key = match ($granularity) {
                'day'   => $row['day'],
                'week'  => $row['week_start'],
                default => substr($row['yr'] . '-' . str_pad($row['mo'], 2, '0', STR_PAD_LEFT), 0, 7),
            };
            $indexed[$key][$slug] = (float) $row['km'];
        }

        // ── Build per-sport ordered data arrays ───────────────────────────────
        $datasets = [];
        foreach ($sports as $slug => $meta) {
            $data = [];
            foreach (array_keys($buckets) as $bk) {
                $data[] = round($indexed[$bk][$slug] ?? 0, 1);
            }
            $datasets[] = ['slug' => $slug, 'color' => $meta['color'], 'data' => $data];
        }

        return [
            'labels'      => array_values($buckets),
            'granularity' => $granularity,
            'datasets'    => $datasets,
        ];
    }
}
