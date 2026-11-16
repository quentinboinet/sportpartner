<?php

namespace App\Service\Stats;

/**
 * Mise en forme des nombres de la comparaison (fr / en), en entiers : arrondi demi loin de zéro sans round()
 * ni number_format() décimal, donc le même texte en PHP 8.3 et 8.4+.
 *
 * - nombres : « 1 234,5 » en français, « 1,234.5 » en anglais ; signe moins = trait d'union "-" ;
 * - durées (minutes) : « 45 min », « 2h », « 1h05 » (fr) / « 1h 05m » (en), comme les objectifs par sport ;
 * - allure : « 5:07 /km » ; pourcentages : une décimale sous 10 %, sans « ,0 » final, « 12 % » / « 12% ».
 */
final class ComparisonFormat
{
    private function __construct()
    {
    }

    public static function number(float $value, int $decimals, string $locale): string
    {
        $fr = self::isFrench($locale);
        $factor = 10 ** $decimals;
        $scaled = (int) floor(abs($value) * $factor + 0.5);
        $sign = $value < 0 && $scaled > 0 ? '-' : '';
        $whole = number_format(intdiv($scaled, $factor), 0, '', $fr ? ' ' : ',');
        $fraction = $decimals > 0
            ? ($fr ? ',' : '.').str_pad((string) ($scaled % $factor), $decimals, '0', STR_PAD_LEFT)
            : '';

        return $sign.$whole.$fraction;
    }

    public static function duration(int $minutes, string $locale): string
    {
        $minutes = abs($minutes);
        if ($minutes < 60) {
            return $minutes.' min';
        }
        $fr = self::isFrench($locale);
        $hours = number_format(intdiv($minutes, 60), 0, '', $fr ? ' ' : ',');
        $rest = $minutes % 60;
        if ($rest === 0) {
            return $hours.'h';
        }

        return $fr ? $hours.'h'.sprintf('%02d', $rest) : $hours.'h '.sprintf('%02d', $rest).'m';
    }

    /** Allure m:ss à partir de secondes entières. */
    public static function pace(int $seconds): string
    {
        $seconds = abs($seconds);

        return intdiv($seconds, 60).':'.sprintf('%02d', $seconds % 60);
    }

    /** Valeur avec son unité (voir MetricComparison) ; null s'affiche « - ». */
    public static function value(?float $value, string $unit, int $decimals, string $locale): string
    {
        if ($value === null) {
            return '-';
        }

        return match ($unit) {
            'km'    => self::number($value, $decimals, $locale).' km',
            'min'   => self::duration((int) MetricDelta::round($value, 0), $locale),
            'm'     => self::number($value, $decimals, $locale).' m',
            'pace'  => self::pace((int) MetricDelta::round($value, 0)).' /km',
            'kmh'   => self::number($value, $decimals, $locale).' km/h',
            'bpm'   => self::number($value, $decimals, $locale).' bpm',
            default => self::number($value, $decimals, $locale),
        };
    }

    /** Écart signé : « +12,5 km », « -45 min », « -0:12 /km », « 0 km » ; null s'affiche « - ». */
    public static function signed(?float $delta, string $unit, int $decimals, string $locale): string
    {
        if ($delta === null) {
            return '-';
        }
        $sign = $delta > 0 ? '+' : ($delta < 0 ? '-' : '');

        return $sign.self::value(abs($delta), $unit, $decimals, $locale);
    }

    /** Pourcentage signé : « +4,2 % », « -12 % », « 0 % » (fr) / « +4.2% » (en). */
    public static function percent(float $percent, string $locale): string
    {
        $abs = MetricDelta::round(abs($percent), 1);
        $text = self::number($abs, $abs < 10 ? 1 : 0, $locale);
        $text = preg_replace('/[,.]0$/', '', $text) ?? $text;
        $sign = $abs > 0 ? ($percent > 0 ? '+' : '-') : '';

        return $sign.$text.(self::isFrench($locale) ? ' %' : '%');
    }

    /**
     * Jour 'Y-m-d' : « 1 nov. 2026 » (fr) / « 1 Nov 2026 » (en), sans l'année si $withYear est faux.
     *
     * @param list<string> $months noms courts des 12 mois (traduits)
     */
    public static function day(string $day, array $months, bool $withYear = true): string
    {
        $text = (int) substr($day, 8, 2).' '.($months[(int) substr($day, 5, 2) - 1] ?? substr($day, 5, 2));

        return $withYear ? $text.' '.substr($day, 0, 4) : $text;
    }

    /**
     * Période : « 1 nov. → 16 nov. 2026 », « 28 déc. 2025 → 3 janv. 2026 », « 16 nov. 2026 » (un seul jour).
     *
     * @param list<string> $months
     */
    public static function range(string $from, string $to, array $months, bool $withYear = true): string
    {
        if ($from === $to) {
            return self::day($from, $months, $withYear);
        }
        $sameYear = substr($from, 0, 4) === substr($to, 0, 4);

        return self::day($from, $months, $withYear && !$sameYear).' → '.self::day($to, $months, $withYear);
    }

    private static function isFrench(string $locale): bool
    {
        return !str_starts_with($locale, 'en');
    }
}
