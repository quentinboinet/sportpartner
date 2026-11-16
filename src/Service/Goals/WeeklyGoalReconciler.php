<?php

namespace App\Service\Goals;

use App\Entity\Sport;
use App\Entity\WeeklyGoal;

/**
 * Règles de la liste "objectifs par sport" saisie dans les paramètres (CollectionType weeklyGoals).
 */
final class WeeklyGoalReconciler
{
    /**
     * Lignes après saisie, prêtes à être écrites dans User::weeklyGoals :
     *  - un sport retiré puis rajouté dans la même saisie réutilise l'objectif enregistré (cibles
     *    de la nouvelle ligne recopiées) : sinon Doctrine insérerait la nouvelle ligne avant de
     *    supprimer l'ancienne et l'index unique (user_id, sport_id) refuserait l'insertion ;
     *  - position = ordre des lignes dans le formulaire.
     *
     * @param iterable<WeeklyGoal>             $original  objectifs avant la saisie
     * @param iterable<array-key, WeeklyGoal>  $submitted lignes après la saisie, dans l'ordre (clés conservées)
     *
     * @return array<array-key, WeeklyGoal>
     */
    public static function reconcile(iterable $original, iterable $submitted): array
    {
        $rows = [];
        foreach ($submitted as $key => $goal) {
            $rows[$key] = $goal;
        }

        $originals = [];
        foreach ($original as $goal) {
            $originals[] = $goal;
        }
        $removed = array_values(array_filter($originals, static fn (WeeklyGoal $g): bool => !\in_array($g, $rows, true)));

        foreach ($rows as $key => $goal) {
            if (\in_array($goal, $originals, true) || $goal->getSport() === null) {
                continue;
            }
            foreach ($removed as $i => $old) {
                if (self::sameSport($old->getSport(), $goal->getSport())) {
                    $rows[$key] = $old->copyTargetsFrom($goal);
                    unset($removed[$i]);
                    break;
                }
            }
        }

        $position = 0;
        foreach ($rows as $goal) {
            $goal->setPosition($position++);
        }

        return $rows;
    }

    /**
     * Clés des lignes dont le sport figure déjà sur une ligne précédente.
     *
     * @param iterable<array-key, WeeklyGoal> $goals
     *
     * @return list<array-key>
     */
    public static function duplicateKeys(iterable $goals): array
    {
        $seen       = [];
        $duplicates = [];
        foreach ($goals as $key => $goal) {
            $sport = $goal->getSport();
            if ($sport === null) {
                continue;
            }
            foreach ($seen as $other) {
                if (self::sameSport($other, $sport)) {
                    $duplicates[] = $key;
                    continue 2;
                }
            }
            $seen[] = $sport;
        }

        return $duplicates;
    }

    private static function sameSport(?Sport $a, ?Sport $b): bool
    {
        if ($a === null || $b === null) {
            return false;
        }

        return $a === $b || ($a->getId() !== null && $a->getId() === $b->getId());
    }
}
