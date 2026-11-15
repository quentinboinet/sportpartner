<?php
namespace App\Entity;

enum CheckpointType: string
{
    case Point = 'point';
    case AidStation = 'aid_station';

    /** Clé de traduction du libellé (race_plan.type_*). */
    public function labelKey(): string
    {
        return 'race_plan.type_'.$this->value;
    }

    /** Classe Bootstrap Icons. */
    public function icon(): string
    {
        return match ($this) {
            self::Point => 'bi-geo-alt-fill',
            self::AidStation => 'bi-cup-hot-fill',
        };
    }

    /** Variable CSS de couleur (définie dans assets/styles/app.css). */
    public function color(): string
    {
        return match ($this) {
            self::Point => 'var(--sp-dark)',
            self::AidStation => 'var(--sp-teal)',
        };
    }
}
