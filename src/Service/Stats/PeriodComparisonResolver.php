<?php

namespace App\Service\Stats;

/**
 * Périodes de la page « Comparer » des statistiques (calcul pur, à partir de "maintenant").
 *
 * A est toujours la période la plus récente, B la référence. Les jours sont entiers (00:00:00 -> 23:59:59)
 * dans le fuseau de $now, celui de PHP dans l'application, comparé tel quel aux startDate enregistrées.
 *
 * Préréglages :
 * - week : « à date », du lundi à aujourd'hui contre les mêmes jours de la semaine dernière (lundi -> même
 *   jour de la semaine). Un lundi compare un jour à un jour, un dimanche deux semaines complètes.
 * - month : « à date », du 1er à aujourd'hui (jour J) contre le 1er -> jour J du mois précédent. Si le mois
 *   précédent n'a pas de jour J (30 mars contre février), il est pris jusqu'à son dernier jour (NOTE_CLAMPED).
 *   Le dernier jour du mois, c'est mois complet contre mois complet (NOTE_FULL_MONTH) : le 30 avril compare
 *   tout avril à tout mars, le 31 mars tout mars à tout février.
 * - month_yoy : mêmes règles, contre le même mois de l'année précédente (le 29 février 2028, dernier jour
 *   du mois, compare février 2028 complet à février 2027 complet).
 * - ytd : du 1er janvier à aujourd'hui contre le 1er janvier -> même date de l'an dernier ; le 29 février
 *   est comparé au 28 février (NOTE_LEAP_DAY). Après un 29 février, A compte donc un jour de plus que B.
 * - 30d : les 30 derniers jours (aujourd'hui compris) contre les 30 jours d'avant.
 * - last_year : l'année N-1 complète contre l'année N-2 complète (365 ou 366 jours).
 * - custom : deux périodes libres, chacune de MAX_DAYS jours au plus (5 ans, jours bissextiles compris),
 *   entre le 01/01/2000 et le 31/12 de l'année prochaine.
 *
 * Toute saisie invalide (préréglage inconnu, date absente ou mal formée, période inversée, trop longue ou hors
 * bornes, sport inconnu) revient au préréglage par défaut ou à « tous les sports », avec un message.
 */
final class PeriodComparisonResolver
{
    public const WEEK = 'week';
    public const MONTH = 'month';
    public const MONTH_LAST_YEAR = 'month_yoy';
    public const YEAR_TO_DATE = 'ytd';
    public const LAST_30_DAYS = '30d';
    public const LAST_YEAR = 'last_year';
    public const CUSTOM = 'custom';

    /** Préréglages calculés, dans l'ordre d'affichage (« custom » à part). */
    public const PRESETS = [self::WEEK, self::MONTH, self::MONTH_LAST_YEAR, self::YEAR_TO_DATE, self::LAST_30_DAYS, self::LAST_YEAR];
    public const DEFAULT_PRESET = self::MONTH;

    /** Durée maximale d'une période libre : 5 ans, dont au plus 2 jours bissextiles. */
    public const MAX_DAYS = 1827;
    public const MIN_DAY = '2000-01-01';

    private function __construct()
    {
    }

    public static function preset(string $preset, \DateTimeImmutable $now): PeriodComparison
    {
        $today = $now->setTime(0, 0);
        $y = (int) $today->format('Y');
        $m = (int) $today->format('n');
        $d = (int) $today->format('j');

        return match ($preset) {
            self::WEEK => self::week($today, $y, $m, $d),
            self::MONTH => self::monthToDate($today, $y, $m - 1, self::MONTH),
            self::MONTH_LAST_YEAR => self::monthToDate($today, $y - 1, $m, self::MONTH_LAST_YEAR),
            self::YEAR_TO_DATE => self::yearToDate($today, $y, $m, $d),
            self::LAST_30_DAYS => new PeriodComparison(
                self::LAST_30_DAYS,
                new DateRange($today->setDate($y, $m, $d - 29), $today),
                new DateRange($today->setDate($y, $m, $d - 59), $today->setDate($y, $m, $d - 30)),
            ),
            self::LAST_YEAR => new PeriodComparison(
                self::LAST_YEAR,
                new DateRange($today->setDate($y - 1, 1, 1), $today->setDate($y - 1, 12, 31)),
                new DateRange($today->setDate($y - 2, 1, 1), $today->setDate($y - 2, 12, 31)),
            ),
            default => throw new \InvalidArgumentException(sprintf('Unknown comparison preset "%s".', $preset)),
        };
    }

    /** Deux périodes libres (A puis B). */
    public static function custom(DateRange $a, DateRange $b): PeriodComparison
    {
        return new PeriodComparison(self::CUSTOM, $a, $b);
    }

    /** Période de même durée juste avant $range (vue d'ensemble « 7 jours » ou période personnalisée). */
    public static function previousOf(DateRange $range): DateRange
    {
        return $range->shiftDays($range->days());
    }

    /** Dernier jour accepté dans une période libre : le 31 décembre de l'année prochaine. */
    public static function maxDay(\DateTimeImmutable $now): string
    {
        return ((int) $now->format('Y') + 1).'-12-31';
    }

    /**
     * Lit les paramètres preset, a_from, a_to, b_from, b_to et sport de la requête.
     * Sans preset, des dates donnent une comparaison libre ; sans rien, le préréglage par défaut.
     *
     * @param array<string, mixed> $query
     * @param list<string>         $sportSlugs sports connus (table sports)
     */
    public static function fromQuery(array $query, array $sportSlugs, \DateTimeImmutable $now): ComparisonRequest
    {
        $errors = [];

        $sport = $query['sport'] ?? null;
        if ($sport === null || $sport === '' || $sport === 'all') {
            $sport = null;
        } elseif (!is_string($sport) || !in_array($sport, $sportSlugs, true)) {
            $sport = null;
            $errors[] = ['key' => 'stats.compare.error_sport', 'params' => []];
        }

        $preset = $query['preset'] ?? null;
        $dateKeys = ['a_from', 'a_to', 'b_from', 'b_to'];
        $hasDates = false;
        foreach ($dateKeys as $key) {
            if (($query[$key] ?? '') !== '') {
                $hasDates = true;
            }
        }
        if ($preset === null || $preset === '') {
            $preset = $hasDates ? self::CUSTOM : self::DEFAULT_PRESET;
        }

        if (!is_string($preset) || ($preset !== self::CUSTOM && !in_array($preset, self::PRESETS, true))) {
            $errors[] = ['key' => 'stats.compare.error_preset', 'params' => []];

            return new ComparisonRequest(self::preset(self::DEFAULT_PRESET, $now), $sport, $errors);
        }

        if ($preset !== self::CUSTOM) {
            return new ComparisonRequest(self::preset($preset, $now), $sport, $errors);
        }

        $dates = [];
        foreach ($dateKeys as $key) {
            $day = self::parseDay($query[$key] ?? null, $now->getTimezone());
            if ($day === null) {
                $errors[] = ['key' => 'stats.compare.error_dates', 'params' => []];

                return new ComparisonRequest(self::preset(self::DEFAULT_PRESET, $now), $sport, $errors);
            }
            $dates[$key] = $day;
        }
        $error = self::customError($dates, $now);
        if ($error !== null) {
            $errors[] = $error;

            return new ComparisonRequest(self::preset(self::DEFAULT_PRESET, $now), $sport, $errors);
        }

        return new ComparisonRequest(
            self::custom(new DateRange($dates['a_from'], $dates['a_to']), new DateRange($dates['b_from'], $dates['b_to'])),
            $sport,
            $errors,
        );
    }

    /**
     * Date 'Y-m-d' stricte (refuse 2026-02-30, 2026-2-3, les heures et les tableaux).
     */
    public static function parseDay(mixed $value, \DateTimeZone $timezone): ?\DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);

        return $day !== false && $day->format('Y-m-d') === $value ? $day : null;
    }

    /**
     * @param array<string, \DateTimeImmutable> $dates a_from, a_to, b_from, b_to
     *
     * @return array{key: string, params: array<string, string>}|null
     */
    private static function customError(array $dates, \DateTimeImmutable $now): ?array
    {
        $max = self::maxDay($now);
        foreach ($dates as $day) {
            $key = $day->format('Y-m-d');
            if ($key < self::MIN_DAY || $key > $max) {
                return ['key' => 'stats.compare.error_bounds', 'params' => ['%max%' => substr($max, 0, 4)]];
            }
        }

        foreach ([['a_from', 'a_to'], ['b_from', 'b_to']] as [$from, $to]) {
            $fromKey = $dates[$from]->format('Y-m-d');
            $toKey = $dates[$to]->format('Y-m-d');
            if ($fromKey > $toKey) {
                return ['key' => 'stats.compare.error_inverted', 'params' => []];
            }
            if (DateRange::dayNumber($toKey) - DateRange::dayNumber($fromKey) + 1 > self::MAX_DAYS) {
                return ['key' => 'stats.compare.error_span', 'params' => ['%max%' => (string) self::MAX_DAYS]];
            }
        }

        return null;
    }

    private static function week(\DateTimeImmutable $today, int $y, int $m, int $d): PeriodComparison
    {
        $sinceMonday = (int) $today->format('N') - 1;

        return new PeriodComparison(
            self::WEEK,
            new DateRange($today->setDate($y, $m, $d - $sinceMonday), $today),
            new DateRange($today->setDate($y, $m, $d - $sinceMonday - 7), $today->setDate($y, $m, $d - 7)),
            true,
        );
    }

    /** Du 1er à aujourd'hui contre le même début du mois de référence ($refMonth peut valoir 0 : décembre de l'année d'avant). */
    private static function monthToDate(\DateTimeImmutable $today, int $refYear, int $refMonth, string $preset): PeriodComparison
    {
        $d = (int) $today->format('j');
        $refFirst = $today->setDate($refYear, $refMonth, 1);
        $refLength = (int) $refFirst->format('t');

        $note = null;
        $refLast = $d;
        if ($d === (int) $today->format('t')) {
            $note = PeriodComparison::NOTE_FULL_MONTH;
            $refLast = $refLength;
        } elseif ($d > $refLength) {
            $note = PeriodComparison::NOTE_CLAMPED;
            $refLast = $refLength;
        }

        return new PeriodComparison(
            $preset,
            new DateRange($today->setDate((int) $today->format('Y'), (int) $today->format('n'), 1), $today),
            new DateRange($refFirst, $refFirst->setDate((int) $refFirst->format('Y'), (int) $refFirst->format('n'), $refLast)),
            true,
            $note,
        );
    }

    private static function yearToDate(\DateTimeImmutable $today, int $y, int $m, int $d): PeriodComparison
    {
        $refLength = (int) $today->setDate($y - 1, $m, 1)->format('t');

        return new PeriodComparison(
            self::YEAR_TO_DATE,
            new DateRange($today->setDate($y, 1, 1), $today),
            new DateRange($today->setDate($y - 1, 1, 1), $today->setDate($y - 1, $m, min($d, $refLength))),
            true,
            $d > $refLength ? PeriodComparison::NOTE_LEAP_DAY : null,
        );
    }
}
