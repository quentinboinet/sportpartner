<?php
namespace App\Entity;

/**
 * Intensité d'une stratégie d'allure (PacingStrategy) : choisit son coefficient (légère, moyenne, marquée).
 * Sans effet pour "Régulier".
 */
enum PacingIntensity: string
{
    case Light = 'light';
    case Medium = 'medium';
    case Strong = 'strong';

    /** Clé de traduction du libellé (race_plan.pacing_intensity_*). */
    public function labelKey(): string
    {
        return 'race_plan.pacing_intensity_'.$this->value;
    }

    /** Valeur postée -> intensité ; null si elle n'est pas une chaîne ou n'existe pas (comparaison exacte). */
    public static function tryFromInput(mixed $value): ?self
    {
        return \is_string($value) ? self::tryFrom($value) : null;
    }
}
