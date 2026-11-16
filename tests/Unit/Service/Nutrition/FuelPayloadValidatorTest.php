<?php

namespace App\Tests\Unit\Service\Nutrition;

use App\Entity\FuelKind;
use App\Service\Nutrition\FuelPayloadValidator;
use App\Service\Nutrition\InvalidFuelPayloadException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FuelPayloadValidatorTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function item(string $kind, string $key, mixed $quantity, mixed $carbs, mixed $label = null, bool $withLabel = false): array
    {
        $item = ['kind' => $kind, 'key' => $key, 'quantity' => $quantity, 'carbs' => $carbs];
        if ($withLabel || $label !== null) {
            $item['label'] = $label;
        }

        return $item;
    }

    #[Test]
    public function acceptsTheWholeCardState(): void
    {
        $items = (new FuelPayloadValidator())->validate(['items' => [
            self::item('food', 'gel', 2, 22),
            self::item('food', 'puree', 0, 22),
            self::item('food', 'custom_1', 1, 27.5, '  Banane   plantain '),
            self::item('drink', 'water', 500, 0),
            self::item('drink', 'energy_drink', 0, 45.0),
            self::item('drink', 'custom_2', 250, 53, 'Coca'),
        ]]);

        self::assertCount(6, $items);
        self::assertSame(FuelKind::Food, $items[0]->kind);
        self::assertSame('gel', $items[0]->key);
        self::assertSame(2, $items[0]->quantity);
        self::assertSame(22.0, $items[0]->carbs);
        self::assertNull($items[0]->label);
        self::assertSame('Banane plantain', $items[2]->label, 'spaces normalized');
        self::assertTrue($items[2]->isCustom());
        self::assertSame(FuelKind::Drink, $items[5]->kind);
        self::assertSame(250, $items[5]->quantity);

        // Lignes à stocker : quantité > 0, ou apport personnalisé
        $storable = array_values(array_filter($items, static fn ($i) => $i->isStorable()));
        self::assertSame(['gel', 'custom_1', 'water', 'custom_2'], array_map(static fn ($i) => $i->key, $storable));
    }

    #[Test]
    public function acceptsAnEmptyListAndNullLabelsOnBuiltInItems(): void
    {
        $validator = new FuelPayloadValidator();
        self::assertSame([], $validator->validate(['items' => []]));

        $items = $validator->validate(['items' => [self::item('food', 'gel', 1, 25, null, true)]]);
        self::assertNull($items[0]->label);
    }

    #[Test]
    public function roundsCarbsToTheTenthAndAcceptsLimits(): void
    {
        $items = (new FuelPayloadValidator())->validate(['items' => [
            self::item('food', 'gel', 100, 22.44),
            self::item('food', 'bar', 0, 22.46),
            self::item('food', 'gummies', 0, 200),
            self::item('drink', 'water', 20000, 0),
            self::item('drink', 'isotonic', 50, 0.04),
            self::item('food', 'custom_999', 0, 0, str_repeat('é', 60)),
        ]]);

        self::assertSame(22.4, $items[0]->carbs);
        self::assertSame(22.5, $items[1]->carbs);
        self::assertSame(200.0, $items[2]->carbs);
        self::assertSame(20000, $items[3]->quantity);
        self::assertSame(0.0, $items[4]->carbs);
        self::assertSame(60, mb_strlen((string) $items[5]->label));
    }

    #[Test]
    public function acceptsTenCustomItemsPerKind(): void
    {
        $list = [];
        for ($n = 1; $n <= 10; ++$n) {
            $list[] = self::item('food', 'custom_'.$n, 1, 10, 'Aliment '.$n);
            $list[] = self::item('drink', 'custom_'.(100 + $n), 500, 10, 'Boisson '.$n);
        }

        self::assertCount(20, (new FuelPayloadValidator())->validate(['items' => $list]));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidPayloads(): iterable
    {
        yield 'not an object' => ['items'];
        yield 'null' => [null];
        yield 'items missing' => [['foo' => []]];
        yield 'items is an object' => [['items' => ['a' => self::item('food', 'gel', 1, 25)]]];
        yield 'item is a list' => [['items' => [['food', 'gel', 1, 25]]]];
        yield 'item is a scalar' => [['items' => ['gel']]];
        yield 'unknown kind' => [['items' => [self::item('snack', 'gel', 1, 25)]]];
        yield 'kind missing' => [['items' => [['key' => 'gel', 'quantity' => 1, 'carbs' => 25]]]];
        yield 'unknown key' => [['items' => [self::item('food', 'pizza', 1, 25)]]];
        yield 'key not a string' => [['items' => [['kind' => 'food', 'key' => 1, 'quantity' => 1, 'carbs' => 25]]]];
        yield 'gel as a drink' => [['items' => [self::item('drink', 'gel', 500, 25)]]];
        yield 'water as a food' => [['items' => [self::item('food', 'water', 1, 0)]]];
        yield 'custom_0' => [['items' => [self::item('food', 'custom_0', 1, 25, 'X')]]];
        yield 'custom_1000' => [['items' => [self::item('food', 'custom_1000', 1, 25, 'X')]]];
        yield 'custom_01' => [['items' => [self::item('food', 'custom_01', 1, 25, 'X')]]];
        yield 'custom without label' => [['items' => [self::item('food', 'custom_1', 1, 25)]]];
        yield 'custom with blank label' => [['items' => [self::item('food', 'custom_1', 1, 25, "  \t ")]]];
        yield 'custom with label not a string' => [['items' => [self::item('food', 'custom_1', 1, 25, 12)]]];
        yield 'custom label 61 chars' => [['items' => [self::item('food', 'custom_1', 1, 25, str_repeat('a', 61))]]];
        yield 'custom label with control char' => [['items' => [self::item('food', 'custom_1', 1, 25, "Gel\u{0007}")]]];
        yield 'label on a built-in item' => [['items' => [self::item('food', 'gel', 1, 25, 'Mon gel')]]];
        yield 'duplicate key' => [['items' => [self::item('food', 'gel', 1, 25), self::item('food', 'gel', 2, 25)]]];
        yield 'duplicate custom key across kinds' => [['items' => [self::item('food', 'custom_1', 1, 25, 'A'), self::item('drink', 'custom_1', 500, 25, 'B')]]];
        yield 'quantity float' => [['items' => [self::item('food', 'gel', 1.5, 25)]]];
        yield 'quantity string' => [['items' => [self::item('food', 'gel', '2', 25)]]];
        yield 'quantity bool' => [['items' => [self::item('food', 'gel', true, 25)]]];
        yield 'quantity missing' => [['items' => [['kind' => 'food', 'key' => 'gel', 'carbs' => 25]]]];
        yield 'negative quantity' => [['items' => [self::item('food', 'gel', -1, 25)]]];
        yield '101 units' => [['items' => [self::item('food', 'gel', 101, 25)]]];
        yield '20050 ml' => [['items' => [self::item('drink', 'water', 20050, 0)]]];
        yield '260 ml (not a multiple of 50)' => [['items' => [self::item('drink', 'water', 260, 0)]]];
        yield 'carbs string' => [['items' => [self::item('food', 'gel', 1, '25')]]];
        yield 'carbs null' => [['items' => [self::item('food', 'gel', 1, null)]]];
        yield 'carbs bool' => [['items' => [self::item('food', 'gel', 1, false)]]];
        yield 'carbs negative' => [['items' => [self::item('food', 'gel', 1, -0.1)]]];
        yield 'carbs above 200' => [['items' => [self::item('food', 'gel', 1, 200.01)]]];
        yield 'carbs infinite' => [['items' => [self::item('food', 'gel', 1, INF)]]];
    }

    #[Test]
    #[DataProvider('invalidPayloads')]
    public function rejectsInvalidPayloads(mixed $payload): void
    {
        $this->expectException(InvalidFuelPayloadException::class);
        (new FuelPayloadValidator())->validate($payload);
    }

    #[Test]
    public function rejectsAnEleventhCustomItemOfAKind(): void
    {
        $list = [];
        for ($n = 1; $n <= 11; ++$n) {
            $list[] = self::item('drink', 'custom_'.$n, 0, 10, 'Boisson '.$n);
        }

        $this->expectException(InvalidFuelPayloadException::class);
        $this->expectExceptionMessage('Too many custom drink items');
        (new FuelPayloadValidator())->validate(['items' => $list]);
    }

    #[Test]
    public function rejectsTooManyItems(): void
    {
        $this->expectException(InvalidFuelPayloadException::class);
        $this->expectExceptionMessage('Too many items');
        (new FuelPayloadValidator())->validate(['items' => array_fill(0, 29, self::item('food', 'gel', 1, 25))]);
    }

    #[Test]
    public function errorMessagesPointToTheItem(): void
    {
        try {
            (new FuelPayloadValidator())->validate(['items' => [self::item('food', 'gel', 1, 25), self::item('drink', 'water', 260, 0)]]);
            self::fail('Expected an exception');
        } catch (InvalidFuelPayloadException $e) {
            self::assertSame('items[1].quantity must be a multiple of 50 from 0 to 20000 (ml).', $e->getMessage());
        }
    }
}
