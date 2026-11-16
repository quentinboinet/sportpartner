<?php

namespace App\Service\Health;

use App\Entity\Activity;
use App\Entity\HealthCategory;
use App\Entity\HealthSession;
use App\Entity\Sport;

/**
 * Activité créée par une séance Santé (case « Ajouter à mes activités », cochée par défaut).
 *
 * Sport : celui choisi à l'enregistrement (« Renfo » weight_training ou « Autre » other) ; sans choix, règle
 * automatique sur les exercices dont au moins une série a été faite : renforcement, gainage et équilibre comptent
 * pour weight_training, mobilité et étirements pour other ; weight_training si les premiers sont au moins aussi
 * nombreux (égalité comprise), other sinon. Le formulaire de fin de séance présélectionne le même résultat
 * (sportFor() de health_session_logic.js).
 *
 * Activité : nom de la séance, départ = début de la séance (UTC, comme les activités Strava) avec le fuseau du
 * navigateur, temps en mouvement = durée active (pauses exclues), temps écoulé = du début à la fin. Pas de
 * distance, de D+, de matériel ni de stravaId : la synchro Strava n'importe que des activités Strava nouvelles
 * (par stravaId) et ne modifie ni ne supprime jamais une activité locale.
 */
final class HealthActivityMapper
{
    public const KIND_STRENGTH = 'strength';
    public const KIND_MOBILITY = 'mobility';
    public const KIND_MIXED = 'mixed';

    /** @param list<HealthCategory> $categories catégories des exercices faits (une entrée par exercice) */
    public static function sportSlug(array $categories, ?string $chosen = null): string
    {
        if ($chosen === HealthSessionPayload::SPORT_STRENGTH || $chosen === HealthSessionPayload::SPORT_OTHER) {
            return $chosen;
        }
        [$strength, $mobility] = self::counts($categories);

        return $strength >= $mobility ? HealthSessionPayload::SPORT_STRENGTH : HealthSessionPayload::SPORT_OTHER;
    }

    /**
     * Nature de la séance, pour son nom par défaut : renforcement seul, mobilité / étirements seuls, ou les deux.
     *
     * @param list<HealthCategory> $categories
     */
    public static function sessionKind(array $categories): string
    {
        [$strength, $mobility] = self::counts($categories);

        return match (true) {
            $mobility === 0 => self::KIND_STRENGTH,
            $strength === 0 => self::KIND_MOBILITY,
            default         => self::KIND_MIXED,
        };
    }

    /** @return list<HealthCategory> catégories des exercices de la séance dont au moins une série a été faite */
    public static function doneCategories(HealthSession $session): array
    {
        $out = [];
        foreach ($session->getExercises() as $exercise) {
            if ($exercise->countSetsDone() > 0) {
                $out[] = $exercise->getCategory();
            }
        }

        return $out;
    }

    public static function toActivity(HealthSession $session, ?Sport $sport): Activity
    {
        return (new Activity())
            ->setUser($session->getUser())
            ->setSport($sport)
            ->setName($session->getName())
            ->setStartDate($session->getStartedAtUtc())
            ->setTimezone($session->getTimezone())
            ->setMovingTimeSeconds($session->getDurationSeconds())
            ->setElapsedTimeSeconds($session->getElapsedSeconds());
    }

    /**
     * @param list<HealthCategory> $categories
     *
     * @return array{int, int} [renforcement, mobilité]
     */
    private static function counts(array $categories): array
    {
        $strength = count(array_filter($categories, static fn (HealthCategory $c): bool => $c->isStrengthWork()));

        return [$strength, count($categories) - $strength];
    }
}
