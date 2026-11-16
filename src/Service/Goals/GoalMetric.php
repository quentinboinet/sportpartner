<?php

namespace App\Service\Goals;

/**
 * Avancement d'une cible de la semaine (distance en km, séances, durée en minutes ou D+ en m).
 * Valeurs réelles, même au-delà de la cible ; seule la largeur de barre est plafonnée à 100 %.
 */
final class GoalMetric
{
    public const DISTANCE  = 'distance';
    public const SESSIONS  = 'sessions';
    public const DURATION  = 'duration';
    public const ELEVATION = 'elevation';

    public function __construct(
        public readonly string $kind,
        /** Réalisé : km au dixième, sinon entier (séances, minutes, mètres). */
        public readonly float|int $current,
        public readonly float|int $target,
    ) {
    }

    /** Réalisé / cible, sans plafond (1.2 = 120 %). */
    public function ratio(): float
    {
        return $this->target > 0 ? $this->current / $this->target : 0.0;
    }

    /** Pourcentage réel arrondi, peut dépasser 100. */
    public function percent(): int
    {
        return (int) round($this->ratio() * 100);
    }

    /** Largeur de la barre en % : arrondie vers le bas (pleine seulement si atteint), plafonnée à 100. */
    public function barPercent(): int
    {
        return $this->isReached() ? 100 : min(99, (int) floor($this->ratio() * 100));
    }

    public function isReached(): bool
    {
        return $this->current >= $this->target;
    }

    /** Reste à faire (0 si atteint). */
    public function remaining(): float|int
    {
        $left = max(0, $this->target - $this->current);

        return $this->kind === self::DISTANCE ? round((float) $left, 1) : (int) $left;
    }
}
