<?php

namespace App\Tests\Unit\Service\Nutrition;

use App\Entity\ActivityFuel;
use App\Entity\FuelKind;
use App\Service\Nutrition\ActivityFuelViewBuilder;
use App\Service\Nutrition\FuelCatalog;
use App\Service\Nutrition\FuelItem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ActivityFuelViewBuilderTest extends TestCase
{
    /**
     * @param list<FuelItem> $items
     *
     * @return list<string>
     */
    private static function keys(array $items): array
    {
        return array_map(static fn (FuelItem $i): string => $i->key, $items);
    }

    #[Test]
    public function showsEveryBuiltInItemWithItsDefaultCarbs(): void
    {
        $items = ActivityFuelViewBuilder::displayItems([], []);

        self::assertSame(['gel', 'puree', 'fruit_paste', 'gummies', 'bar', 'water', 'isotonic', 'energy_drink'], self::keys($items));
        self::assertSame([25.0, 22.0, 18.0, 24.0, 35.0, 0.0, 30.0, 45.0], array_map(static fn (FuelItem $i): float => $i->carbs, $items));
        self::assertSame([0], array_values(array_unique(array_map(static fn (FuelItem $i): int => $i->quantity, $items))));
        self::assertSame(FuelKind::Drink, $items[5]->kind);
    }

    #[Test]
    public function storedRowsWinOverRememberedCarbsWhichWinOverDefaults(): void
    {
        $stored = [
            new FuelItem(FuelKind::Food, 'gel', 2, 25.0),
            new FuelItem(FuelKind::Drink, 'water', 500, 0.0),
        ];
        // Ailleurs, l'utilisateur a mis gel = 22 g et barre = 40 g
        $items = ActivityFuelViewBuilder::displayItems($stored, ['gel' => 22.0, 'bar' => 40.0]);
        $byKey = array_combine(self::keys($items), $items);

        self::assertSame(25.0, $byKey['gel']->carbs, 'the activity keeps its own value');
        self::assertSame(2, $byKey['gel']->quantity);
        self::assertSame(40.0, $byKey['bar']->carbs, 'remembered value proposed');
        self::assertSame(0, $byKey['bar']->quantity);
        self::assertSame(22.0, $byKey['puree']->carbs, 'default from the catalog');
        self::assertSame(500, $byKey['water']->quantity);
    }

    #[Test]
    public function customItemsFollowTheBuiltInOnesOfTheirKindByNumber(): void
    {
        $stored = [
            new FuelItem(FuelKind::Food, 'custom_10', 1, 30.0, 'Riz'),
            new FuelItem(FuelKind::Drink, 'custom_3', 250, 53.0, 'Coca'),
            new FuelItem(FuelKind::Food, 'custom_2', 0, 27.0, 'Banane'),
            new FuelItem(FuelKind::Food, 'old_item', 3, 10.0), // clé retirée du catalogue : ignorée
        ];
        $items = ActivityFuelViewBuilder::displayItems($stored, []);

        self::assertSame(
            ['gel', 'puree', 'fruit_paste', 'gummies', 'bar', 'custom_2', 'custom_10', 'water', 'isotonic', 'energy_drink', 'custom_3'],
            self::keys($items),
        );
        self::assertSame('Banane', $items[5]->label);
        self::assertSame('bi-basket', $items[5]->icon());
        self::assertSame('bi-cup', $items[10]->icon());
    }

    #[Test]
    public function entityRoundTrip(): void
    {
        $item = new FuelItem(FuelKind::Drink, 'custom_4', 750, 32.5, 'Sirop');
        $back = (new ActivityFuel())
            ->setKind($item->kind)->setItemKey($item->key)->setLabel($item->label)
            ->setQuantity($item->quantity)->setCarbsPerUnit($item->carbs)
            ->toItem();

        self::assertEquals($item, $back);
    }

    #[Test]
    public function catalogRules(): void
    {
        foreach (['run', 'trail', 'ride', 'walk', 'hike', 'other', null] as $slug) {
            self::assertTrue(FuelCatalog::supportsSportSlug($slug), (string) $slug);
        }
        self::assertFalse(FuelCatalog::supportsSportSlug('weight_training'));
        self::assertFalse(FuelCatalog::supportsSportSlug('swim'));

        self::assertTrue(FuelCatalog::isCustomKey('custom_1'));
        self::assertTrue(FuelCatalog::isCustomKey('custom_999'));
        self::assertFalse(FuelCatalog::isCustomKey('custom_0'));
        self::assertFalse(FuelCatalog::isCustomKey('custom_1000'));
        self::assertFalse(FuelCatalog::isCustomKey('gel'));
        self::assertSame(12, FuelCatalog::customNumber('custom_12'));
        self::assertSame(0, FuelCatalog::customNumber('gel'));
        self::assertSame(['water', 'isotonic', 'energy_drink'], FuelCatalog::builtInKeys(FuelKind::Drink));
    }
}
