<?php

namespace App\Service\Activity;

use App\Entity\Activity;
use App\Entity\HealthSession;
use App\Repository\HealthSessionRepository;

/** Origine des activités (ActivitySource), en une requête au plus sur les séances Santé. */
final class ActivitySourceResolver
{
    public function __construct(private readonly HealthSessionRepository $healthSessions)
    {
    }

    /** Séance Santé qui a créé l'activité ; pas de requête pour une activité Strava. */
    public function healthSessionFor(Activity $activity): ?HealthSession
    {
        return $activity->getStravaId() === null && $activity->getId() !== null
            ? $this->healthSessions->findOneByActivity($activity)
            : null;
    }

    public function sourceOf(Activity $activity): ActivitySource
    {
        return ActivitySource::forActivity($activity, $this->healthSessionFor($activity) !== null);
    }

    /**
     * @param list<Activity> $activities
     *
     * @return array<int, ActivitySource> id d'activité => origine
     */
    public function sourcesOf(array $activities): array
    {
        $local = [];
        foreach ($activities as $activity) {
            if ($activity->getStravaId() === null && $activity->getId() !== null) {
                $local[] = $activity->getId();
            }
        }
        $fromHealth = $this->healthSessions->findActivityIdsAmong($local);

        $sources = [];
        foreach ($activities as $activity) {
            $id = $activity->getId();
            if ($id !== null) {
                $sources[$id] = ActivitySource::forActivity($activity, isset($fromHealth[$id]));
            }
        }

        return $sources;
    }
}
