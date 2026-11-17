<?php

namespace App\Service\Activity;

/** Saisie d'une activité manuelle une fois validée (ManualActivityForm::values()). */
final class ManualActivityValues
{
    public function __construct(
        /** Nom sur une ligne ; vide = nom par défaut. */
        public readonly string $name,
        public readonly \DateTimeImmutable $startUtc,
        public readonly string $timezone,
        public readonly int $movingSeconds,
        public readonly int $elapsedSeconds,
        public readonly ?float $distanceMeters,
        public readonly ?int $elevationMeters,
        public readonly ?int $hrAvg,
        public readonly ?int $hrMax,
        /** Matériel par défaut du type lié au sport (comme la synchro Strava). */
        public readonly bool $gearAuto,
        /** Matériel choisi (null : aucun, ou par défaut si $gearAuto). */
        public readonly ?int $gearId,
    ) {
    }
}
