<?php

namespace App\Entity;

/**
 * Catégorie d'un exercice de l'espace Santé. L'ordre des cas est l'ordre d'affichage de la bibliothèque.
 *
 * Renfo (strength, core, balance) ou souplesse (mobility, stretching) : décide du sport de l'activité créée par
 * une séance (voir App\Service\Health\HealthActivityMapper).
 */
enum HealthCategory: string
{
    case Strength = 'strength';
    case Core = 'core';
    case Balance = 'balance';
    case Mobility = 'mobility';
    case Stretching = 'stretching';

    /** Clé de traduction du libellé (health.category.*). */
    public function labelKey(): string
    {
        return 'health.category.'.$this->value;
    }

    /** Classe Bootstrap Icons. */
    public function icon(): string
    {
        return match ($this) {
            self::Strength   => 'bi-lightning-charge',
            self::Core       => 'bi-shield-check',
            self::Balance    => 'bi-bullseye',
            self::Mobility   => 'bi-arrow-repeat',
            self::Stretching => 'bi-arrows-expand',
        };
    }

    /** Travail de renforcement (renfo, gainage, équilibre), par opposition à la mobilité et aux étirements. */
    public function isStrengthWork(): bool
    {
        return match ($this) {
            self::Strength, self::Core, self::Balance => true,
            self::Mobility, self::Stretching          => false,
        };
    }
}
