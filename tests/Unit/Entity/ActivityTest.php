<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Activity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ActivityTest extends TestCase
{
    /** @return iterable<string, array{float, int, ?string}> */
    public static function paceProvider(): iterable
    {
        yield 'exact 5:00/km' => [10000, 3000, '5:00'];
        yield 'rounds up to next minute' => [1000, 300, '5:00'];
        yield '309.55 s/km rounds to 5:10' => [2606.6, 807, '5:10'];
        yield '299.6 s/km rounds to 5:00, never 4:60' => [1000.0 / 299.6 * 299.6, 300, '5:00'];
        yield 'no distance' => [0, 600, null];
        yield 'no time' => [1000, 0, null];
    }

    #[DataProvider('paceProvider')]
    public function testPaceMinPerKm(float $meters, int $seconds, ?string $expected): void
    {
        $activity = (new Activity())->setDistanceMeters($meters)->setMovingTimeSeconds($seconds);

        $this->assertSame($expected, $activity->getPaceMinPerKm());
    }

    public function testSpeedKmh(): void
    {
        $activity = (new Activity())->setAverageSpeed(7.17);

        $this->assertSame(25.8, $activity->getSpeedKmh());
        $this->assertNull((new Activity())->getSpeedKmh());
    }

    public function testSpeedKmhFallsBackToDistanceOverMovingTime(): void
    {
        // Activité sans vitesse moyenne Strava : 160 km en 5h10 -> 31 km/h
        $activity = (new Activity())->setDistanceMeters(160000.0)->setMovingTimeSeconds(18600);
        $this->assertSame(31.0, $activity->getSpeedKmh());

        // La vitesse moyenne Strava reste prioritaire
        $this->assertSame(25.8, $activity->setAverageSpeed(7.17)->getSpeedKmh());

        // Temps nul : pas de division par zéro
        $this->assertNull((new Activity())->setDistanceMeters(1000.0)->setMovingTimeSeconds(0)->getSpeedKmh());
    }
}
