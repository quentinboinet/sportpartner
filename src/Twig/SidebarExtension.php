<?php

namespace App\Twig;

use App\Entity\User;
use App\Repository\ActivityRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class SidebarExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly ActivityRepository $activityRepo,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('sidebar_today_stats', $this->todayStats(...)),
        ];
    }

    public function todayStats(): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return ['distanceKm' => null, 'tss' => null];
        }

        $rows = $this->activityRepo->findTodayStatsForSidebar($user);
        if (!$rows) {
            return ['distanceKm' => null, 'tss' => null];
        }

        $profile = $user->getProfile();
        $maxHr     = $profile?->getMaxHr();
        $restingHr = $profile?->getRestingHr() ?? 60;

        $totalDistanceM = 0.0;
        $totalTss       = 0.0;
        $hasTss         = false;

        foreach ($rows as $row) {
            $totalDistanceM += (float) ($row['distanceMeters'] ?? 0);

            $durationMin = (float) ($row['movingTimeSeconds'] ?? 0) / 60;
            $avgHr       = isset($row['averageHeartrate']) ? (float) $row['averageHeartrate'] : null;

            if ($durationMin <= 0) {
                continue;
            }

            if ($avgHr && $maxHr && $maxHr > $restingHr) {
                // Banister TRIMP — standard HR-based training load approximation
                $hrr = max(0.0, min(1.0, ($avgHr - $restingHr) / ($maxHr - $restingHr)));
                $totalTss += $durationMin * $hrr * 0.64 * exp(1.92 * $hrr);
                $hasTss = true;
            }
        }

        return [
            'distanceKm' => $totalDistanceM > 0 ? round($totalDistanceM / 1000, 1) : null,
            'tss'        => $hasTss ? (int) round($totalTss) : null,
        ];
    }
}
