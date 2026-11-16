<?php

namespace App\Tests\Unit\Service\Nutrition;

use App\Entity\FuelKind;
use App\Service\Nutrition\ActivityFuelCalculator;
use App\Service\Nutrition\FuelItem;
use App\Service\Nutrition\FuelTargetStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ActivityFuelCalculatorTest extends TestCase
{
    private ActivityFuelCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ActivityFuelCalculator();
    }

    private static function food(string $key, int $units, float $carbs, ?string $label = null): FuelItem
    {
        return new FuelItem(FuelKind::Food, $key, $units, $carbs, $label);
    }

    private static function drink(string $key, int $ml, float $carbsPer500, ?string $label = null): FuelItem
    {
        return new FuelItem(FuelKind::Drink, $key, $ml, $carbsPer500, $label);
    }

    /** @return list<FuelItem> 2 gels, 1 pâte de fruit, 500 ml d'eau, 500 ml de boisson d'effort (maquette) */
    private static function mockupItems(): array
    {
        return [
            self::food('gel', 2, 25),
            self::food('puree', 0, 22),
            self::food('fruit_paste', 1, 18),
            self::drink('water', 500, 0),
            self::drink('isotonic', 0, 30),
            self::drink('energy_drink', 500, 45),
        ];
    }

    #[Test]
    public function reproducesTheMockupNumbers(): void
    {
        $s = $this->calculator->summarize(self::mockupItems(), 6180); // 1:43:00

        self::assertSame(68, $s->foodCarbs);
        self::assertSame(45, $s->drinkCarbs);
        self::assertSame(113, $s->totalCarbs);
        self::assertSame(66, $s->carbsPerHour);       // 113 / 1,7167 h = 65,8
        self::assertSame(1000, $s->liquidMl);
        self::assertSame(10, $s->liquidDl);           // 1,0 L
        self::assertSame(1.0, $s->liquidLiters());
        self::assertSame(583, $s->hydrationMlPerHour); // 1000 / 1,7167 h = 582,5
        self::assertSame(3, $s->foodUnits);
        self::assertSame(FuelTargetStatus::Within, $s->status);
        self::assertTrue($s->hasEntries());

        // Durée exacte de la maquette (1:42:13) : 587 ml/h, toujours 66 g/h
        $mockup = $this->calculator->summarize(self::mockupItems(), 6133);
        self::assertSame(66, $mockup->carbsPerHour);
        self::assertSame(587, $mockup->hydrationMlPerHour);
    }

    #[Test]
    public function drinksArePerFiveHundredMlAndRoundHalfUp(): void
    {
        self::assertSame(23, ActivityFuelCalculator::itemCarbs(self::drink('energy_drink', 250, 45))); // 22,5 -> 23
        self::assertSame(45, ActivityFuelCalculator::itemCarbs(self::drink('isotonic', 750, 30)));
        self::assertSame(2250, ActivityFuelCalculator::itemCarbsHundredths(self::drink('energy_drink', 250, 45)));
        self::assertSame(0, ActivityFuelCalculator::itemCarbs(self::drink('water', 1500, 0)));
        self::assertSame(1, ActivityFuelCalculator::itemCarbs(self::drink('custom_1', 50, 5.5, 'Sirop'))); // 0,55 g -> 1
    }

    #[Test]
    public function totalIsTheRoundedExactSumNotTheSumOfRoundedParts(): void
    {
        // 3 x 22,5 = 67,5 (-> 68) + 250 ml à 45 g = 22,5 (-> 23) : total exact 90 -> 90 (et non 91)
        $s = $this->calculator->summarize([self::food('puree', 3, 22.5), self::drink('energy_drink', 250, 45)], 3600);

        self::assertSame(68, $s->foodCarbs);
        self::assertSame(23, $s->drinkCarbs);
        self::assertSame(90, $s->totalCarbs);
        self::assertSame(90, $s->carbsPerHour);
    }

    #[Test]
    public function customItemsCountLikeBuiltInOnes(): void
    {
        $s = $this->calculator->summarize([
            self::food('custom_1', 2, 27, 'Banane'),
            self::food('gel', 1, 25),
            self::drink('custom_2', 250, 53, 'Coca'), // 26,5 g
        ], 3600);

        self::assertSame(79, $s->foodCarbs);
        self::assertSame(27, $s->drinkCarbs);
        self::assertSame(106, $s->totalCarbs); // 79 + 26,5 = 105,5 -> 106
        self::assertSame(3, $s->foodUnits);
        self::assertSame(250, $s->liquidMl);
        self::assertSame(3, $s->liquidDl);     // 0,25 L -> 0,3 L
        self::assertSame(250, $s->hydrationMlPerHour);
    }

    #[Test]
    public function withoutDurationThereIsNoRateNorHydration(): void
    {
        foreach ([null, 0] as $duration) {
            $s = $this->calculator->summarize(self::mockupItems(), $duration);
            self::assertSame(113, $s->totalCarbs);
            self::assertNull($s->durationSeconds);
            self::assertNull($s->carbsPerHour);
            self::assertNull($s->hydrationMlPerHour);
            self::assertSame(FuelTargetStatus::None, $s->status);
        }
    }

    #[Test]
    public function durationIsMovingTimeThenElapsedTime(): void
    {
        self::assertSame(6180, ActivityFuelCalculator::durationSeconds(6180, 7000));
        self::assertSame(7000, ActivityFuelCalculator::durationSeconds(null, 7000));
        self::assertSame(7000, ActivityFuelCalculator::durationSeconds(0, 7000));
        self::assertNull(ActivityFuelCalculator::durationSeconds(null, null));
        self::assertNull(ActivityFuelCalculator::durationSeconds(0, 0));
    }

    /** @return iterable<string, array{float, int, FuelTargetStatus}> glucides en 1 h -> débit arrondi, statut */
    public static function boundaries(): iterable
    {
        yield '59.4 g/h rounds to 59: below' => [59.4, 59, FuelTargetStatus::Below];
        yield '59.5 g/h rounds to 60: within (60 included)' => [59.5, 60, FuelTargetStatus::Within];
        yield '60 g/h: within' => [60, 60, FuelTargetStatus::Within];
        yield '90 g/h: within (90 included)' => [90, 90, FuelTargetStatus::Within];
        yield '90.4 g/h rounds to 90: within' => [90.4, 90, FuelTargetStatus::Within];
        yield '90.5 g/h rounds to 91: above' => [90.5, 91, FuelTargetStatus::Above];
        yield '0 g/h: below' => [0, 0, FuelTargetStatus::Below];
    }

    #[Test]
    #[DataProvider('boundaries')]
    public function statusComparesTheDisplayedRateWithInclusiveBounds(float $carbs, int $rate, FuelTargetStatus $status): void
    {
        $s = $this->calculator->summarize([self::food('custom_1', 1, $carbs, 'Test')], 3600);

        self::assertSame($rate, $s->carbsPerHour);
        self::assertSame($status, $s->status);
    }

    #[Test]
    public function statusOfARate(): void
    {
        self::assertSame(FuelTargetStatus::None, ActivityFuelCalculator::status(null));
        self::assertSame(FuelTargetStatus::Below, ActivityFuelCalculator::status(ActivityFuelCalculator::TARGET_MIN_G_PER_H - 1));
        self::assertSame(FuelTargetStatus::Within, ActivityFuelCalculator::status(ActivityFuelCalculator::TARGET_MIN_G_PER_H));
        self::assertSame(FuelTargetStatus::Within, ActivityFuelCalculator::status(ActivityFuelCalculator::TARGET_MAX_G_PER_H));
        self::assertSame(FuelTargetStatus::Above, ActivityFuelCalculator::status(ActivityFuelCalculator::TARGET_MAX_G_PER_H + 1));
    }

    #[Test]
    public function liquidIsRoundedToTheTenthOfALitreHalfUp(): void
    {
        self::assertSame(12, $this->calculator->summarize([self::drink('water', 1150, 0)], 3600)->liquidDl);  // 1,15 -> 1,2
        self::assertSame(13, $this->calculator->summarize([self::drink('water', 1250, 0)], 3600)->liquidDl);  // 1,25 -> 1,3
        self::assertSame(1, $this->calculator->summarize([self::drink('water', 50, 0)], 3600)->liquidDl);     // 0,05 -> 0,1
        self::assertSame(1.2, $this->calculator->summarize([self::drink('water', 1150, 0)], 3600)->liquidLiters());
    }

    #[Test]
    public function emptyFuelingIsZeroAndBelowTarget(): void
    {
        $s = $this->calculator->summarize([self::food('gel', 0, 25), self::drink('water', 0, 0)], 3600);

        self::assertSame(0, $s->totalCarbs);
        self::assertSame(0, $s->carbsPerHour);
        self::assertSame(0, $s->hydrationMlPerHour);
        self::assertSame(0, $s->foodUnits);
        self::assertFalse($s->hasEntries());
        self::assertSame(FuelTargetStatus::Below, $s->status);
    }

    #[Test]
    public function largeQuantitiesStayExact(): void
    {
        $s = $this->calculator->summarize([self::food('bar', 100, 35), self::drink('energy_drink', 20000, 45)], 36000);

        self::assertSame(3500, $s->foodCarbs);
        self::assertSame(1800, $s->drinkCarbs);
        self::assertSame(5300, $s->totalCarbs);
        self::assertSame(530, $s->carbsPerHour);
        self::assertSame(200, $s->liquidDl);
        self::assertSame(2000, $s->hydrationMlPerHour);
    }

    #[Test]
    public function exposesTheJsonContract(): void
    {
        $data = $this->calculator->summarize(self::mockupItems(), 6180)->toArray();

        self::assertSame([
            'foodCarbs'          => 68,
            'drinkCarbs'         => 45,
            'totalCarbs'         => 113,
            'durationSeconds'    => 6180,
            'carbsPerHour'       => 66,
            'liquidMl'           => 1000,
            'liquidLiters'       => 1.0,
            'hydrationMlPerHour' => 583,
            'foodUnits'          => 3,
            'status'             => 'within',
            'target'             => ['minPerHour' => 60, 'maxPerHour' => 90],
        ], $data);
    }
}
