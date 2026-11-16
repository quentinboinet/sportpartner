<?php

namespace App\Service\Nutrition;

/** Position du débit de glucides (g/h arrondi) par rapport à la cible d'ActivityFuelCalculator. */
enum FuelTargetStatus: string
{
    case Below = 'below';
    case Within = 'within';
    case Above = 'above';

    /** Durée de l'activité inconnue : débit non calculable. */
    case None = 'none';
}
