<?php

namespace App\Tests\Unit\Service\Activity;

use App\Entity\Activity;
use App\Entity\Sport;
use App\Service\Activity\ActivityDuplicateRule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Doublon probable : même sport, départ à 30 min près (bornes incluses), activité modifiée exclue. */
final class ActivityDuplicateRuleTest extends TestCase
{
    private static function start(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-16 06:30:00', new \DateTimeZone('UTC'));
    }

    /** Activité telle que relue par Doctrine : heure UTC stockée, objet dans un autre fuseau. */
    private static function activity(string $slug, string $utcWallClock, ?int $id = null): Activity
    {
        $activity = (new Activity())
            ->setSport((new Sport())->setSlug($slug))
            ->setStartDate(new \DateTimeImmutable($utcWallClock, new \DateTimeZone('America/New_York')));
        if ($id !== null) {
            (new \ReflectionProperty(Activity::class, 'id'))->setValue($activity, $id);
        }

        return $activity;
    }

    #[Test]
    public function windowIsThirtyMinutesEachSideInUtc(): void
    {
        [$from, $to] = ActivityDuplicateRule::window(new \DateTimeImmutable('2026-11-16 07:30:00', new \DateTimeZone('Europe/Paris')));
        self::assertSame('2026-11-16 06:00:00 UTC', $from->format('Y-m-d H:i:s T'));
        self::assertSame('2026-11-16 07:00:00 UTC', $to->format('Y-m-d H:i:s T'));
    }

    #[Test]
    public function sameSportWithinThirtyMinutesIsADuplicate(): void
    {
        $start = self::start();
        self::assertTrue(ActivityDuplicateRule::matches(self::activity('run', '2026-11-16 06:30:00'), 'run', $start));
        self::assertTrue(ActivityDuplicateRule::matches(self::activity('run', '2026-11-16 06:00:00'), 'run', $start), '30 min avant : inclus');
        self::assertTrue(ActivityDuplicateRule::matches(self::activity('run', '2026-11-16 07:00:00'), 'run', $start), '30 min après : inclus');
        self::assertFalse(ActivityDuplicateRule::matches(self::activity('run', '2026-11-16 05:59:59'), 'run', $start));
        self::assertFalse(ActivityDuplicateRule::matches(self::activity('run', '2026-11-16 07:00:01'), 'run', $start));
        self::assertFalse(ActivityDuplicateRule::matches(self::activity('trail', '2026-11-16 06:30:00'), 'run', $start), 'autre sport');
        self::assertFalse(ActivityDuplicateRule::matches(new Activity(), 'run', $start), 'sans sport ni départ');
    }

    #[Test]
    public function theEditedActivityIsNotItsOwnDuplicate(): void
    {
        $self = self::activity('ride', '2026-11-16 06:35:00', 42);
        $other = self::activity('ride', '2026-11-16 06:40:00', 43);
        self::assertFalse(ActivityDuplicateRule::matches($self, 'ride', self::start(), 42));
        self::assertSame([$other], ActivityDuplicateRule::filter([$self, $other], 'ride', self::start(), 42));
        self::assertSame([$self, $other], ActivityDuplicateRule::filter([$self, $other], 'ride', self::start()));
    }
}
