<?php

namespace App\Service\Notifications;

use App\Entity\Gear;
use App\Entity\GearType;
use App\Service\Gear\GearUsage;
use App\Service\Goals\SportGoalProgress;

/** Données de l'utilisateur dont TodoBuilder tire la liste « À faire » (rassemblées par TodoProvider). */
final class TodoInput
{
    /**
     * @param list<RaceTodoRow>                           $races            courses inscrites datées de J0 à J+21, non faites
     * @param list<array{gear: Gear, usage: GearUsage}>   $wearAlerts       GearUsageProvider::wearAlerts() (le plus usé d'abord)
     * @param list<GearType>                              $activeGearTypes  types du matériel non retiré
     * @param list<RecentActivityRow>                     $recentActivities activités des 14 derniers jours, la plus récente d'abord
     * @param list<SportGoalProgress>                     $goals            WeeklyGoalProgress::forCurrentWeek()
     */
    public function __construct(
        public readonly array $races = [],
        public readonly array $wearAlerts = [],
        public readonly array $activeGearTypes = [],
        public readonly array $recentActivities = [],
        public readonly array $goals = [],
        public readonly StravaState $strava = new StravaState(connected: true),
    ) {
    }
}
