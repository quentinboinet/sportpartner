<?php

namespace App\Entity;

/** Dosage d'un exercice : en répétitions (bouton « Fait ») ou en secondes (compte à rebours). */
enum HealthDosageMode: string
{
    case Reps = 'reps';
    case Time = 'time';

    /** Bornes de la valeur d'une série : répétitions, ou secondes. */
    public function minValue(): int
    {
        return match ($this) {
            self::Reps => 1,
            self::Time => 5,
        };
    }

    public function maxValue(): int
    {
        return match ($this) {
            self::Reps => 100,
            self::Time => 600,
        };
    }
}
