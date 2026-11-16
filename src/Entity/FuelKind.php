<?php

namespace App\Entity;

/** Nature d'un apport du ravitaillement d'une activité (colonne activityFuel.kind). */
enum FuelKind: string
{
    /** Aliment compté à l'unité (gel, barre...) ; glucides par unité. */
    case Food = 'food';

    /** Boisson comptée en ml ; glucides pour 500 ml. */
    case Drink = 'drink';
}
