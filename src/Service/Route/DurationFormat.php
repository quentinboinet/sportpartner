<?php

namespace App\Service\Route;

/**
 * Conversions pures durée <-> texte pour le plan de course (temps écoulés depuis le départ,
 * allures, heures d'horloge). Les heures ne sont jamais bornées à 24 (ultra-trails).
 */
final class DurationFormat
{
    public const MAX_HOURS = 999;

    private function __construct()
    {
    }

    /**
     * Texte saisi -> secondes. Formats acceptés (espaces tolérés autour des séparateurs) :
     *  - "H:MM", "HH:MM", "HHH:MM"        ex. "3:15", "30:05"
     *  - "H:MM:SS"                          ex. "3:15:30"
     *  - "HhMM", "Hh"                       ex. "3h15", "3 h 05", "3h"
     * Heures 0-999, minutes et secondes sur 2 chiffres (00-59).
     *
     * @return int|null null pour une saisie vide
     *
     * @throws \InvalidArgumentException si la saisie n'est pas une durée valide
     */
    public static function parse(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{1,3})\s*:\s*([0-5]\d)(?:\s*:\s*([0-5]\d))?$/', $value, $m)) {
            return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) ($m[3] ?? 0);
        }
        if (preg_match('/^(\d{1,3})\s*[hH](?:\s*([0-5]\d))?$/', $value, $m)) {
            return (int) $m[1] * 3600 + (int) ($m[2] ?? 0) * 60;
        }

        throw new \InvalidArgumentException(sprintf('Invalid duration "%s".', $value));
    }

    /**
     * Secondes -> "H:MM" (secondes tronquées) ou "H:MM:SS". Heures non complétées par des zéros,
     * non bornées à 24. Une durée négative (marge sur barrière dépassée) est préfixée par "-".
     */
    public static function format(int $seconds, bool $withSeconds = false): string
    {
        $sign = $seconds < 0 ? '-' : '';
        $abs = abs($seconds);
        $h = intdiv($abs, 3600);
        $min = intdiv($abs % 3600, 60);

        return $withSeconds
            ? sprintf('%s%d:%02d:%02d', $sign, $h, $min, $abs % 60)
            : sprintf('%s%d:%02d', $sign, $h, $min);
    }

    /**
     * Format de saisie qui recharge exactement la valeur : "H:MM" si la durée tombe sur une minute,
     * sinon "H:MM:SS".
     */
    public static function formatInput(int $seconds): string
    {
        return self::format($seconds, $seconds % 60 !== 0);
    }

    /** Allure en secondes/km -> "M:SS" (arrondie à la seconde), ex. 307.4 -> "5:07". */
    public static function formatPace(float $secondsPerKm): string
    {
        $s = (int) round($secondsPerKm);

        return sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    }

    /**
     * Heure d'horloge atteinte après $elapsed secondes depuis une heure de départ (seule l'heure du
     * jour de $start compte). Minutes tronquées.
     *
     * @return array{time: string, dayOffset: int} time "HH:MM" ; dayOffset 0 = jour du départ, 1 = lendemain...
     */
    public static function formatClock(\DateTimeImmutable $start, int $elapsed): array
    {
        $startOfDay = (int) $start->format('G') * 3600 + (int) $start->format('i') * 60 + (int) $start->format('s');
        $total = $startOfDay + $elapsed;
        $dayOffset = (int) floor($total / 86400);
        $timeOfDay = $total - $dayOffset * 86400;

        return [
            'time' => sprintf('%02d:%02d', intdiv($timeOfDay, 3600), intdiv($timeOfDay % 3600, 60)),
            'dayOffset' => $dayOffset,
        ];
    }
}
