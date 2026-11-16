<?php

namespace App\Service\Notifications;

use App\Entity\Gear;
use App\Entity\GearType;
use App\Service\Gear\GearUsage;
use App\Service\Goals\GoalMetric;
use App\Service\Goals\SportGoalProgress;
use App\Service\Nutrition\ActivityFuelCalculator;
use App\Service\Nutrition\FuelCatalog;
use App\Service\Weather\RaceWeatherService;

/**
 * Liste « À faire » de la cloche de la barre du haut. Calcul pur : ni base, ni API, ni horloge (« maintenant »
 * est un paramètre). Les jours sont des dates de calendrier dans le fuseau de $now (J0 = aujourd'hui).
 *
 * Courses (« Je suis inscrit », datées, non faites) : UN SEUL élément par course, jamais deux.
 *  - J0 « C'est le jour J : nom », J-1 « Demain : nom » : urgent, page de la course, quel que soit le plan ;
 *  - J-2 à J-15, plan prêt (un scénario avec un temps visé) : « nom dans N jours », info, page de la course ;
 *  - J-2 à J-15, plan incomplet : le compte à rebours et le rappel du plan sont fusionnés, attention, page du
 *    plan : « nom dans N jours, sans plan de course » (ni GPX, ni repère, ni scénario) ou « nom dans N jours :
 *    pas encore de temps de passage » (GPX, repères ou scénario, mais aucun temps visé) ;
 *  - J-16 à J-21 : seulement ces deux rappels du plan (info, page du plan) ; rien si le plan est prêt ;
 *  - sous-titre « Prévision météo du jour J disponible » de J-15 à J0 quand un lieu est connu (départ du GPX
 *    ou lieu saisi, comme RaceWeatherService) ; la prévision couvre J à J+15.
 *  Clé race:{id}:{countdown|noplan|nosplits}:{palier}, palier j21 (16-21 jours), j15 (8-15), j7 (2-7), j1, j0 :
 *  une alerte masquée revient au palier suivant ou quand l'état du plan change.
 *
 * Matériel :
 *  - usure (GearUsageProvider::wearAlerts(), mêmes seuils que le bandeau du tableau de bord) : gear:{id}:worn
 *    (urgent, « à remplacer » / « à réviser ») ou gear:{id}:warn (attention, « bientôt à remplacer » /
 *    « révision à prévoir ») ; lien vers la carte du matériel (onglet Matériel) ;
 *  - activités des 14 derniers jours sans matériel, d'un sport qui en reçoit automatiquement
 *    (GearType::sportSlugs() : chaussures pour course, trail, marche, rando ; vélo pour le vélo) quand
 *    l'utilisateur a du matériel non retiré de ce type. « Autre » et les activités sans sport ne comptent pas
 *    (tout matériel y est permis, mais rien n'y est attendu). Un seul élément « N activités récentes sans
 *    matériel » (info), lien vers la plus récente ; clé gear:missing:{id de la plus récente} : une nouvelle
 *    activité sans matériel fait revenir l'alerte.
 *
 * Objectifs par sport (semaine ISO en cours, WeeklyGoalProgress) :
 *  - toutes les cibles atteintes : « Objectif vélo atteint cette semaine », goal:{sport}:{2026-W47}:reached ;
 *  - le dimanche seulement, objectif non atteint : « Course : il te reste 12 km et 1 séance » (attention),
 *    goal:{sport}:{semaine}:left ; lien vers la carte des objectifs du tableau de bord ;
 *  - renfo (weight_training) avec une cible en séances et aucune séance faite, du jeudi au samedi :
 *    « 0 / 2 séances de renfo cette semaine » (info), health:strength:{semaine}, lien vers l'espace Santé ; le
 *    dimanche, le rappel « il te reste » ci-dessus prend le relais.
 *
 * Ravitaillement : activités des 14 derniers jours d'au moins 1h30 (temps en mouvement, sinon temps écoulé,
 * comme la carte), d'un sport qui a la carte Ravitaillement (FuelCatalog), sans aucune ligne enregistrée, hors
 * activités créées par une séance Santé (elles n'ont pas la carte). Les 3 plus récentes, une par activité (info),
 * clé fuel:activity:{id}, lien vers la carte de l'activité.
 *
 * Strava : compte non connecté « Connecte ton compte Strava » (strava:connect, en dernier) ; connecté mais jeton
 * expiré sans jeton de rafraîchissement « Reconnecte Strava » (strava:reconnect, attention), voir StravaState.
 *
 * Ordre : urgent, puis attention, puis info (les bonnes nouvelles avec les infos) ; à gravité égale, courses
 * (la plus proche d'abord), usure (la plus forte d'abord), reconnexion Strava, objectif à finir, renfo à faire,
 * ravitaillement, activités sans matériel, objectifs atteints, connexion Strava.
 */
final class TodoBuilder
{
    /** Compte à rebours de J-15 à J0 : la fenêtre des prévisions météo du jour J. */
    public const RACE_COUNTDOWN_DAYS = RaceWeatherService::FORECAST_DAYS;
    /** Rappel du plan de course jusqu'à J-21. */
    public const RACE_PLAN_DAYS = 21;
    /** Activités récentes : aujourd'hui et les 13 jours précédents. */
    public const RECENT_DAYS = 14;
    /** Ravitaillement : activités d'au moins 1h30. */
    public const FUEL_MIN_SECONDS = 5400;
    public const FUEL_MAX_ITEMS = 3;
    /** Rappel des séances de renfo (espace Santé) : sport concerné, et à partir du jeudi (ISO 4). */
    public const STRENGTH_SPORT_SLUG = 'weight_training';
    public const STRENGTH_REMINDER_FROM_DAY = 4;

    private const SEVERITY_RANK = [
        TodoItem::URGENT  => 0,
        TodoItem::WARNING => 1,
        TodoItem::INFO    => 2,
        TodoItem::SUCCESS => 2,
    ];

    private const CAT_RACE = 10;
    private const CAT_GEAR_WEAR = 20;
    private const CAT_STRAVA_RECONNECT = 30;
    private const CAT_GOAL_LEFT = 40;
    private const CAT_STRENGTH_REMINDER = 45;
    private const CAT_FUEL = 50;
    private const CAT_GEAR_MISSING = 60;
    private const CAT_GOAL_REACHED = 70;
    private const CAT_STRAVA_CONNECT = 90;

    /** @return list<TodoItem> dans l'ordre d'affichage */
    public function build(TodoInput $input, \DateTimeImmutable $now): array
    {
        $today = self::dayIndex($now);
        $recent = array_values(array_filter(
            $input->recentActivities,
            static fn (RecentActivityRow $a): bool => $today - self::dayIndex($a->startDate) < self::RECENT_DAYS,
        ));

        $ranked = [...self::races($input->races, $today), ...self::gearWear($input->wearAlerts)];
        if ($input->strava->needsReconnect($now)) {
            $ranked[] = self::ranked(self::CAT_STRAVA_RECONNECT, new TodoItem(
                'strava:reconnect', TodoItem::WARNING, 'bi-arrow-repeat',
                new TodoText('notifications.strava_reconnect'), new TodoText('notifications.strava_reconnect_sub'),
                'app_oauth_strava',
            ));
        }
        $ranked = [
            ...$ranked,
            ...self::goals($input->goals, $now),
            ...self::fuel($recent, $today),
            ...self::gearMissing($recent, $input->activeGearTypes),
        ];
        if (!$input->strava->connected) {
            $ranked[] = self::ranked(self::CAT_STRAVA_CONNECT, new TodoItem(
                'strava:connect', TodoItem::INFO, 'bi-strava',
                new TodoText('notifications.strava_connect'), new TodoText('notifications.strava_connect_sub'),
                'app_oauth_strava',
            ));
        }

        // Tri stable : à rang égal, l'ordre d'ajout (le plus proche, le plus usé, le plus récent d'abord) est gardé
        usort($ranked, static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);

        return array_map(static fn (array $r): TodoItem => $r['item'], $ranked);
    }

    /** Palier du compte à rebours (clé) : j0, j1, j7 (2-7 jours), j15 (8-15), j21 (16-21). */
    public static function raceBucket(int $days): string
    {
        return match (true) {
            $days <= 0  => 'j0',
            $days === 1 => 'j1',
            $days <= 7  => 'j7',
            $days <= 15 => 'j15',
            default     => 'j21',
        };
    }

    /** Numéro de jour de la date de calendrier de $date (dans son fuseau), insensible aux changements d'heure. */
    public static function dayIndex(\DateTimeImmutable $date): int
    {
        return intdiv((new \DateTimeImmutable($date->format('Y-m-d'), new \DateTimeZone('UTC')))->getTimestamp(), 86400);
    }

    /**
     * @param list<RaceTodoRow> $races
     *
     * @return list<array{rank: int, item: TodoItem}>
     */
    private static function races(array $races, int $today): array
    {
        usort($races, static fn (RaceTodoRow $a, RaceTodoRow $b): int => [self::dayIndex($a->date), $a->id] <=> [self::dayIndex($b->date), $b->id]);

        $out = [];
        foreach ($races as $race) {
            $days = self::dayIndex($race->date) - $today;
            if ($days < 0 || $days > self::RACE_PLAN_DAYS) {
                continue;
            }

            $bucket = self::raceBucket($days);
            $plan = $race->planState();
            $weather = $days <= self::RACE_COUNTDOWN_DAYS && $race->hasPlace() ? new TodoText('notifications.race_weather') : null;
            $showParams = ['id' => $race->id];

            if ($days <= 1) {
                $out[] = self::ranked(self::CAT_RACE, new TodoItem(
                    "race:{$race->id}:countdown:$bucket", TodoItem::URGENT, 'bi-flag-fill',
                    new TodoText($days === 0 ? 'notifications.race_today' : 'notifications.race_tomorrow', ['%name%' => $race->name]),
                    $weather, 'app_races_show', $showParams,
                ));
                continue;
            }

            if ($plan === RaceTodoRow::PLAN_READY) {
                if ($days <= self::RACE_COUNTDOWN_DAYS) {
                    $out[] = self::ranked(self::CAT_RACE, new TodoItem(
                        "race:{$race->id}:countdown:$bucket", TodoItem::INFO, 'bi-flag',
                        new TodoText('notifications.race_in_days', ['%name%' => $race->name, '%count%' => $days]),
                        $weather, 'app_races_show', $showParams,
                    ));
                }
                continue;
            }

            $noPlan = $plan === RaceTodoRow::PLAN_NONE;
            $out[] = self::ranked(self::CAT_RACE, new TodoItem(
                "race:{$race->id}:".($noPlan ? 'noplan' : 'nosplits').":$bucket",
                $days <= self::RACE_COUNTDOWN_DAYS ? TodoItem::WARNING : TodoItem::INFO,
                $noPlan ? 'bi-map' : 'bi-stopwatch',
                new TodoText($noPlan ? 'notifications.race_no_plan' : 'notifications.race_no_splits', ['%name%' => $race->name, '%count%' => $days]),
                $weather, 'app_race_plan', ['id' => $race->id],
            ));
        }

        return $out;
    }

    /**
     * @param list<array{gear: Gear, usage: GearUsage}> $alerts
     *
     * @return list<array{rank: int, item: TodoItem}>
     */
    private static function gearWear(array $alerts): array
    {
        $out = [];
        foreach ($alerts as ['gear' => $gear, 'usage' => $usage]) {
            $status = $usage->status();
            if ($status !== GearUsage::STATUS_WARN && $status !== GearUsage::STATUS_WORN) {
                continue;
            }
            $worn = $status === GearUsage::STATUS_WORN;
            $type = $gear->getType()->value;
            // Même libellé que le bandeau du tableau de bord : marque + modèle (surnom)
            $name = $gear->getFullName().($gear->getNickname() !== null ? ' ('.$gear->getNickname().')' : '');

            $out[] = self::ranked(self::CAT_GEAR_WEAR, new TodoItem(
                'gear:'.$gear->getId().':'.$status,
                $worn ? TodoItem::URGENT : TodoItem::WARNING,
                $worn ? 'bi-x-octagon-fill' : 'bi-exclamation-triangle-fill',
                new TodoText("notifications.gear_{$type}_{$status}", ['%name%' => $name]),
                new TodoText("notifications.gear_{$type}_usage", [
                    // Arrondi demi vers le haut, comme number_format() du bandeau, sans round()
                    '%km%'  => (int) floor($usage->totalDistanceKm() + 0.5),
                    '%pct%' => (int) $usage->wearPercent(),
                ]),
                'app_settings', ['tab' => 'gear'], 'gear-'.$gear->getId(),
            ));
        }

        return $out;
    }

    /**
     * @param list<SportGoalProgress> $goals
     *
     * @return list<array{rank: int, item: TodoItem}>
     */
    private static function goals(array $goals, \DateTimeImmutable $now): array
    {
        $week = $now->format('o-\WW');
        $sunday = $now->format('N') === '7';

        $out = [];
        foreach ($goals as $progress) {
            $slug = $progress->sport->getSlug();
            $sport = new TodoText('sport.'.$slug);

            if ($progress->isReached()) {
                $out[] = self::ranked(self::CAT_GOAL_REACHED, new TodoItem(
                    "goal:$slug:$week:reached", TodoItem::SUCCESS, 'bi-trophy-fill',
                    new TodoText('notifications.goal_reached', ['%sport%' => $sport, '%sport_lc%' => new TodoText('sport.'.$slug, [], true)]),
                    null, 'app_dashboard', [], 'sport-goals-title',
                ));
                continue;
            }
            if (!$sunday) {
                $reminder = self::strengthReminder($progress, $now, $week);
                if ($reminder !== null) {
                    $out[] = $reminder;
                }
                continue;
            }

            $left = [];
            foreach ($progress->metrics as $metric) {
                if (!$metric->isReached()) {
                    $left[] = self::leftPart($metric);
                }
            }
            $out[] = self::ranked(self::CAT_GOAL_LEFT, new TodoItem(
                "goal:$slug:$week:left", TodoItem::WARNING, 'bi-hourglass-split',
                new TodoText('notifications.goal_left', ['%sport%' => $sport, '%left%' => $left]),
                null, 'app_dashboard', [], 'sport-goals-title',
            ));
        }

        return $out;
    }

    /**
     * Objectif de renfo en séances sans aucune séance faite, du jeudi au samedi : invitation à une séance guidée
     * de l'espace Santé. Le dimanche n'est pas concerné (rappel « il te reste » de goals()).
     *
     * @return array{rank: int, item: TodoItem}|null
     */
    private static function strengthReminder(SportGoalProgress $progress, \DateTimeImmutable $now, string $week): ?array
    {
        $day = (int) $now->format('N');
        if ($progress->sport->getSlug() !== self::STRENGTH_SPORT_SLUG || $day < self::STRENGTH_REMINDER_FROM_DAY || $day > 6) {
            return null;
        }
        foreach ($progress->metrics as $metric) {
            if ($metric->kind === GoalMetric::SESSIONS && $metric->target > 0 && (int) $metric->current === 0) {
                return self::ranked(self::CAT_STRENGTH_REMINDER, new TodoItem(
                    "health:strength:$week", TodoItem::INFO, 'bi-heart-pulse',
                    new TodoText('notifications.health_strength_none', ['%count%' => (int) $metric->target]),
                    new TodoText('notifications.health_strength_none_sub'),
                    'app_health',
                ));
            }
        }

        return null;
    }

    private static function leftPart(GoalMetric $metric): TodoText
    {
        $left = $metric->remaining();

        return match ($metric->kind) {
            GoalMetric::DISTANCE => new TodoText('notifications.left_distance', ['%value%' => (float) $left]),
            GoalMetric::SESSIONS => (int) $left === 1
                ? new TodoText('notifications.left_sessions_one')
                : new TodoText('notifications.left_sessions_many', ['%count%' => (int) $left]),
            GoalMetric::DURATION => self::duration((int) $left),
            default              => new TodoText('notifications.left_elevation', ['%value%' => (int) $left]),
        };
    }

    /** Durée en minutes, comme la carte des objectifs : « 45 min », « 2h », « 1h05 ». */
    private static function duration(int $minutes): TodoText
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return match (true) {
            $minutes < 60 => new TodoText('objectives.by_sport.minutes', ['%m%' => (string) $m]),
            $m === 0      => new TodoText('objectives.by_sport.hours', ['%h%' => (string) $h]),
            default       => new TodoText('objectives.by_sport.hours_minutes', ['%h%' => (string) $h, '%m%' => sprintf('%02d', $m)]),
        };
    }

    /**
     * @param list<RecentActivityRow> $recent
     *
     * @return list<array{rank: int, item: TodoItem}>
     */
    private static function fuel(array $recent, int $today): array
    {
        $out = [];
        foreach (self::byMostRecent($recent) as $activity) {
            $seconds = ActivityFuelCalculator::durationSeconds($activity->movingSeconds, $activity->elapsedSeconds);
            if ($activity->hasFuel || $activity->fromHealthSession || $seconds === null || $seconds < self::FUEL_MIN_SECONDS || !FuelCatalog::supportsSportSlug($activity->sportSlug)) {
                continue;
            }

            $out[] = self::ranked(self::CAT_FUEL, new TodoItem(
                'fuel:activity:'.$activity->id, TodoItem::INFO, 'bi-cup-straw',
                new TodoText('notifications.fuel_missing', ['%name%' => self::activityName($activity)]),
                new TodoText('notifications.fuel_missing_sub', [
                    '%duration%' => self::duration(intdiv($seconds, 60)),
                    '%when%'     => self::when($today - self::dayIndex($activity->startDate)),
                ]),
                'app_activity_show', ['id' => $activity->id], 'sp-fuel-title',
            ));
            if (count($out) >= self::FUEL_MAX_ITEMS) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param list<RecentActivityRow> $recent
     * @param list<GearType>          $activeTypes
     *
     * @return list<array{rank: int, item: TodoItem}>
     */
    private static function gearMissing(array $recent, array $activeTypes): array
    {
        $missing = array_values(array_filter(
            self::byMostRecent($recent),
            static fn (RecentActivityRow $a): bool => !$a->hasGear && in_array(GearType::forSportSlug($a->sportSlug), $activeTypes, true),
        ));
        if ($missing === []) {
            return [];
        }

        $latest = $missing[0];
        $count = count($missing);

        return [self::ranked(self::CAT_GEAR_MISSING, new TodoItem(
            'gear:missing:'.$latest->id, TodoItem::INFO, 'bi-tag',
            $count === 1
                ? new TodoText('notifications.gear_missing_one')
                : new TodoText('notifications.gear_missing_many', ['%count%' => $count]),
            new TodoText($count === 1 ? 'notifications.gear_missing_only' : 'notifications.gear_missing_latest', ['%name%' => self::activityName($latest)]),
            'app_activity_show', ['id' => $latest->id], 'act-gear-select',
        ))];
    }

    /**
     * @param list<RecentActivityRow> $activities
     *
     * @return list<RecentActivityRow>
     */
    private static function byMostRecent(array $activities): array
    {
        usort($activities, static fn (RecentActivityRow $a, RecentActivityRow $b): int => [$b->startDate, $b->id] <=> [$a->startDate, $a->id]);

        return $activities;
    }

    private static function activityName(RecentActivityRow $activity): string|TodoText
    {
        $name = trim((string) $activity->name);

        return $name !== '' ? $name : new TodoText('notifications.activity_unnamed');
    }

    private static function when(int $daysAgo): TodoText
    {
        return match (true) {
            $daysAgo <= 0  => new TodoText('notifications.when_today'),
            $daysAgo === 1 => new TodoText('notifications.when_yesterday'),
            default        => new TodoText('notifications.when_days', ['%count%' => $daysAgo]),
        };
    }

    /** @return array{rank: int, item: TodoItem} */
    private static function ranked(int $category, TodoItem $item): array
    {
        return ['rank' => self::SEVERITY_RANK[$item->severity] * 100 + $category, 'item' => $item];
    }
}
