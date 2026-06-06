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
}
