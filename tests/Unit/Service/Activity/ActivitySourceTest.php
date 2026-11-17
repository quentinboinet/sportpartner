<?php

namespace App\Tests\Unit\Service\Activity;

use App\Entity\Activity;
use App\Entity\HealthSession;
use App\Entity\User;
use App\Repository\HealthSessionRepository;
use App\Service\Activity\ActivitySource;
use App\Service\Activity\ActivitySourceResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Origine d'une activité : Strava (stravaId), séance Santé (séance liée), sinon saisie manuelle, seule modifiable. */
final class ActivitySourceTest extends TestCase
{
    private static function activity(int $id, ?int $stravaId = null): Activity
    {
        $activity = (new Activity())->setStravaId($stravaId);
        (new \ReflectionProperty(Activity::class, 'id'))->setValue($activity, $id);

        return $activity;
    }

    #[Test]
    public function classification(): void
    {
        self::assertSame(ActivitySource::Strava, ActivitySource::of(123456789, false));
        self::assertSame(ActivitySource::Strava, ActivitySource::of(123456789, true), 'le stravaId prime');
        self::assertSame(ActivitySource::Health, ActivitySource::of(null, true));
        self::assertSame(ActivitySource::Manual, ActivitySource::of(null, false));

        self::assertTrue(ActivitySource::Manual->isEditable());
        self::assertFalse(ActivitySource::Strava->isEditable());
        self::assertFalse(ActivitySource::Health->isEditable());
        self::assertSame(['activity_source.strava', 'activity_source.manual', 'activity_source.health'],
            array_map(static fn (ActivitySource $s): string => $s->labelKey(), ActivitySource::cases()));
        foreach (ActivitySource::cases() as $source) {
            self::assertStringStartsWith('bi-', $source->icon());
        }
    }

    #[Test]
    public function resolverAsksTheHealthSessionsOnlyForLocalActivities(): void
    {
        $repo = $this->createMock(HealthSessionRepository::class);
        $repo->expects(self::once())->method('findActivityIdsAmong')->with([2, 3])->willReturn([3 => true]);
        $resolver = new ActivitySourceResolver($repo);

        $sources = $resolver->sourcesOf([self::activity(1, 987), self::activity(2), self::activity(3)]);
        self::assertSame([1 => ActivitySource::Strava, 2 => ActivitySource::Manual, 3 => ActivitySource::Health], $sources);
    }

    #[Test]
    public function resolverSingleActivity(): void
    {
        $session = new HealthSession(new User());
        $repo = $this->createMock(HealthSessionRepository::class);
        $repo->expects(self::exactly(2))->method('findOneByActivity')->willReturnOnConsecutiveCalls($session, null);
        $resolver = new ActivitySourceResolver($repo);

        self::assertSame(ActivitySource::Health, $resolver->sourceOf(self::activity(5)));
        self::assertSame(ActivitySource::Manual, $resolver->sourceOf(self::activity(6)));
        // Activité Strava : aucune requête (sinon le mock aurait reçu un troisième appel)
        self::assertSame(ActivitySource::Strava, $resolver->sourceOf(self::activity(7, 555)));
        self::assertNull($resolver->healthSessionFor(self::activity(8, 556)));
    }
}
