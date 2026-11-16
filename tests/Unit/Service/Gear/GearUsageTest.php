<?php

namespace App\Tests\Unit\Service\Gear;

use App\Entity\Gear;
use App\Entity\GearBrand;
use App\Entity\GearType;
use App\Entity\User;
use App\Repository\GearRepository;
use App\Service\Gear\GearUsage;
use App\Service\Gear\GearUsageProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GearUsageTest extends TestCase
{
    private static function usage(float $distanceM, int $initialM = 0, ?int $maxM = 700_000, int $count = 1): GearUsage
    {
        return new GearUsage($count, $distanceM, 3600, null, $initialM, $maxM);
    }

    public function testTotalDistanceAddsInitialDistance(): void
    {
        $u = self::usage(150_000, 50_000);

        $this->assertSame(200_000.0, $u->totalDistanceM());
        $this->assertSame(200.0, $u->totalDistanceKm());
    }

    /** @return iterable<string, array{float, int, ?int, string, ?int}> */
    public static function statusProvider(): iterable
    {
        yield 'new shoes'                 => [0, 0, 700_000, GearUsage::STATUS_OK, 0];
        yield 'just under warn (79.9 %)'  => [559_300, 0, 700_000, GearUsage::STATUS_OK, 79];
        yield 'exactly 80 % = warn'       => [560_000, 0, 700_000, GearUsage::STATUS_WARN, 80];
        yield 'initial km count for wear' => [460_000, 100_000, 700_000, GearUsage::STATUS_WARN, 80];
        yield 'just under worn (99.9 %)'  => [699_300, 0, 700_000, GearUsage::STATUS_WARN, 99];
        yield 'exactly 100 % = worn'      => [700_000, 0, 700_000, GearUsage::STATUS_WORN, 100];
        yield 'over lifespan'             => [910_000, 0, 700_000, GearUsage::STATUS_WORN, 130];
        yield 'no lifespan = not tracked' => [910_000, 0, null, GearUsage::STATUS_NONE, null];
        yield 'zero lifespan = not tracked' => [10_000, 0, 0, GearUsage::STATUS_NONE, null];
    }

    #[DataProvider('statusProvider')]
    public function testStatusAndPercent(float $distanceM, int $initialM, ?int $maxM, string $status, ?int $percent): void
    {
        $u = self::usage($distanceM, $initialM, $maxM);

        $this->assertSame($status, $u->status());
        $this->assertSame($percent, $u->wearPercent());
    }

    public function testWearRatio(): void
    {
        $this->assertEqualsWithDelta(0.5, self::usage(350_000)->wearRatio(), 1e-9);
        $this->assertNull(self::usage(350_000, 0, null)->wearRatio());
    }

    public function testBarPercentIsClamped(): void
    {
        $this->assertSame(100, self::usage(1_400_000)->wearBarPercent());
        $this->assertSame(50, self::usage(350_000)->wearBarPercent());
        $this->assertSame(0, self::usage(350_000, 0, null)->wearBarPercent());
    }

    public function testRemainingKm(): void
    {
        $this->assertEqualsWithDelta(265.0, self::usage(435_000)->remainingKm(), 1e-9);
        $this->assertSame(0.0, self::usage(800_000)->remainingKm());
        $this->assertNull(self::usage(800_000, 0, null)->remainingKm());
    }

    public function testNegativeValuesAreIgnored(): void
    {
        $this->assertSame(0.0, self::usage(-500, -100)->totalDistanceM());
    }

    public function testProviderBuildsFromGearAndRow(): void
    {
        $brand = (new GearBrand())->setName('Hoka')->setForShoes(true);
        $gear  = (new Gear())->setType(GearType::Shoes)->setBrand($brand)->setModel('Speedgoat 6')
            ->setInitialDistanceM(20_000)->setMaxDistanceM(700_000);
        $last  = new \DateTimeImmutable('2026-11-01 08:00:00');

        $u = GearUsageProvider::build($gear, ['activitiesCount' => 3, 'distanceM' => 30_000.0, 'movingSeconds' => 9000, 'lastUsedAt' => $last]);
        $this->assertSame(3, $u->activitiesCount);
        $this->assertSame(50_000.0, $u->totalDistanceM());
        $this->assertSame(7, $u->wearPercent());
        $this->assertSame($last, $u->lastUsedAt);

        // Matériel jamais utilisé : seulement le kilométrage initial
        $unused = GearUsageProvider::build($gear, null);
        $this->assertSame(0, $unused->activitiesCount);
        $this->assertSame(20_000.0, $unused->totalDistanceM());
        $this->assertNull($unused->lastUsedAt);
    }

    public function testWearAlertsKeepWarnAndWornWorstFirst(): void
    {
        $brand = (new GearBrand())->setName('Hoka')->setForShoes(true)->setForBike(true);
        $make  = static fn (string $model, GearType $type, int $initialM, int $maxM): Gear => (new Gear())
            ->setType($type)->setBrand($brand)->setModel($model)->setInitialDistanceM($initialM)->setMaxDistanceM($maxM);
        $row = static fn (float $distanceM): array => ['activitiesCount' => 1, 'distanceM' => $distanceM, 'movingSeconds' => 60, 'lastUsedAt' => null];

        $ok    = $make('OK', GearType::Shoes, 0, 700_000);          // 50 %
        $warn  = $make('Warn', GearType::Shoes, 0, 700_000);        // 84 %
        $worn  = $make('Worn', GearType::Shoes, 400_000, 700_000);  // 110 %
        $bike  = $make('Bike', GearType::Bike, 0, 10_000_000);      // 95 %

        $repo = $this->createMock(GearRepository::class);
        $repo->expects($this->once())->method('findActiveTrackedWithUsage')->willReturn([
            ['gear' => $ok, 'usage' => $row(350_000)],
            ['gear' => $warn, 'usage' => $row(590_000)],
            ['gear' => $worn, 'usage' => $row(370_000)],
            ['gear' => $bike, 'usage' => $row(9_500_000)],
        ]);

        $alerts = (new GearUsageProvider($repo))->wearAlerts(new User());

        $this->assertSame([$worn, $bike, $warn], array_column($alerts, 'gear'));
        $this->assertSame(GearUsage::STATUS_WORN, $alerts[0]['usage']->status());
        $this->assertSame(110, $alerts[0]['usage']->wearPercent());
        $this->assertSame(GearUsage::STATUS_WARN, $alerts[2]['usage']->status());
    }
}
