<?php

namespace App\Service\Notifications;

/**
 * Activité récente réduite à ce que la liste « À faire » regarde : matériel rattaché et ravitaillement
 * noté (ActivityRepository::findRecentTodoRows(), une seule requête, sans les flux JSON).
 */
final class RecentActivityRow
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $name,
        public readonly \DateTimeImmutable $startDate,
        public readonly ?string $sportSlug,
        public readonly ?int $movingSeconds,
        public readonly ?int $elapsedSeconds,
        public readonly bool $hasGear,
        public readonly bool $hasFuel,
        /** Activité créée par une séance de l'espace Santé (pas de carte Ravitaillement). */
        public readonly bool $fromHealthSession = false,
    ) {
    }
}
