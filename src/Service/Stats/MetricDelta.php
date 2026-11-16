<?php

namespace App\Service\Stats;

/**
 * Écart entre la valeur de A et celle de B pour un indicateur, sur les valeurs arrondies comme elles sont
 * affichées (l'écart affiché est donc toujours la différence des deux nombres affichés).
 *
 * - delta = A - B ; percent = (A - B) / B x 100 au dixième, null si B vaut 0 (pas de division par zéro) ;
 * - A et B nuls (ou l'un absent) : pas d'écart (« - » à l'écran), direction et ton "none" ;
 * - direction : up / down / flat, d'après le signe de l'écart ;
 * - ton : better / worse selon le sens de l'indicateur (plus de volume = mieux, allure plus basse = mieux),
 *   neutral pour un indicateur sans jugement (FC), same si l'écart est nul.
 *
 * Arrondi « demi loin de zéro » calculé avec floor(), sans round() : même résultat en PHP 8.3 et 8.4+.
 */
final class MetricDelta
{
    public const HIGHER_IS_BETTER = 1;
    public const LOWER_IS_BETTER = -1;
    public const NEUTRAL = 0;

    private function __construct(
        public readonly ?float $a,
        public readonly ?float $b,
        public readonly ?float $delta,
        public readonly ?float $percent,
        public readonly string $direction,
        public readonly string $tone,
    ) {
    }

    public static function compare(?float $a, ?float $b, int $polarity, int $decimals): self
    {
        $a = $a !== null ? self::round($a, $decimals) : null;
        $b = $b !== null ? self::round($b, $decimals) : null;

        if ($a === null || $b === null || ($a == 0.0 && $b == 0.0)) {
            return new self($a, $b, null, null, 'none', 'none');
        }

        $delta = self::round($a - $b, $decimals);
        $percent = $b != 0.0 ? self::round(($a - $b) / abs($b) * 100, 1) : null;
        $direction = $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat');
        $tone = match (true) {
            $direction === 'flat'        => 'same',
            $polarity === self::NEUTRAL  => 'neutral',
            ($direction === 'up') === ($polarity === self::HIGHER_IS_BETTER) => 'better',
            default                      => 'worse',
        };

        return new self($a, $b, $delta, $percent, $direction, $tone);
    }

    /** Arrondi à $decimals décimales, demi loin de zéro, sans round(). */
    public static function round(float $value, int $decimals): float
    {
        $factor = 10 ** $decimals;
        $rounded = floor(abs($value) * $factor + 0.5) / $factor;
        $rounded = $value < 0 ? -$rounded : $rounded;

        return $rounded == 0.0 ? 0.0 : $rounded;
    }

    /** Valeur ramenée à 7 jours (moyenne par semaine) ; 0 pour une période vide. */
    public static function perWeek(float $total, int $days): float
    {
        return $days > 0 ? $total * 7 / $days : 0.0;
    }
}
