<?php

namespace App\Service\Health;

/** Exercice d'une séance envoyée : dosage prévu et valeur faite pour chaque série terminée. */
final class HealthSessionPayloadExercise
{
    /** @param list<int> $performed */
    public function __construct(
        public readonly int $exerciseId,
        public readonly int $sets,
        public readonly int $value,
        public readonly int $rest,
        public readonly array $performed,
    ) {
    }
}
