<?php

namespace App\Service\Notifications;

/**
 * Course « Je suis inscrit » datée et non faite, avec l'état de son plan (RaceRepository::findTodoRows(),
 * une seule requête, sans charger le tracé GPX).
 */
final class RaceTodoRow
{
    /** Ni parcours GPX, ni repère, ni scénario. */
    public const PLAN_NONE = 'none';
    /** Un parcours, des repères ou un scénario, mais aucun scénario avec un temps visé. */
    public const PLAN_NO_SPLITS = 'nosplits';
    /** Au moins un scénario avec un temps visé : les temps de passage sont calculés. */
    public const PLAN_READY = 'ready';

    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly \DateTimeImmutable $date,
        public readonly ?string $location,
        public readonly bool $hasRoute,
        public readonly int $checkpoints,
        public readonly int $scenarios,
        public readonly int $timedScenarios,
    ) {
    }

    public function planState(): string
    {
        if ($this->timedScenarios > 0) {
            return self::PLAN_READY;
        }

        return $this->hasRoute || $this->checkpoints > 0 || $this->scenarios > 0 ? self::PLAN_NO_SPLITS : self::PLAN_NONE;
    }

    /** Lieu connu pour la météo du jour J : départ du GPX, sinon lieu saisi (voir RaceWeatherService). */
    public function hasPlace(): bool
    {
        return $this->hasRoute || trim((string) $this->location) !== '';
    }
}
