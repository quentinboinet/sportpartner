<?php

namespace App\Service\Health;

/** Séance envoyée par health_session_controller.js, validée par HealthSessionPayloadValidator. */
final class HealthSessionPayload
{
    public const SPORT_STRENGTH = 'weight_training';
    public const SPORT_OTHER = 'other';

    /** @param list<HealthSessionPayloadExercise> $exercises */
    public function __construct(
        /** Nom saisi (null : nom par défaut selon le contenu de la séance). */
        public readonly ?string $name,
        /** Début, en UTC. */
        public readonly \DateTimeImmutable $startedAt,
        public readonly ?string $timezone,
        public readonly int $durationSeconds,
        public readonly int $elapsedSeconds,
        public readonly ?int $rpe,
        public readonly ?string $notes,
        public readonly bool $addActivity,
        /** SPORT_STRENGTH, SPORT_OTHER, ou null : règle automatique (HealthActivityMapper::sportSlug()). */
        public readonly ?string $sport,
        public readonly array $exercises,
    ) {
    }
}
