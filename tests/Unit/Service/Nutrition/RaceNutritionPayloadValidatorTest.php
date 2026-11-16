<?php

namespace App\Tests\Unit\Service\Nutrition;

use App\Service\Nutrition\InvalidFuelPayloadException;
use App\Service\Nutrition\RaceNutritionPayloadValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Contrat JSON de l'enregistrement du plan nutrition d'un scénario (RaceNutritionPayloadValidator) : bornes de chaque
 * valeur, clés de produits connues, noms des produits personnalisés limités, lignes manuelles uniques.
 */
final class RaceNutritionPayloadValidatorTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function valid(): array
    {
        return [
            'settings' => [
                'carbsPerHour' => 70, 'fluidPerHour' => 500, 'mixDrink' => 30, 'mixGel' => 40, 'mixSolid' => 30,
                'solidEarly' => true, 'flaskMl' => 500, 'gel' => 'gel', 'solid' => 'custom_2', 'drink' => 'isotonic',
            ],
            'products' => [
                ['key' => 'gel', 'carbs' => 22.04],
                ['key' => 'bar', 'kind' => 'food', 'cat' => 'solid', 'carbs' => 35],
                ['key' => 'custom_2', 'kind' => 'food', 'cat' => 'solid', 'label' => "  Banane\t mûre ", 'carbs' => 27],
                ['key' => 'custom_3', 'kind' => 'drink', 'cat' => 'drink', 'label' => 'Coca', 'carbs' => 53],
            ],
            'manual' => [
                ['place' => 'segment', 'anchor' => '12', 'items' => [['key' => 'gel', 'qty' => 2], ['key' => 'water', 'qty' => 0]]],
                ['place' => 'point', 'anchor' => 'start', 'items' => []],
                ['place' => 'segment', 'anchor' => 'finish', 'items' => [['key' => 'custom_3', 'qty' => 250]]],
                ['place' => 'point', 'anchor' => 12, 'items' => [['key' => 'custom_2', 'qty' => 1]]],
            ],
        ];
    }

    #[Test]
    public function validPayloadIsNormalized(): void
    {
        $state = (new RaceNutritionPayloadValidator())->validate(self::valid());

        self::assertSame(70, $state['settings']['carbsPerHour']);
        self::assertTrue($state['settings']['solidEarly']);
        self::assertSame('custom_2', $state['settings']['solid']);
        self::assertSame(['key' => 'gel', 'kind' => 'food', 'cat' => 'gel', 'label' => null, 'carbs' => 22.0], $state['products'][0], 'catalogue: kind and category from the catalogue, carbs to 0.1 g');
        self::assertSame('Banane mûre', $state['products'][2]['label'], 'spaces normalized');
        self::assertSame([
            ['place' => 'segment', 'anchor' => '12', 'items' => [['key' => 'gel', 'qty' => 2]]],
            ['place' => 'point', 'anchor' => 'start', 'items' => []],
            ['place' => 'segment', 'anchor' => 'finish', 'items' => [['key' => 'custom_3', 'qty' => 250]]],
            ['place' => 'point', 'anchor' => '12', 'items' => [['key' => 'custom_2', 'qty' => 1]]],
        ], $state['manual'], 'zero quantities dropped, empty rows kept, integer anchors as strings');
    }

    /** @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>}> */
    public static function invalid(): iterable
    {
        $set = static fn (string $path, mixed $value): callable => static function (array $p) use ($path, $value): array {
            $ref = &$p;
            foreach (explode('.', $path) as $part) {
                $ref = &$ref[ctype_digit($part) ? (int) $part : $part];
            }
            $ref = $value;

            return $p;
        };

        yield 'not an object' => [static fn (array $p): array => ['x']];
        yield 'missing manual' => [static function (array $p): array { unset($p['manual']); return $p; }];
        yield 'carbs per hour above 150' => [$set('settings.carbsPerHour', 151)];
        yield 'carbs per hour as string' => [$set('settings.carbsPerHour', '70')];
        yield 'negative fluid' => [$set('settings.fluidPerHour', -50)];
        yield 'fluid not a multiple of 50' => [$set('settings.fluidPerHour', 520)];
        yield 'fluid above 1500' => [$set('settings.fluidPerHour', 1550)];
        yield 'mix does not sum to 100' => [$set('settings.mixGel', 50)];
        yield 'mix above 100' => [static fn (array $p): array => array_replace_recursive($p, ['settings' => ['mixDrink' => 120, 'mixGel' => -20, 'mixSolid' => 0]])];
        yield 'solidEarly not a bool' => [$set('settings.solidEarly', 1)];
        yield 'flask too small' => [$set('settings.flaskMl', 50)];
        yield 'flask not a multiple of 50' => [$set('settings.flaskMl', 330)];
        yield 'gel choice of the wrong category' => [$set('settings.gel', 'bar')];
        yield 'drink choice that is food' => [$set('settings.drink', 'gel')];
        yield 'unknown auto product' => [$set('settings.solid', 'custom_9')];
        yield 'unknown product key' => [$set('products.0.key', 'pizza')];
        yield 'duplicate product' => [$set('products.1.key', 'gel')];
        yield 'catalogue product with a label' => [$set('products.0.label', 'Mon gel')];
        yield 'catalogue product with the wrong category' => [$set('products.1.cat', 'gel')];
        yield 'carbs above 200' => [$set('products.0.carbs', 200.5)];
        yield 'carbs as string' => [$set('products.0.carbs', '25')];
        yield 'custom label too long' => [$set('products.2.label', str_repeat('a', 61))];
        yield 'custom label empty' => [$set('products.2.label', '   ')];
        yield 'custom label with a control character' => [$set('products.2.label', "Ba\x07nane")];
        yield 'custom food in the drink category' => [$set('products.2.cat', 'drink')];
        yield 'custom key out of range' => [$set('products.2.key', 'custom_0')];
        yield 'too many custom food products' => [static function (array $p): array {
            for ($i = 10; $i <= 20; ++$i) {
                $p['products'][] = ['key' => 'custom_'.$i, 'kind' => 'food', 'cat' => 'gel', 'label' => 'G'.$i, 'carbs' => 20];
            }

            return $p;
        }];
        yield 'manual place unknown' => [$set('manual.0.place', 'leg')];
        yield 'start anchor on a segment' => [$set('manual.0.anchor', 'start')];
        yield 'finish anchor on a point' => [$set('manual.1.anchor', 'finish')];
        yield 'anchor not a number' => [$set('manual.0.anchor', '12a')];
        yield 'duplicate row' => [$set('manual.3.place', 'segment')];
        yield 'unknown product in a row' => [$set('manual.0.items.0.key', 'custom_7')];
        yield 'duplicate product in a row' => [$set('manual.0.items.1.key', 'gel')];
        yield 'food quantity above 100' => [$set('manual.0.items.0.qty', 101)];
        yield 'negative quantity' => [$set('manual.0.items.0.qty', -1)];
        yield 'drink not a multiple of 50 ml' => [$set('manual.2.items.0.qty', 260)];
        yield 'drink above 20 L' => [$set('manual.2.items.0.qty', 20050)];
        yield 'quantity as float' => [$set('manual.0.items.0.qty', 2.0)];
        yield 'too many lines in a row' => [$set('manual.0.items', array_map(static fn (int $i): array => ['key' => 'gel', 'qty' => $i], range(1, 21)))];
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $mutate */
    #[Test]
    #[DataProvider('invalid')]
    public function invalidPayloadIsRejectedAsAWhole(callable $mutate): void
    {
        $this->expectException(InvalidFuelPayloadException::class);
        (new RaceNutritionPayloadValidator())->validate($mutate(self::valid()));
    }

    #[Test]
    public function autoProductsMayBeNull(): void
    {
        $payload = self::valid();
        $payload['settings']['gel'] = null;
        $payload['settings']['solid'] = null;
        $payload['settings']['drink'] = null;
        $state = (new RaceNutritionPayloadValidator())->validate($payload);
        self::assertNull($state['settings']['gel']);
        self::assertNull($state['settings']['drink']);
    }
}
