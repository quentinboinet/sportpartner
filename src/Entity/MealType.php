<?php
namespace App\Entity;

enum MealType: string
{
    case Breakfast    = 'breakfast';
    case MorningSnack = 'morning_snack';
    case Lunch        = 'lunch';
    case PreWorkout   = 'pre_workout';
    case Dinner       = 'dinner';
    case EveningSnack = 'evening_snack';
}
