<?php

namespace App\Service\Activity;

use App\Service\Health\FieldError;

/**
 * Allure ou vitesse invraisemblable d'une activité saisie à la main (faute de frappe probable sur le temps ou la
 * distance) :
 *  - sports à allure (course, trail, marche, randonnée) : plus lente que 30:00 /km ou plus rapide que 2:00 /km ;
 *  - vélo : vitesse moyenne au-dessus de 80 km/h.
 * Bornes incluses dans le vraisemblable (30:00 /km tout juste passe). Comparaisons en produits entiers, sans
 * arrondi. Sans distance ou sans temps : rien à vérifier.
 *
 * AVERTISSEMENT, pas une erreur : rien n'est enregistré, l'utilisateur confirme avec « Enregistrer quand même »
 * (même parcours que le doublon probable). Des cas réels sortent de ces bornes (kilomètre vertical ou randonnée
 * très raide à plus de 30 min/km, marche très lente) et ne doivent pas être bloqués.
 */
final class ActivityPlausibilityRule
{
    /** Sports dont l'allure est affichée en min/km (la natation, à l'allure très variable, n'est pas vérifiée). */
    public const PACE_SPORTS = ['run', 'trail', 'walk', 'hike'];
    public const SLOWEST_PACE_SECONDS = 1800;
    public const FASTEST_PACE_SECONDS = 120;
    public const RIDE_MAX_KMH = 80;

    public static function check(string $sportSlug, int $movingSeconds, ?float $meters): ?FieldError
    {
        if ($meters === null || $meters <= 0 || $movingSeconds <= 0) {
            return null;
        }

        if (in_array($sportSlug, self::PACE_SPORTS, true)) {
            // allure (s/km) = temps * 1000 / mètres, comparée sans division
            $slow = $movingSeconds * 1000 > self::SLOWEST_PACE_SECONDS * $meters;
            $fast = $movingSeconds * 1000 < self::FASTEST_PACE_SECONDS * $meters;
            if (!$slow && !$fast) {
                return null;
            }

            return new FieldError($slow ? 'activity_form.plausibility.pace_slow' : 'activity_form.plausibility.pace_fast', [
                '%value%' => self::pace((int) floor($movingSeconds * 1000 / $meters + 0.5)),
                '%limit%' => self::pace($slow ? self::SLOWEST_PACE_SECONDS : self::FASTEST_PACE_SECONDS),
            ]);
        }

        // vitesse (km/h) = mètres * 3,6 / temps, comparée sans division
        if ($sportSlug === 'ride' && $meters * 36 > self::RIDE_MAX_KMH * 10 * $movingSeconds) {
            return new FieldError('activity_form.plausibility.speed_high', [
                '%value%' => (int) floor($meters * 3.6 / $movingSeconds + 0.5),
                '%limit%' => self::RIDE_MAX_KMH,
            ]);
        }

        return null;
    }

    /**
     * Seuils passés au JS (aperçu en direct) : une seule source.
     *
     * @return array{paceSports: list<string>, slowestPace: int, fastestPace: int, rideMaxKmh: int}
     */
    public static function forJs(): array
    {
        return [
            'paceSports' => self::PACE_SPORTS,
            'slowestPace' => self::SLOWEST_PACE_SECONDS,
            'fastestPace' => self::FASTEST_PACE_SECONDS,
            'rideMaxKmh' => self::RIDE_MAX_KMH,
        ];
    }

    private static function pace(int $secondsPerKm): string
    {
        return sprintf('%d:%02d', intdiv($secondsPerKm, 60), $secondsPerKm % 60);
    }
}
