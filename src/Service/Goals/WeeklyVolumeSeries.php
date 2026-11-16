<?php

namespace App\Service\Goals;

/**
 * Série hebdomadaire du tableau de bord (graphique, anneaux, tuiles) : exactement $weeks semaines consécutives,
 * de la plus ancienne à la semaine EN COURS (dernier élément), les semaines sans activité valant zéro.
 *
 * Sans ce remplissage, le dernier élément était la dernière semaine AVEC des activités et l'avant-dernier la
 * semaine active précédente : un lundi sans sortie, les anneaux montraient encore la semaine passée alors que
 * les objectifs par sport (WeeklyGoalProgress) repartaient de zéro.
 *
 * Semaines ISO (lundi -> dimanche) dans le fuseau de PHP, comme YEARWEEK(startDate, 1) et
 * WeeklyGoalProgress::weekBounds(). "week" et "year" sont tirés de yearweek (semaine et année ISO) : la colonne
 * WEEK(startDate, 1) de MySQL vaut 0 début janvier pour une semaine qui appartient à l'année précédente.
 */
final class WeeklyVolumeSeries
{
    private function __construct()
    {
    }

    /** Lundi 00:00:00 de la plus ancienne semaine de la série (borne basse de la requête). */
    public static function since(\DateTimeImmutable $now, int $weeks): \DateTimeImmutable
    {
        [$monday] = WeeklyGoalProgress::weekBounds($now);

        return $monday->modify('-'.(max(1, $weeks) - 1).' weeks');
    }

    /**
     * @param list<array<string, mixed>> $rows lignes de ActivityRepository::findWeeklyVolume() (valeurs SQL en chaînes)
     *
     * @return list<array{yearweek: int, week: int, year: int, totalDistance: float, totalTime: int, totalElevation: float, count: int}>
     */
    public static function fill(array $rows, \DateTimeImmutable $now, int $weeks): array
    {
        $byYearweek = [];
        foreach ($rows as $row) {
            $byYearweek[(int) ($row['yearweek'] ?? 0)] = $row;
        }

        $series = [];
        $monday = self::since($now, $weeks);
        for ($i = 0; $i < max(1, $weeks); ++$i) {
            $yearweek = (int) $monday->format('oW');
            $row = $byYearweek[$yearweek] ?? [];
            $series[] = [
                'yearweek' => $yearweek,
                'week' => (int) $monday->format('W'),
                'year' => (int) $monday->format('o'),
                'totalDistance' => (float) ($row['totalDistance'] ?? 0),
                'totalTime' => (int) ($row['totalTime'] ?? 0),
                'totalElevation' => (float) ($row['totalElevation'] ?? 0),
                'count' => (int) ($row['count'] ?? 0),
            ];
            $monday = $monday->modify('+1 week');
        }

        return $series;
    }
}
