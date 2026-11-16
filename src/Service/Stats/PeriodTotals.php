<?php

namespace App\Service\Stats;

/**
 * Totaux d'activités (une journée et un sport, une période, un sport sur une période...).
 *
 * Les champs hr* et pace* sont des sommes partielles : FC moyenne pondérée par le temps en mouvement des
 * activités qui ont une FC, allure / vitesse = temps total / distance totale des activités de plus de 100 m
 * avec un temps en mouvement (comme ActivityRepository::getWeeklyAvgPaceForSport).
 *
 * @phpstan-type ComparisonRow array{day: string, sport: string, sessions: int, distanceM: float, movingSeconds: int, elevationM: float, maxDistanceM: float, maxElevationM: float, hrWeighted: float, hrSeconds: int, paceDistanceM: float, paceSeconds: int}
 */
final class PeriodTotals
{
    public function __construct(
        public readonly int $sessions = 0,
        public readonly float $distanceM = 0.0,
        public readonly int $movingSeconds = 0,
        public readonly float $elevationM = 0.0,
        public readonly float $maxDistanceM = 0.0,
        public readonly float $maxElevationM = 0.0,
        public readonly float $hrWeighted = 0.0,
        public readonly int $hrSeconds = 0,
        public readonly float $paceDistanceM = 0.0,
        public readonly int $paceSeconds = 0,
    ) {
    }

    /** @param ComparisonRow $row */
    public static function ofRow(array $row): self
    {
        return new self(
            $row['sessions'],
            $row['distanceM'],
            $row['movingSeconds'],
            $row['elevationM'],
            $row['maxDistanceM'],
            $row['maxElevationM'],
            $row['hrWeighted'],
            $row['hrSeconds'],
            $row['paceDistanceM'],
            $row['paceSeconds'],
        );
    }

    public function plus(self $other): self
    {
        return new self(
            $this->sessions + $other->sessions,
            $this->distanceM + $other->distanceM,
            $this->movingSeconds + $other->movingSeconds,
            $this->elevationM + $other->elevationM,
            max($this->maxDistanceM, $other->maxDistanceM),
            max($this->maxElevationM, $other->maxElevationM),
            $this->hrWeighted + $other->hrWeighted,
            $this->hrSeconds + $other->hrSeconds,
            $this->paceDistanceM + $other->paceDistanceM,
            $this->paceSeconds + $other->paceSeconds,
        );
    }

    /** FC moyenne pondérée par le temps en mouvement, null sans activité avec FC. */
    public function averageHeartrate(): ?float
    {
        return $this->hrSeconds > 0 ? $this->hrWeighted / $this->hrSeconds : null;
    }

    /** Allure moyenne en secondes par km (temps total / distance totale), null sans distance. */
    public function paceSecondsPerKm(): ?float
    {
        return $this->paceDistanceM > 0 && $this->paceSeconds > 0 ? $this->paceSeconds / $this->paceDistanceM * 1000 : null;
    }

    /** Vitesse moyenne en km/h (distance totale / temps total), null sans temps. */
    public function speedKmh(): ?float
    {
        return $this->paceDistanceM > 0 && $this->paceSeconds > 0 ? $this->paceDistanceM / $this->paceSeconds * 3.6 : null;
    }
}
