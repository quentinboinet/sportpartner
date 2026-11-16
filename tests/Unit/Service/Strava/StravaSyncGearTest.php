<?php

namespace App\Tests\Unit\Service\Strava;

use App\Entity\Activity;
use App\Entity\Gear;
use App\Entity\GearBrand;
use App\Entity\GearType;
use App\Entity\Sport;
use App\Entity\User;
use App\Repository\ActivityRepository;
use App\Repository\GearRepository;
use App\Repository\SportRepository;
use App\Service\Strava\StravaService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Rattachement automatique du matériel par défaut aux activités créées par la synchro Strava. */
class StravaSyncGearTest extends TestCase
{
    private static function gear(GearType $type, ?string $purchasedAt = null): Gear
    {
        $brand = (new GearBrand())->setName('Decathlon')->setForShoes(true)->setForBike(true);

        return (new Gear())->setType($type)->setBrand($brand)->setModel('Test')
            ->setPurchasedAt($purchasedAt !== null ? new \DateTimeImmutable($purchasedAt) : null);
    }

    public function testPickDefaultGear(): void
    {
        $shoes    = self::gear(GearType::Shoes, '2026-06-01');
        $bike     = self::gear(GearType::Bike);
        $defaults = ['shoes' => $shoes, 'bike' => $bike];
        $date     = new \DateTimeImmutable('2026-10-01T07:00:00Z');

        $this->assertSame($shoes, StravaService::pickDefaultGear($defaults, 'run', $date));
        $this->assertSame($shoes, StravaService::pickDefaultGear($defaults, 'hike', $date));
        $this->assertSame($bike, StravaService::pickDefaultGear($defaults, 'ride', $date));
        $this->assertNull(StravaService::pickDefaultGear($defaults, 'swim', $date));
        $this->assertNull(StravaService::pickDefaultGear($defaults, 'other', $date));
        $this->assertNull(StravaService::pickDefaultGear(['bike' => $bike], 'trail', $date), 'no default shoes');
        // Activité antérieure à l'achat des chaussures
        $this->assertNull(StravaService::pickDefaultGear($defaults, 'run', new \DateTimeImmutable('2026-05-31T07:00:00Z')));
        $this->assertSame($shoes, StravaService::pickDefaultGear($defaults, 'run', null), 'no start date');
    }

    public function testSyncAssignsDefaultGearToNewActivitiesOnly(): void
    {
        $user = (new User())->setStravaAccessToken('token')
            ->setStravaTokenExpiresAt(new \DateTimeImmutable('+1 hour'));

        $sports = [];
        foreach (['run', 'ride', 'swim', 'other'] as $slug) {
            $sports[] = (new Sport())->setSlug($slug)->setIcon('bi-x')->setColor('#000000');
        }
        $shoes = self::gear(GearType::Shoes);
        $bike  = self::gear(GearType::Bike);

        $payload = [
            ['id' => 1, 'sport_type' => 'Run', 'name' => 'Footing', 'distance' => 10000.0, 'start_date' => '2026-11-10T07:00:00Z'],
            ['id' => 2, 'sport_type' => 'Ride', 'name' => 'Sortie', 'distance' => 50000.0, 'start_date' => '2026-11-11T07:00:00Z'],
            ['id' => 3, 'sport_type' => 'Swim', 'name' => 'Piscine', 'distance' => 2000.0, 'start_date' => '2026-11-12T07:00:00Z'],
            ['id' => 4, 'sport_type' => 'Run', 'name' => 'Déjà importée', 'distance' => 8000.0, 'start_date' => '2026-11-09T07:00:00Z'],
        ];
        $http = new MockHttpClient([new MockResponse((string) json_encode($payload))]);

        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $o) use (&$persisted): void {
            $persisted[] = $o;
        });

        $activityRepo = $this->createMock(ActivityRepository::class);
        $activityRepo->method('findExistingStravaIds')->willReturn([4]);
        $sportRepo = $this->createMock(SportRepository::class);
        $sportRepo->method('findAll')->willReturn($sports);
        $gearRepo = $this->createMock(GearRepository::class);
        $gearRepo->expects($this->once())->method('findDefaultsByType')->with($user)
            ->willReturn(['shoes' => $shoes, 'bike' => $bike]);

        $service  = new StravaService($http, $em, $activityRepo, $sportRepo, $gearRepo, 'id', 'secret', 'http://localhost/cb');
        $imported = $service->syncUserActivities($user);

        $this->assertSame(3, $imported);
        $byName = [];
        foreach ($persisted as $activity) {
            $this->assertInstanceOf(Activity::class, $activity);
            $byName[(string) $activity->getName()] = $activity;
        }
        $this->assertSame(['Footing', 'Sortie', 'Piscine'], array_keys($byName));
        $this->assertSame($shoes, $byName['Footing']->getGear());
        $this->assertSame($bike, $byName['Sortie']->getGear());
        $this->assertNull($byName['Piscine']->getGear());
    }

    /**
     * Matériel importé de Strava : le gear_id de l'activité l'emporte sur le matériel par défaut,
     * et une activité déjà comptée dans le kilométrage initial lors de l'import (id <= dernier id
     * vu à l'import) en est retirée, pour ne pas compter sa distance deux fois.
     */
    public function testSyncLinksImportedStravaGearBeforeDefaultGear(): void
    {
        $user = (new User())->setStravaAccessToken('token')
            ->setStravaTokenExpiresAt(new \DateTimeImmutable('+1 hour'));

        $sports = [];
        foreach (['run', 'ride', 'other'] as $slug) {
            $sports[] = (new Sport())->setSlug($slug)->setIcon('bi-x')->setColor('#000000');
        }
        $defaultShoes = self::gear(GearType::Shoes);
        $hoka = self::gear(GearType::Shoes)->setStravaGearId('g1')->setStravaLastActivityId(50)->setInitialDistanceM(100_000);
        $bike = self::gear(GearType::Bike)->setStravaGearId('b1')->setStravaLastActivityId(50)->setInitialDistanceM(5_000);

        $payload = [
            ['id' => 60, 'sport_type' => 'Run', 'name' => 'Après import', 'distance' => 8000.0, 'gear_id' => 'g1', 'start_date' => '2026-11-14T07:00:00Z'],
            ['id' => 47, 'sport_type' => 'Ride', 'name' => 'Vélo', 'distance' => 20000.0, 'gear_id' => 'b1', 'start_date' => '2026-11-03T07:00:00Z'],
            ['id' => 46, 'sport_type' => 'Run', 'name' => 'Sans matériel Strava', 'distance' => 6000.0, 'gear_id' => null, 'start_date' => '2026-11-02T07:00:00Z'],
            ['id' => 45, 'sport_type' => 'Run', 'name' => 'Matériel non importé', 'distance' => 7000.0, 'gear_id' => 'g999', 'start_date' => '2026-11-01T07:00:00Z'],
            ['id' => 40, 'sport_type' => 'Run', 'name' => 'Avant import', 'distance' => 10000.0, 'gear_id' => 'g1', 'start_date' => '2026-10-30T07:00:00Z'],
        ];
        $http = new MockHttpClient([new MockResponse((string) json_encode($payload))]);

        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $o) use (&$persisted): void {
            $persisted[] = $o;
        });
        $activityRepo = $this->createMock(ActivityRepository::class);
        $activityRepo->method('findExistingStravaIds')->willReturn([]);
        $sportRepo = $this->createMock(SportRepository::class);
        $sportRepo->method('findAll')->willReturn($sports);
        $gearRepo = $this->createMock(GearRepository::class);
        $gearRepo->method('findDefaultsByType')->willReturn(['shoes' => $defaultShoes]);
        $gearRepo->expects($this->once())->method('findStravaGearMap')->with($user)->willReturn(['g1' => $hoka, 'b1' => $bike]);

        $service = new StravaService($http, $em, $activityRepo, $sportRepo, $gearRepo, 'id', 'secret', 'http://localhost/cb');
        $this->assertSame(5, $service->syncUserActivities($user));

        $byName = [];
        foreach ($persisted as $activity) {
            $this->assertInstanceOf(Activity::class, $activity);
            $byName[(string) $activity->getName()] = $activity;
        }
        $this->assertSame($hoka, $byName['Après import']->getGear());
        $this->assertSame($hoka, $byName['Avant import']->getGear());
        $this->assertSame($bike, $byName['Vélo']->getGear());
        $this->assertSame($defaultShoes, $byName['Sans matériel Strava']->getGear(), 'no gear on Strava: default gear');
        $this->assertSame($defaultShoes, $byName['Matériel non importé']->getGear(), 'unknown Strava gear: default gear');

        // 40 <= 50 : déjà dans le total Strava importé ; 60 > 50 : postérieure à l'import
        $this->assertSame(90_000, $hoka->getInitialDistanceM());
        $this->assertSame(0, $bike->getInitialDistanceM(), 'never negative');
        $this->assertSame(0, $defaultShoes->getInitialDistanceM());
    }
}
