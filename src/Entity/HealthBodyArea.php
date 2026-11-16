<?php

namespace App\Entity;

/** Zone du corps travaillée par un exercice de l'espace Santé (un exercice en a au moins une). */
enum HealthBodyArea: string
{
    case FootAnkle = 'foot_ankle';
    case LowerLeg = 'lower_leg';
    case Thigh = 'thigh';
    case Hip = 'hip';
    case Core = 'core';
    case Back = 'back';
    case Shoulders = 'shoulders';

    /** Clé de traduction du libellé (health.area.*). */
    public function labelKey(): string
    {
        return 'health.area.'.$this->value;
    }
}
