<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Gear;
use App\Entity\GearBrand;
use App\Entity\GearType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GearTest extends TestCase
{
    /** @return iterable<string, array{?string, ?GearType}> */
    public static function sportProvider(): iterable
    {
        yield 'run'             => ['run', GearType::Shoes];
        yield 'trail'           => ['trail', GearType::Shoes];
        yield 'walk'            => ['walk', GearType::Shoes];
        yield 'hike'            => ['hike', GearType::Shoes];
        yield 'ride'            => ['ride', GearType::Bike];
        yield 'swim'            => ['swim', null];
        yield 'weight training' => ['weight_training', null];
        yield 'other'           => ['other', null];
        yield 'no sport'        => [null, null];
    }

    #[DataProvider('sportProvider')]
    public function testForSportSlug(?string $slug, ?GearType $expected): void
    {
        $this->assertSame($expected, GearType::forSportSlug($slug));
    }

    public function testCompatibleTypes(): void
    {
        $this->assertSame([GearType::Shoes], GearType::compatibleWithSportSlug('trail'));
        $this->assertSame([GearType::Bike], GearType::compatibleWithSportSlug('ride'));
        $this->assertSame([], GearType::compatibleWithSportSlug('swim'));
        // "Autre" (VTT, elliptique... côté Strava) et sans sport : tout est proposé
        $this->assertSame(GearType::cases(), GearType::compatibleWithSportSlug('other'));
        $this->assertSame(GearType::cases(), GearType::compatibleWithSportSlug(null));
    }

    public function testDefaultLifespan(): void
    {
        $this->assertSame(700_000, GearType::Shoes->defaultMaxDistanceM());
        $this->assertNull(GearType::Bike->defaultMaxDistanceM());
    }

    public function testBrandSupportsType(): void
    {
        $hoka = (new GearBrand())->setName('Hoka')->setForShoes(true);
        $both = (new GearBrand())->setName('Decathlon')->setForShoes(true)->setForBike(true);

        $this->assertTrue($hoka->supports(GearType::Shoes));
        $this->assertFalse($hoka->supports(GearType::Bike));
        $this->assertTrue($both->supports(GearType::Bike));
    }

    public function testDisplayNames(): void
    {
        $hoka  = (new GearBrand())->setName('Hoka');
        $other = (new GearBrand())->setName(GearBrand::OTHER);
        $gear  = (new Gear())->setBrand($hoka)->setModel('Speedgoat 6');

        $this->assertSame('Hoka Speedgoat 6', $gear->getFullName());
        $this->assertSame('Hoka Speedgoat 6', $gear->getDisplayName());

        $gear->setNickname('Les rouges');
        $this->assertSame('Les rouges', $gear->getDisplayName());

        // La marque générique "Autre" n'est pas affichée
        $gear->setBrand($other)->setNickname(null);
        $this->assertSame('Speedgoat 6', $gear->getFullName());
    }

    public function testRetired(): void
    {
        $gear = new Gear();
        $this->assertFalse($gear->isRetired());
        $gear->setRetiredAt(new \DateTimeImmutable('2026-01-01'));
        $this->assertTrue($gear->isRetired());
    }

    public function testDeductImportedStravaActivity(): void
    {
        $manual = (new Gear())->setInitialDistanceM(50_000);
        $manual->deductImportedStravaActivity(10, 5_000.0);
        $this->assertSame(50_000, $manual->getInitialDistanceM(), 'never imported: nothing to deduct');
        $this->assertFalse($manual->isFromStrava());

        $gear = (new Gear())->setStravaGearId('g1')->setStravaLastActivityId(100)->setInitialDistanceM(50_000);
        $this->assertTrue($gear->isFromStrava());
        $gear->deductImportedStravaActivity(101, 5_000.0);
        $this->assertSame(50_000, $gear->getInitialDistanceM(), 'activity created after the import');
        $gear->deductImportedStravaActivity(100, 4_999.6);
        $this->assertSame(45_000, $gear->getInitialDistanceM());
        $gear->deductImportedStravaActivity(12, null);
        $this->assertSame(45_000, $gear->getInitialDistanceM(), 'no distance');
        $gear->deductImportedStravaActivity(13, 90_000.0);
        $this->assertSame(0, $gear->getInitialDistanceM(), 'never negative');
    }
}
