<?php

namespace App\Service\Gear;

use App\Entity\Gear;
use App\Entity\User;
use App\Repository\GearRepository;

/** Calcule l'usage / l'usure de tout le matériel d'un utilisateur (une requête agrégée). */
class GearUsageProvider
{
    public function __construct(private readonly GearRepository $gearRepo)
    {
    }

    /**
     * @param list<Gear> $gears Matériel de l'utilisateur (déjà chargé).
     *
     * @return array<int, GearUsage> Indexé par id de matériel.
     */
    public function forUser(User $user, array $gears): array
    {
        $rows  = $this->gearRepo->getUsageByGear($user);
        $usage = [];
        foreach ($gears as $gear) {
            $id = (int) $gear->getId();
            $usage[$id] = self::build($gear, $rows[$id] ?? null);
        }

        return $usage;
    }

    /**
     * Alertes d'usure du tableau de bord : matériel en service à 80 % ('warn') ou 100 % ('worn')
     * de sa durée de vie, le plus usé d'abord (une seule requête agrégée).
     *
     * @return list<array{gear: Gear, usage: GearUsage}>
     */
    public function wearAlerts(User $user): array
    {
        $alerts = [];
        foreach ($this->gearRepo->findActiveTrackedWithUsage($user) as $row) {
            $usage = self::build($row['gear'], $row['usage']);
            if (in_array($usage->status(), [GearUsage::STATUS_WARN, GearUsage::STATUS_WORN], true)) {
                $alerts[] = ['gear' => $row['gear'], 'usage' => $usage];
            }
        }

        usort($alerts, static fn (array $a, array $b): int => ($b['usage']->wearRatio() <=> $a['usage']->wearRatio())
            ?: strnatcasecmp($a['gear']->getDisplayName(), $b['gear']->getDisplayName()));

        return $alerts;
    }

    /**
     * @param array{activitiesCount: int, distanceM: float, movingSeconds: int, lastUsedAt: ?\DateTimeImmutable}|null $row
     */
    public static function build(Gear $gear, ?array $row): GearUsage
    {
        return new GearUsage(
            activitiesCount: $row['activitiesCount'] ?? 0,
            distanceM: $row['distanceM'] ?? 0.0,
            movingSeconds: $row['movingSeconds'] ?? 0,
            lastUsedAt: $row['lastUsedAt'] ?? null,
            initialDistanceM: $gear->getInitialDistanceM(),
            maxDistanceM: $gear->getMaxDistanceM(),
        );
    }
}
