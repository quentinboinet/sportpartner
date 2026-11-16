<?php

namespace App\Entity;

/** Niveau d'un exercice de l'espace Santé. */
enum HealthLevel: string
{
    case Beginner = 'beginner';
    case Intermediate = 'intermediate';
    case Advanced = 'advanced';

    /** Clé de traduction du libellé (health.level.*). */
    public function labelKey(): string
    {
        return 'health.level.'.$this->value;
    }
}
