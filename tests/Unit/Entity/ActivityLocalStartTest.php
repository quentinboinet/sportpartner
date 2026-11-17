<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Activity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Départ stocké en UTC, relu en heure locale du fuseau de l'activité (IANA ou format Strava). */
final class ActivityLocalStartTest extends TestCase
{
    #[Test]
    public function localStartUsesTheActivityTimeZone(): void
    {
        // Comme relu par Doctrine : heure UTC stockée, objet dans le fuseau de PHP (ici un autre fuseau)
        $stored = new \DateTimeImmutable('2026-11-16 06:30:00', new \DateTimeZone('Asia/Tokyo'));

        $strava = (new Activity())->setStartDate($stored)->setTimezone('(GMT+01:00) Europe/Paris');
        self::assertSame('2026-11-16 06:30:00 UTC', $strava->getStartDateUtc()?->format('Y-m-d H:i:s T'));
        self::assertSame('2026-11-16 07:30', $strava->getLocalStartDate()?->format('Y-m-d H:i'));

        $manual = (new Activity())->setStartDate($stored)->setTimezone('America/New_York');
        self::assertSame('2026-11-16 01:30', $manual->getLocalStartDate()?->format('Y-m-d H:i'));
        self::assertSame('America/New_York', $manual->getTimeZoneObject()->getName());
    }

    #[Test]
    public function unknownOrMissingTimeZoneFallsBackToPhp(): void
    {
        $stored = new \DateTimeImmutable('2026-11-16 06:30:00', new \DateTimeZone('UTC'));
        $default = date_default_timezone_get();

        self::assertSame($default, (new Activity())->setStartDate($stored)->getTimeZoneObject()->getName());
        self::assertSame($default, (new Activity())->setTimezone('(GMT+01:00) Nowhere/Land')->getTimeZoneObject()->getName());
        self::assertSame($default, Activity::timeZoneFromField('')->getName());
        self::assertNull((new Activity())->getLocalStartDate());
        self::assertNull((new Activity())->getStartDateUtc());
    }
}
