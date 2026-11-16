<?php

namespace App\Service\Gear;

/**
 * Usage et usure d'un matériel : distance cumulée (avant suivi + activités rattachées)
 * rapportée à sa durée de vie.
 */
final class GearUsage
{
    /** À partir de ce ratio, le matériel approche de sa fin de vie. */
    public const WARN_RATIO = 0.8;
    /** À partir de ce ratio, le matériel a atteint sa durée de vie. */
    public const WORN_RATIO = 1.0;

    public const STATUS_OK   = 'ok';
    public const STATUS_WARN = 'warn';
    public const STATUS_WORN = 'worn';
    public const STATUS_NONE = 'none';

    public function __construct(
        public readonly int $activitiesCount,
        public readonly float $distanceM,
        public readonly int $movingSeconds,
        public readonly ?\DateTimeImmutable $lastUsedAt,
        public readonly int $initialDistanceM,
        public readonly ?int $maxDistanceM,
    ) {
    }

    /** Distance totale en mètres : déjà parcourue avant le suivi + activités rattachées. */
    public function totalDistanceM(): float
    {
        return max(0, $this->initialDistanceM) + max(0.0, $this->distanceM);
    }

    public function totalDistanceKm(): float
    {
        return $this->totalDistanceM() / 1000;
    }

    /** Part de la durée de vie consommée (peut dépasser 1), null si l'usure n'est pas suivie. */
    public function wearRatio(): ?float
    {
        if ($this->maxDistanceM === null || $this->maxDistanceM <= 0) {
            return null;
        }

        return $this->totalDistanceM() / $this->maxDistanceM;
    }

    /** Pourcentage d'usure arrondi (non borné), null si non suivi. */
    public function wearPercent(): ?int
    {
        $ratio = $this->wearRatio();

        return $ratio === null ? null : (int) floor($ratio * 100);
    }

    /** Largeur de la barre de progression, bornée à 0-100. */
    public function wearBarPercent(): int
    {
        return min(100, max(0, $this->wearPercent() ?? 0));
    }

    /** 'ok' (< 80 %), 'warn' (>= 80 %), 'worn' (>= 100 %) ou 'none' (usure non suivie). */
    public function status(): string
    {
        $ratio = $this->wearRatio();

        return match (true) {
            $ratio === null              => self::STATUS_NONE,
            $ratio >= self::WORN_RATIO   => self::STATUS_WORN,
            $ratio >= self::WARN_RATIO   => self::STATUS_WARN,
            default                      => self::STATUS_OK,
        };
    }

    /** Distance restante avant la fin de vie, en km (0 si dépassée), null si non suivi. */
    public function remainingKm(): ?float
    {
        if ($this->maxDistanceM === null || $this->maxDistanceM <= 0) {
            return null;
        }

        return max(0.0, ($this->maxDistanceM - $this->totalDistanceM()) / 1000);
    }
}
