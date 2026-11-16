<?php

namespace App\Service\Notifications;

use App\Entity\GearType;
use App\Entity\User;
use App\Repository\ActivityRepository;
use App\Repository\GearRepository;
use App\Repository\RaceRepository;
use App\Service\Gear\GearUsageProvider;
use App\Service\Goals\WeeklyGoalProgress;
use Psr\Clock\ClockInterface;

/**
 * Rassemble les données de la liste « À faire » d'un utilisateur, puis la fait calculer par TodoBuilder.
 * Données de l'utilisateur seulement, aucun appel externe (ni Strava, ni météo), requêtes agrégées sans N+1 :
 *  1. courses inscrites de J0 à J+21 avec l'état de leur plan (RaceRepository::findTodoRows) ;
 *  2. activités des 14 derniers jours, matériel et ravitaillement compris (ActivityRepository::findRecentTodoRows) ;
 *  3. types du matériel non retiré (GearRepository::findActiveTypes), seulement s'il y a une activité récente
 *     sans matériel d'un sport qui en reçoit ;
 *  4. alertes d'usure (GearUsageProvider::wearAlerts) ;
 *  5-6. objectifs par sport, puis leurs totaux de la semaine s'il y en a (WeeklyGoalProgress).
 * L'état Strava est lu sur l'utilisateur déjà chargé.
 */
final class TodoProvider
{
    public function __construct(
        private readonly RaceRepository $races,
        private readonly ActivityRepository $activities,
        private readonly GearRepository $gear,
        private readonly GearUsageProvider $gearUsage,
        private readonly WeeklyGoalProgress $goalProgress,
        private readonly TodoBuilder $builder,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return list<TodoItem> */
    public function forUser(User $user): array
    {
        $now = $this->clock->now();
        $today = $now->setTime(0, 0);

        $recent = $this->activities->findRecentTodoRows($user, $today->modify('-'.(TodoBuilder::RECENT_DAYS - 1).' days'));
        $needsGearTypes = false;
        foreach ($recent as $activity) {
            if (!$activity->hasGear && GearType::forSportSlug($activity->sportSlug) !== null) {
                $needsGearTypes = true;
                break;
            }
        }

        return $this->builder->build(new TodoInput(
            races: $this->races->findTodoRows($user, $today, $today->modify('+'.TodoBuilder::RACE_PLAN_DAYS.' days')),
            wearAlerts: $this->gearUsage->wearAlerts($user),
            activeGearTypes: $needsGearTypes ? $this->gear->findActiveTypes($user) : [],
            recentActivities: $recent,
            goals: $this->goalProgress->forCurrentWeek($user),
            strava: StravaState::fromUser($user),
        ), $now);
    }
}
