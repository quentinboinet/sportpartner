<?php

namespace App\Tests\Unit\Service\Nutrition;

use App\Service\Nutrition\RaceNutritionCalculator;
use App\Service\Nutrition\RaceNutritionCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Liste « à emporter » (RaceNutritionCalculator::carryList()) : aliments emportés du départ jusqu'au premier point
 * d'assistance, récupérés à chaque assistance pour la suite, pris sur place aux ravitos officiels ; boissons à
 * remplir entre deux points d'eau, en flasques (arrondi au-dessus).
 *
 * @phpstan-import-type Input from RaceNutritionCalculator
 */
final class RaceNutritionCarryListTest extends TestCase
{
    /**
     * Départ, Col (point), Ravito (officiel), Base (assistance), arrivée ; tout est saisi à la main.
     *
     * @param array<string, list<array{key: string, qty: int}>> $manual
     *
     * @return Input
     */
    private static function input(array $manual, bool $baseIsAid = false): array
    {
        $settings = RaceNutritionCatalog::defaultSettings(null);
        unset($settings['flaskMl']);

        return [
            'settings' => $settings,
            'products' => RaceNutritionCatalog::calculatorProducts(RaceNutritionCatalog::products([
                ['key' => 'custom_1', 'kind' => 'drink', 'cat' => 'drink', 'label' => 'Coca', 'carbs' => 53.0],
            ], [])),
            'points' => [
                ['anchor' => 'start', 'type' => 'start', 'assistance' => false],
                ['anchor' => '1', 'type' => 'point', 'assistance' => false],
                ['anchor' => '2', 'type' => 'aid_station', 'assistance' => false],
                ['anchor' => '3', 'type' => $baseIsAid ? 'aid_station' : 'point', 'assistance' => true],
            ],
            'segments' => [
                ['anchor' => '1', 'seconds' => null],
                ['anchor' => '2', 'seconds' => null],
                ['anchor' => '3', 'seconds' => null],
                ['anchor' => 'finish', 'seconds' => null],
            ],
            'manual' => $manual,
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function carry(array $manual, int $flask = 500, bool $baseIsAid = false): array
    {
        $calc = new RaceNutritionCalculator();
        $input = self::input($manual, $baseIsAid);

        return $calc->carryList($input, $calc->compute($input), $flask);
    }

    #[Test]
    public function foodIsCarriedFromTheStartUntilTheFirstCrewPoint(): void
    {
        $stops = self::carry([
            'p:start' => [['key' => 'gel', 'qty' => 1]],
            's:1' => [['key' => 'gel', 'qty' => 2]],
            'p:1' => [['key' => 'bar', 'qty' => 1]],            // point simple : pris dans ses poches
            's:2' => [['key' => 'gel', 'qty' => 1]],
            'p:2' => [['key' => 'bar', 'qty' => 1]],            // ravito officiel : sur place
            's:3' => [['key' => 'puree', 'qty' => 1]],
            'p:3' => [['key' => 'bar', 'qty' => 2]],            // assistance : dans son sac
            's:finish' => [['key' => 'gel', 'qty' => 3]],
        ]);

        self::assertSame(['start', '2', '3'], array_column($stops, 'anchor'), 'start, aid stations and crew points only');
        self::assertSame([['key' => 'gel', 'qty' => 4], ['key' => 'puree', 'qty' => 1], ['key' => 'bar', 'qty' => 1]], $stops[0]['carry']);
        self::assertSame([['key' => 'gel', 'qty' => 1]], $stops[0]['eatHere']);
        self::assertNull($stops[1]['carry'], 'official aid station: no own products');
        self::assertSame([['key' => 'bar', 'qty' => 1]], $stops[1]['eatHere'], 'eaten on site');
        self::assertSame([['key' => 'gel', 'qty' => 3], ['key' => 'bar', 'qty' => 2]], $stops[2]['carry'], 'crew point: what is eaten there + the next legs');
        self::assertTrue($stops[2]['assistance']);
    }

    #[Test]
    public function drinksAreFilledForTheStretchUntilTheNextWaterPoint(): void
    {
        $stops = self::carry([
            's:1' => [['key' => 'isotonic', 'qty' => 500], ['key' => 'water', 'qty' => 250]],
            'p:1' => [['key' => 'water', 'qty' => 250]],        // point simple : bu depuis ses flasques
            's:2' => [['key' => 'isotonic', 'qty' => 250]],
            'p:2' => [['key' => 'custom_1', 'qty' => 250]],     // coca au ravito : sur place
            's:3' => [['key' => 'water', 'qty' => 1250]],
            's:finish' => [['key' => 'isotonic', 'qty' => 500]],
        ]);

        self::assertSame([['key' => 'water', 'ml' => 500, 'flasks' => 1], ['key' => 'isotonic', 'ml' => 750, 'flasks' => 2]], $stops[0]['fill']);
        self::assertSame(3, $stops[0]['flasks']);
        self::assertSame([['key' => 'water', 'ml' => 1250, 'flasks' => 3]], $stops[1]['fill'], 'refill at the aid station for the next stretch');
        self::assertSame([['key' => 'custom_1', 'qty' => 250]], $stops[1]['eatHere']);
        self::assertSame([['key' => 'isotonic', 'ml' => 500, 'flasks' => 1]], $stops[2]['fill'], 'crew point is a water point too');

        $big = self::carry(['s:1' => [['key' => 'water', 'qty' => 1500]]], 750);
        self::assertSame([['key' => 'water', 'ml' => 1500, 'flasks' => 2]], $big[0]['fill'], 'flask size used');
    }

    #[Test]
    public function aStopWithNothingStillListsItsEmptyLists(): void
    {
        $stops = self::carry([]);
        self::assertSame(['start', '2', '3'], array_column($stops, 'anchor'));
        self::assertSame([], $stops[0]['carry']);
        self::assertSame([], $stops[0]['fill']);
        self::assertSame(0, $stops[0]['flasks']);
        self::assertSame('aid_station', $stops[1]['type']);
    }

    #[Test]
    public function crewPointThatIsAlsoAnAidStationKeepsBothRoles(): void
    {
        $stops = self::carry(['p:3' => [['key' => 'bar', 'qty' => 1], ['key' => 'water', 'qty' => 250]], 's:finish' => [['key' => 'gel', 'qty' => 2]]], 500, true);
        self::assertSame('aid_station', $stops[2]['type']);
        self::assertTrue($stops[2]['assistance']);
        self::assertSame([['key' => 'gel', 'qty' => 2], ['key' => 'bar', 'qty' => 1]], $stops[2]['carry']);
        self::assertSame([['key' => 'bar', 'qty' => 1], ['key' => 'water', 'qty' => 250]], $stops[2]['eatHere']);
    }
}
