<?php

namespace App\Service\Activity;

use App\Entity\Activity;

/**
 * Doublon probable d'une activité saisie à la main : une autre activité de l'utilisateur, du MÊME sport, partie à
 * WINDOW_SECONDS près (bornes incluses) - typiquement la même sortie déjà synchronisée depuis Strava. Simple
 * avertissement : l'utilisateur peut « Enregistrer quand même ». L'activité modifiée elle-même est exclue.
 *
 * Les départs sont comparés en UTC (startDate est stocké en UTC pour toutes les origines).
 * ActivityRepository::findStartingBetween() lit les candidates avec les bornes de window(), filter() applique la
 * règle exacte.
 */
final class ActivityDuplicateRule
{
    public const WINDOW_SECONDS = 1800;

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} bornes UTC incluses autour du départ */
    public static function window(\DateTimeImmutable $startUtc): array
    {
        $utc = $startUtc->setTimezone(new \DateTimeZone('UTC'));

        return [$utc->modify('-'.self::WINDOW_SECONDS.' seconds'), $utc->modify('+'.self::WINDOW_SECONDS.' seconds')];
    }

    public static function matches(Activity $candidate, string $sportSlug, \DateTimeImmutable $startUtc, ?int $excludeId = null): bool
    {
        $start = $candidate->getStartDateUtc();
        if ($start === null || $candidate->getSport()?->getSlug() !== $sportSlug) {
            return false;
        }
        if ($excludeId !== null && $candidate->getId() === $excludeId) {
            return false;
        }

        return abs($start->getTimestamp() - $startUtc->getTimestamp()) <= self::WINDOW_SECONDS;
    }

    /**
     * @param iterable<Activity> $candidates
     *
     * @return list<Activity> les doublons probables, dans l'ordre reçu
     */
    public static function filter(iterable $candidates, string $sportSlug, \DateTimeImmutable $startUtc, ?int $excludeId = null): array
    {
        $out = [];
        foreach ($candidates as $candidate) {
            if (self::matches($candidate, $sportSlug, $startUtc, $excludeId)) {
                $out[] = $candidate;
            }
        }

        return $out;
    }
}
