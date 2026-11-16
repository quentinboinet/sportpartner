<?php

namespace App\Tests\Unit\Service\Nutrition;

use App\Service\Nutrition\RaceNutritionRows;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Règles des lignes manuelles quand un repère est supprimé (RaceNutritionRows) : rien n'est perdu, les apports du point
 * et du tronçon qui y arrivait passent sur le tronçon suivant.
 */
final class RaceNutritionRowsTest extends TestCase
{
    private const KINDS = ['gel' => 'food', 'bar' => 'food', 'water' => 'drink', 'isotonic' => 'drink'];

    #[Test]
    public function pointAndIncomingLegAreMergedIntoTheNextLeg(): void
    {
        $manual = [
            'p:5' => [['key' => 'bar', 'qty' => 1]],
            's:5' => [['key' => 'gel', 'qty' => 2], ['key' => 'water', 'qty' => 500]],
            's:7' => [['key' => 'gel', 'qty' => 1]],
            'p:start' => [],
        ];
        $result = RaceNutritionRows::afterCheckpointDeleted($manual, 5, 7, self::KINDS);

        self::assertTrue($result['moved']);
        self::assertSame('s:7', $result['target']);
        self::assertSame(['s:7', 'p:start'], array_keys($result['manual']), 'rows of the deleted checkpoint removed');
        self::assertSame([['key' => 'gel', 'qty' => 3], ['key' => 'bar', 'qty' => 1], ['key' => 'water', 'qty' => 500]], $result['manual']['s:7'], 'summed per product, existing lines first');
        self::assertSame([], $result['manual']['p:start'], 'other rows untouched');
    }

    #[Test]
    public function lastCheckpointMovesToTheFinishLegWhichBecomesManual(): void
    {
        $result = RaceNutritionRows::afterCheckpointDeleted(['s:9' => [['key' => 'isotonic', 'qty' => 750]]], 9, null, self::KINDS);

        self::assertSame('s:finish', $result['target']);
        self::assertSame(['s:finish' => [['key' => 'isotonic', 'qty' => 750]]], $result['manual'], 'auto finish leg becomes manual with the moved items');
    }

    #[Test]
    public function emptyManualRowIsMovedTooAndNothingHappensWithoutRows(): void
    {
        $result = RaceNutritionRows::afterCheckpointDeleted(['p:3' => []], 3, 4, self::KINDS);
        self::assertTrue($result['moved']);
        self::assertSame(['s:4' => []], $result['manual'], 'a manual "nothing here" stays an explicit choice on the next leg');

        $result = RaceNutritionRows::afterCheckpointDeleted(['s:4' => [['key' => 'gel', 'qty' => 1]]], 3, 4, self::KINDS);
        self::assertFalse($result['moved']);
        self::assertSame(['s:4' => [['key' => 'gel', 'qty' => 1]]], $result['manual']);
    }

    #[Test]
    public function mergedQuantitiesAreCappedToTheInputLimits(): void
    {
        $merged = RaceNutritionRows::merge(
            [['key' => 'gel', 'qty' => 80], ['key' => 'water', 'qty' => 15000]],
            [['key' => 'gel', 'qty' => 50], ['key' => 'water', 'qty' => 10000], ['key' => 'bar', 'qty' => 0]],
            self::KINDS,
        );
        self::assertSame([['key' => 'gel', 'qty' => 100], ['key' => 'water', 'qty' => 20000]], $merged);
    }
}
