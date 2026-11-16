<?php

namespace App\Tests\Unit\Service\Goals;

use App\Entity\Sport;
use App\Entity\WeeklyGoal;
use App\Service\Goals\WeeklyGoalReconciler;
use PHPUnit\Framework\TestCase;

class WeeklyGoalReconcilerTest extends TestCase
{
    private static function sport(int $id, string $slug): Sport
    {
        $sport = (new Sport())->setSlug($slug)->setIcon('bi-x')->setColor('#000000');
        (new \ReflectionProperty(Sport::class, 'id'))->setValue($sport, $id);

        return $sport;
    }

    public function testRemovedThenReAddedSportReusesTheSavedGoal(): void
    {
        $run   = self::sport(1, 'run');
        $ride  = self::sport(3, 'ride');
        $saved = (new WeeklyGoal())->setSport($run)->setDistanceKm(30)->setSessions(3);
        $kept  = (new WeeklyGoal())->setSport($ride)->setDistanceKm(150);
        // La ligne "Course" a été retirée puis rajoutée (nouvelle ligne, autre objet Sport de même id)
        $readded = (new WeeklyGoal())->setSport(self::sport(1, 'run'))->setDistanceKm(40);

        $rows = WeeklyGoalReconciler::reconcile([$saved, $kept], ['11' => $kept, 'new0' => $readded]);

        self::assertSame(['11', 'new0'], array_map('strval', array_keys($rows)));
        self::assertSame($kept, $rows['11']);
        self::assertSame($saved, $rows['new0'], "l'objectif enregistré est réutilisé (pas d'insertion en double)");
        self::assertSame(40.0, $saved->getDistanceKm());
        self::assertNull($saved->getSessions(), 'cibles de la nouvelle ligne recopiées, y compris les vides');
        self::assertSame([0, 1], [$kept->getPosition(), $saved->getPosition()], 'position = ordre des lignes');
    }

    public function testNewGoalForASportStillPresentIsADuplicate(): void
    {
        $run   = self::sport(1, 'run');
        $saved = (new WeeklyGoal())->setSport($run)->setDistanceKm(30);
        $dup   = (new WeeklyGoal())->setSport($run)->setDistanceKm(12);

        $rows = WeeklyGoalReconciler::reconcile([$saved], ['10' => $saved, 'new0' => $dup]);

        self::assertSame($dup, $rows['new0'], 'pas de fusion avec un objectif toujours présent');
        self::assertSame(30.0, $saved->getDistanceKm());
        self::assertSame(['new0'], WeeklyGoalReconciler::duplicateKeys($rows));
    }

    public function testNewGoalsAndPositions(): void
    {
        $a = (new WeeklyGoal())->setSport(self::sport(1, 'run'))->setSessions(2);
        $b = (new WeeklyGoal())->setSport(self::sport(4, 'swim'))->setSessions(1);
        $c = (new WeeklyGoal())->setSessions(1); // sport manquant : laissé tel quel (erreur de validation)

        $rows = WeeklyGoalReconciler::reconcile([], ['new2' => $b, 'new0' => $a, 'new5' => $c]);

        self::assertSame([$b, $a, $c], array_values($rows));
        self::assertSame([0, 1, 2], [$b->getPosition(), $a->getPosition(), $c->getPosition()]);
        self::assertSame([], WeeklyGoalReconciler::duplicateKeys($rows));
    }

    public function testDuplicateKeysComparesSportIdsAndIgnoresMissingSport(): void
    {
        $goals = [
            'a' => (new WeeklyGoal())->setSport(self::sport(1, 'run')),
            'b' => (new WeeklyGoal()),
            'c' => (new WeeklyGoal())->setSport(self::sport(3, 'ride')),
            'd' => (new WeeklyGoal())->setSport(self::sport(1, 'run')),
            'e' => (new WeeklyGoal())->setSport(self::sport(1, 'run')),
        ];

        self::assertSame(['d', 'e'], WeeklyGoalReconciler::duplicateKeys($goals));
    }
}
