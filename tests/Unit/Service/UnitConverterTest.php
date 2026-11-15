<?php

namespace App\Tests\Unit\Service;

use App\Service\Units\UnitConverter;
use PHPUnit\Framework\TestCase;

class UnitConverterTest extends TestCase
{
    private UnitConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new UnitConverter();
    }

    public function testMetersToKm(): void
    {
        $this->assertSame(10.0, $this->converter->metersToKm(10000));
        $this->assertSame(42.2, $this->converter->metersToKm(42200));
        $this->assertSame(0.0, $this->converter->metersToKm(0));
    }

    public function testMetersToMiles(): void
    {
        $miles = $this->converter->metersToMiles(1609.344);
        $this->assertEqualsWithDelta(1.0, $miles, 0.01);
    }

    public function testSecondsToHMS(): void
    {
        $this->assertSame('1:00:00', $this->converter->secondsToHMS(3600));
        $this->assertSame('5:30', $this->converter->secondsToHMS(330));
        $this->assertSame('3:45:20', $this->converter->secondsToHMS(13520));
    }

    public function testPaceCalculation(): void
    {
        // 4 min/km = 1000m / 240s = ~4.17 m/s
        $pace = $this->converter->metersPerSecondToPaceKm(1000 / 240);
        $this->assertSame('4:00', $pace);
    }

    public function testPaceExactValues(): void
    {
        $this->assertSame('5:00', $this->converter->metersPerSecondToPaceKm(1000 / 300));
        $this->assertSame('3:30', $this->converter->metersPerSecondToPaceKm(1000 / 210));
        $this->assertSame('5:01', $this->converter->metersPerSecondToPaceKm(1000 / 301));
        $this->assertSame('65:00', $this->converter->metersPerSecondToPaceKm(1000 / 3900));
    }

    public function testPaceRoundsToNearestSecond(): void
    {
        // 239.6 s/km -> rounds up to the next minute, never "3:59" nor "3:60"
        $this->assertSame('4:00', $this->converter->metersPerSecondToPaceKm(1000 / 239.6));
        // 299.5 s/km -> rounds half up
        $this->assertSame('5:00', $this->converter->metersPerSecondToPaceKm(1000 / 299.5));
        // 299.4 s/km -> rounds down
        $this->assertSame('4:59', $this->converter->metersPerSecondToPaceKm(1000 / 299.4));
        // 330.4 s/km -> 5:30
        $this->assertSame('5:30', $this->converter->metersPerSecondToPaceKm(1000 / 330.4));
    }

    public function testPaceWithZeroOrNegativeSpeed(): void
    {
        $this->assertSame('--:--', $this->converter->metersPerSecondToPaceKm(0));
        $this->assertSame('--:--', $this->converter->metersPerSecondToPaceKm(0.0));
        $this->assertSame('--:--', $this->converter->metersPerSecondToPaceKm(-3.5));
    }
}
