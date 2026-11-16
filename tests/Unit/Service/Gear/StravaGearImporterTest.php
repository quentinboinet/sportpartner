<?php

namespace App\Tests\Unit\Service\Gear;

use App\Entity\Gear;
use App\Entity\GearBrand;
use App\Entity\GearType;
use App\Entity\User;
use App\Repository\ActivityRepository;
use App\Repository\GearBrandRepository;
use App\Repository\GearRepository;
use App\Repository\SportRepository;
use App\Service\Gear\StravaGearImporter;
use App\Service\Gear\StravaGearImportResult;
use App\Service\Strava\StravaService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Import du matériel Strava : aucune requête réelle, l'API est simulée par MockHttpClient
 * (activités paginées, GET /athlete, GET /gear/{id}, rafraîchissement du jeton).
 */
class StravaGearImporterTest extends TestCase
{
    private const API = 'https://www.strava.com/api/v3';

    /** @var list<array{method: string, path: string, query: array<string, mixed>, auth: ?string}> */
    private array $requests = [];
    /** @var list<object> */
    private array $persisted = [];
    /** @var array<int, list<int>> Matériel => activités rattachées par assignGear() */
    private array $assigned = [];
    private int $nextGearId = 1000;
    private bool $transaction = false;
    /** @var array<string, GearBrand> */
    private array $brands = [];

    protected function setUp(): void
    {
        $list = [
            [1, 'Hoka', true, false], [2, 'New Balance', true, false], [3, 'Kiprun', true, false],
            [4, 'Nike', true, false], [5, 'Canyon', false, true], [6, 'Specialized', false, true],
            [7, 'Decathlon', true, true], [99, GearBrand::OTHER, true, true],
        ];
        foreach ($list as [$id, $name, $shoes, $bike]) {
            $brand = (new GearBrand())->setName($name)->setForShoes($shoes)->setForBike($bike);
            self::setId($brand, $id);
            $this->brands[$name] = $brand;
        }
    }

    // ── Fabriques ──────────────────────────────────────────────────────────────

    private static function setId(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }

    private static function user(string $token = 'token', string $expires = '+1 hour'): User
    {
        $user = (new User())->setEmail('gearimport-test@example.invalid')
            ->setStravaAthleteId(42)
            ->setStravaAccessToken($token)
            ->setStravaRefreshToken('refresh-old')
            ->setStravaTokenExpiresAt(new \DateTimeImmutable($expires));
        self::setId($user, 7);

        return $user;
    }

    private function gear(int $id, GearType $type, string $brand, string $model, ?string $stravaId = null): Gear
    {
        $gear = (new Gear())->setUser(self::user())->setType($type)->setBrand($this->brands[$brand])
            ->setModel($model)->setStravaGearId($stravaId)->setMaxDistanceM($type->defaultMaxDistanceM());
        self::setId($gear, $id);

        return $gear;
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed> DetailedGear Strava
     */
    private static function detail(string $id, string $brand, string $model, string $name, float $distance, bool $primary = false, array $extra = []): array
    {
        return ['id' => $id, 'resource_state' => 3, 'brand_name' => $brand, 'model_name' => $model, 'name' => $name,
            'distance' => $distance, 'primary' => $primary, 'description' => null] + $extra;
    }

    /**
     * Activité Strava résumée (seuls id et gear_id servent à l'import).
     *
     * @return array<string, mixed>
     */
    private static function activity(int $id, ?string $gearId): array
    {
        return ['id' => $id, 'name' => 'Sortie '.$id, 'sport_type' => 'Run', 'distance' => 10000.0, 'gear_id' => $gearId];
    }

    /**
     * API Strava simulée.
     *
     * @param array<int, list<array<string, mixed>>>|\Closure(int): list<array<string, mixed>> $pages   Activités par page.
     * @param array<string, array<string, mixed>|ResponseInterface>                              $gear    Matériel (ou réponse brute : 404, 403...).
     * @param array<string, mixed>|ResponseInterface                                            $athlete Réponse de GET /athlete.
     * @param array<int, ResponseInterface>                                                     $pageErrors Réponse brute pour une page donnée.
     */
    private function http(array|\Closure $pages, array $gear = [], array|ResponseInterface $athlete = ['id' => 42, 'resource_state' => 2], array $pageErrors = []): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options) use ($pages, $gear, $athlete, $pageErrors): ResponseInterface {
            $path = (string) parse_url($url, PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $this->requests[] = [
                'method' => $method,
                'path'   => $path,
                'query'  => $query,
                'auth'   => $options['normalized_headers']['authorization'][0] ?? null,
            ];

            if ($method === 'POST' && $url === 'https://www.strava.com/oauth/token') {
                return new JsonMockResponse(['access_token' => 'fresh', 'refresh_token' => 'refresh-new', 'expires_at' => time() + 21600]);
            }
            if ($path === '/api/v3/athlete/activities') {
                $page = (int) ($query['page'] ?? 1);
                if (isset($pageErrors[$page])) {
                    return $pageErrors[$page];
                }

                return new JsonMockResponse($pages instanceof \Closure ? $pages($page) : ($pages[$page] ?? []));
            }
            if ($path === '/api/v3/athlete') {
                return $athlete instanceof ResponseInterface ? $athlete : new JsonMockResponse($athlete);
            }
            if (str_starts_with($path, '/api/v3/gear/')) {
                $id = rawurldecode(substr($path, strlen('/api/v3/gear/')));
                $g  = $gear[$id] ?? new MockResponse('{"message":"Record Not Found"}', ['http_code' => 404]);

                return $g instanceof ResponseInterface ? $g : new JsonMockResponse($g);
            }

            return new MockResponse('{"message":"Unexpected"}', ['http_code' => 500]);
        });
    }

    /**
     * @param list<Gear>                                                         $existing Matériel déjà en base.
     * @param list<array{id: int, stravaId: ?int, gearId: ?int, distanceM: float}> $rows    Activités déjà en base.
     */
    private function importer(MockHttpClient $http, array $existing = [], array $rows = []): StravaGearImporter
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $o): void {
            $this->persisted[] = $o;
        });
        // Comme Doctrine : les nouveaux matériels reçoivent un id au flush
        $em->method('flush')->willReturnCallback(function (): void {
            foreach ($this->persisted as $o) {
                if ($o instanceof Gear && $o->getId() === null) {
                    self::setId($o, $this->nextGearId++);
                }
            }
        });
        $em->method('wrapInTransaction')->willReturnCallback(function (callable $func) use ($em): mixed {
            $this->transaction = true;

            return $func($em);
        });

        $gearRepo = $this->createMock(GearRepository::class);
        $gearRepo->method('findByUser')->willReturn($existing);
        $brandRepo = $this->createMock(GearBrandRepository::class);
        $brandRepo->method('findAllOrdered')->willReturn(array_values($this->brands));
        $activityRepo = $this->createMock(ActivityRepository::class);
        $activityRepo->method('findGearLinkRows')->willReturn($rows);
        $activityRepo->method('assignGear')->willReturnCallback(function (User $user, int $gearId, array $ids): int {
            $this->assigned[$gearId] = $ids;

            return count($ids);
        });

        $strava = new StravaService($http, $em, $this->createMock(ActivityRepository::class), $this->createMock(SportRepository::class), $gearRepo, 'id', 'secret', 'http://localhost/cb');

        return new StravaGearImporter($http, $strava, $em, $gearRepo, $brandRepo, $activityRepo);
    }

    /** @return array<string, Gear> Matériel créé, indexé par identifiant Strava. */
    private function created(): array
    {
        $created = [];
        foreach ($this->persisted as $o) {
            if ($o instanceof Gear) {
                $created[(string) $o->getStravaGearId()] = $o;
            }
        }

        return $created;
    }

    /** @return array<string, int> Nombre de requêtes par chemin. */
    private function requestCounts(): array
    {
        return array_count_values(array_map(static fn (array $r): string => $r['method'].' '.$r['path'], $this->requests));
    }

    // ── Lecture et création ────────────────────────────────────────────────────

    public function testPaginatesActivitiesAndCreatesBikesAndShoes(): void
    {
        // Page 1 pleine (200 activités, les plus récentes d'abord), page 2 incomplète : fin
        $page1 = [];
        for ($id = 1000; $id > 800; $id--) {
            $page1[] = self::activity($id, match (true) {
                $id === 1000 => 'g1',
                $id === 999  => 'b1',
                $id === 900  => 'g2',
                default      => $id % 2 === 0 ? 'g1' : null,
            });
        }
        $page2 = [self::activity(800, 'g1'), self::activity(799, 'b2'), self::activity(798, null), self::activity(797, 'g3')];

        $http = $this->http([1 => $page1, 2 => $page2], [
            'g1' => self::detail('g1', 'HOKA ONE ONE', 'Speedgoat 6', 'Les rouges', 773_000.0, true),
            'g2' => self::detail('g2', 'New-Balance', 'Fresh Foam 1080', 'New Balance Fresh Foam 1080', 120_000.4),
            'g3' => self::detail('g3', 'Marque inconnue', '', 'Mystery shoes', 5_000.0),
            'b1' => self::detail('b1', 'Canyon', 'Endurace CF 8', 'Canyon Endurace CF 8', 2_500_000.0, true, ['frame_type' => 3]),
            'b2' => self::detail('b2', 'Riverside', 'Touring 520', 'Vélo du dimanche', 300_000.0, false, ['retired' => true]),
        ]);

        $result = $this->importer($http)->import(self::user());

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(5, $result->created);
        $this->assertSame(0, $result->updated);
        $this->assertSame(0, $result->linked);
        $this->assertSame(0, $result->skipped);
        $this->assertFalse($result->truncated);
        $this->assertTrue($result->profileScopeMissing, 'GET /athlete without bikes/shoes = no profile:read_all');

        // 2 pages, 1 GET /athlete, un seul GET /gear/{id} par matériel
        $counts = $this->requestCounts();
        $this->assertSame(2, $counts['GET /api/v3/athlete/activities']);
        $this->assertSame(1, $counts['GET /api/v3/athlete']);
        foreach (['g1', 'g2', 'g3', 'b1', 'b2'] as $id) {
            $this->assertSame(1, $counts['GET /api/v3/gear/'.$id], $id);
        }
        $this->assertCount(8, $this->requests);
        $this->assertSame(['per_page' => '200', 'page' => '1'], $this->requests[0]['query']);
        $this->assertSame('Authorization: Bearer token', $this->requests[0]['auth']);

        $gear = $this->created();
        $this->assertSame(['g1', 'b1', 'g2', 'b2', 'g3'], array_keys($gear));

        $this->assertSame(GearType::Shoes, $gear['g1']->getType());
        $this->assertSame('Hoka', $gear['g1']->getBrand()->getName());
        $this->assertSame('Speedgoat 6', $gear['g1']->getModel());
        $this->assertSame('Les rouges', $gear['g1']->getNickname());
        $this->assertSame(700_000, $gear['g1']->getMaxDistanceM(), 'default lifespan for new shoes');
        $this->assertSame(773_000, $gear['g1']->getInitialDistanceM(), 'no local activity: initial = Strava total');
        $this->assertSame(1000, $gear['g1']->getStravaLastActivityId());
        $this->assertTrue($gear['g1']->isDefault(), 'Strava primary, no default shoes yet');

        $this->assertSame(GearType::Bike, $gear['b1']->getType());
        $this->assertSame('Canyon', $gear['b1']->getBrand()->getName());
        $this->assertSame('Endurace CF 8', $gear['b1']->getModel());
        $this->assertNull($gear['b1']->getNickname(), 'name = brand + model');
        $this->assertNull($gear['b1']->getMaxDistanceM(), 'bike wear not tracked by default');
        $this->assertTrue($gear['b1']->isDefault());

        $this->assertSame('New Balance', $gear['g2']->getBrand()->getName());
        $this->assertNull($gear['g2']->getNickname());
        $this->assertSame(120_000, $gear['g2']->getInitialDistanceM());
        $this->assertFalse($gear['g2']->isDefault());

        $this->assertSame(GearBrand::OTHER, $gear['g3']->getBrand()->getName());
        $this->assertSame('Mystery shoes', $gear['g3']->getModel(), 'no model_name: name');
        $this->assertNull($gear['g3']->getNickname());

        $this->assertSame('Decathlon', $gear['b2']->getBrand()->getName(), 'Decathlon house brand');
        $this->assertSame('Vélo du dimanche', $gear['b2']->getNickname());
        $this->assertEquals(new \DateTimeImmutable('today'), $gear['b2']->getRetiredAt());
        $this->assertFalse($gear['b2']->isDefault());
    }

    public function testProfileGearIsImportedWithProfileScope(): void
    {
        $http = $this->http(
            [1 => [self::activity(10, 'g1')]],
            [
                'g1' => self::detail('g1', 'Hoka', 'Mach 6', 'Hoka Mach 6', 50_000.0),
                'b9' => self::detail('b9', 'Specialized', 'Tarmac SL8', 'Specialized Tarmac SL8', 0.0, true),
            ],
            ['id' => 42, 'resource_state' => 3, 'bikes' => [['id' => 'b9', 'primary' => true, 'name' => 'Specialized Tarmac SL8']], 'shoes' => [['id' => 'g1']]],
        );

        $result = $this->importer($http)->import(self::user());

        $this->assertFalse($result->profileScopeMissing);
        $this->assertSame(2, $result->created);
        $this->assertSame(['g1', 'b9'], array_keys($this->created()), 'gear never used, found on the profile');
        $this->assertSame(1, $this->requestCounts()['GET /api/v3/gear/g1'], 'listed twice, fetched once');
    }

    public function testInvalidOrUnknownGearIsSkipped(): void
    {
        $http = $this->http([1 => [self::activity(3, 'g1'), self::activity(2, 'g2'), self::activity(1, 'x77')]], [
            'g1' => self::detail('g1', 'Nike', 'Pegasus 41', 'Nike Pegasus 41', 80_000.0),
            // g2 : 404 (matériel supprimé sur Strava)
        ]);

        $result = $this->importer($http, [], [
            ['id' => 1, 'stravaId' => 2, 'gearId' => null, 'distanceM' => 9_000.0],
        ])->import(self::user());

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->created);
        $this->assertSame(2, $result->skipped, 'g2 not found, x77 unknown type');
        $this->assertSame(0, $result->linked, 'activity of the skipped gear untouched');
        $this->assertArrayNotHasKey('GET /api/v3/gear/x77', $this->requestCounts(), 'no request for an unknown type');
    }

    public function testEmptyStravaAccount(): void
    {
        $result = $this->importer($this->http([1 => []]))->import(self::user());

        $this->assertTrue($result->isEmpty());
        $this->assertSame([], $this->persisted);
    }

    // ── Mise à jour ────────────────────────────────────────────────────────────

    public function testExistingGearIsUpdatedNotDuplicated(): void
    {
        $shoes = $this->gear(10, GearType::Shoes, 'Hoka', 'Speedgoat 5', 'g1')
            ->setNickname('Mes Hoka')->setMaxDistanceM(800_000)->setNotes('Pointure 44')
            ->setPurchasedAt(new \DateTimeImmutable('2026-01-01'))->setInitialDistanceM(1);
        $bike = $this->gear(11, GearType::Bike, 'Canyon', 'Endurace', 'b1');

        $http = $this->http([1 => [self::activity(5, 'g1'), self::activity(4, 'b1')]], [
            'g1' => self::detail('g1', 'HOKA', 'Speedgoat 6', 'HOKA Speedgoat 6', 400_000.0),
            'b1' => self::detail('b1', '', 'Endurace CF 8', 'Endurace CF 8', 1_000_000.0),
        ]);

        $result = $this->importer($http, [$shoes, $bike])->import(self::user());

        $this->assertSame(0, $result->created);
        $this->assertSame(2, $result->updated);
        $this->assertSame([], $this->persisted, 'no duplicate');

        $this->assertSame('Speedgoat 6', $shoes->getModel(), 'Strava is the source for the model');
        $this->assertSame('Mes Hoka', $shoes->getNickname(), 'no Strava nickname: local one kept');
        $this->assertSame(800_000, $shoes->getMaxDistanceM(), 'local lifespan kept');
        $this->assertSame('Pointure 44', $shoes->getNotes());
        $this->assertEquals(new \DateTimeImmutable('2026-01-01'), $shoes->getPurchasedAt());
        $this->assertSame(400_000, $shoes->getInitialDistanceM(), 'recomputed on each import');

        $this->assertSame('Canyon', $bike->getBrand()->getName(), 'unknown Strava brand does not overwrite');
        $this->assertSame('Endurace CF 8', $bike->getModel());
    }

    public function testSingleManualTwinIsAdoptedInsteadOfDuplicated(): void
    {
        $manual = $this->gear(50, GearType::Shoes, 'Hoka', 'speedgoat 6')->setNotes('Saisie à la main');
        $http   = $this->http([1 => [self::activity(1, 'g1')]], [
            'g1' => self::detail('g1', 'HOKA ONE ONE', 'Speedgoat 6', 'Hoka Speedgoat 6', 10_000.0),
        ]);

        $result = $this->importer($http, [$manual])->import(self::user());

        $this->assertSame(0, $result->created);
        $this->assertSame(1, $result->updated);
        $this->assertSame('g1', $manual->getStravaGearId());
        $this->assertSame('Saisie à la main', $manual->getNotes());
    }

    public function testAmbiguousManualTwinsAreNotAdopted(): void
    {
        $a    = $this->gear(50, GearType::Shoes, 'Hoka', 'Speedgoat 6');
        $b    = $this->gear(51, GearType::Shoes, 'Hoka', 'Speedgoat 6');
        $http = $this->http([1 => [self::activity(1, 'g1')]], [
            'g1' => self::detail('g1', 'Hoka', 'Speedgoat 6', 'Hoka Speedgoat 6', 10_000.0),
        ]);

        $result = $this->importer($http, [$a, $b])->import(self::user());

        $this->assertSame(1, $result->created);
        $this->assertNull($a->getStravaGearId());
        $this->assertNull($b->getStravaGearId());
    }

    public function testRetirementFromStravaAndLocalRetirementKept(): void
    {
        $retiredHere = $this->gear(20, GearType::Shoes, 'Nike', 'Pegasus 40', 'g1')
            ->setRetiredAt(new \DateTimeImmutable('2026-03-01'));
        $activeHere  = $this->gear(21, GearType::Shoes, 'Nike', 'Pegasus 41', 'g2')->setIsDefault(true);

        $http = $this->http([1 => [self::activity(3, 'g1'), self::activity(2, 'g2'), self::activity(1, 'g3')]], [
            'g1' => self::detail('g1', 'Nike', 'Pegasus 40', 'Nike Pegasus 40', 900_000.0, false, ['retired' => false]),
            'g2' => self::detail('g2', 'Nike', 'Pegasus 41', 'Nike Pegasus 41', 300_000.0, false, ['retired' => true]),
            'g3' => self::detail('g3', 'Nike', 'Vomero 18', 'Nike Vomero 18', 100.0, true, ['retired' => true]),
        ]);

        $this->importer($http, [$retiredHere, $activeHere])->import(self::user());

        $this->assertEquals(new \DateTimeImmutable('2026-03-01'), $retiredHere->getRetiredAt(), 'local retirement kept');
        $this->assertEquals(new \DateTimeImmutable('today'), $activeHere->getRetiredAt(), 'retired on Strava');
        $this->assertFalse($activeHere->isDefault(), 'a retired gear cannot be the default one');
        $new = $this->created()['g3'];
        $this->assertTrue($new->isRetired());
        $this->assertFalse($new->isDefault(), 'retired primary gear does not become default');
    }

    public function testStravaPrimaryBecomesDefaultOnlyWithoutActiveDefault(): void
    {
        $defaultShoes = $this->gear(30, GearType::Shoes, 'Nike', 'Pegasus 41')->setIsDefault(true);
        // Incohérence possible : un vélo retiré encore marqué par défaut ne compte pas
        $oldBike = $this->gear(31, GearType::Bike, 'Canyon', 'Ultimate')->setIsDefault(true)
            ->setRetiredAt(new \DateTimeImmutable('2025-01-01'));

        $http = $this->http([1 => [self::activity(2, 'g1'), self::activity(1, 'b1')]], [
            'g1' => self::detail('g1', 'Hoka', 'Mach 6', 'Hoka Mach 6', 1_000.0, true),
            'b1' => self::detail('b1', 'Canyon', 'Endurace', 'Canyon Endurace', 1_000.0, true),
        ]);

        $this->importer($http, [$defaultShoes, $oldBike])->import(self::user());

        $gear = $this->created();
        $this->assertFalse($gear['g1']->isDefault(), 'user already has default shoes');
        $this->assertTrue($defaultShoes->isDefault());
        $this->assertTrue($gear['b1']->isDefault());
        $this->assertFalse($oldBike->isDefault());
    }

    // ── Rattachement des activités et kilométrage initial ──────────────────────

    /**
     * Strava : 101, 102, 104 -> g1 ; 103 sans matériel ; 105 -> b1 ; 106 -> g1 (absente de la base).
     * Base : 7 activités, dont une manuelle (sans id Strava) déjà rattachée à g1 et une inconnue de Strava.
     *
     * @return array{0: MockHttpClient, 1: list<Gear>, 2: list<array{id: int, stravaId: ?int, gearId: ?int, distanceM: float}>, 3: Gear}
     */
    private function linkingFixture(): array
    {
        $manual   = $this->gear(5, GearType::Shoes, 'Nike', 'Pegasus 41');
        $imported = $this->gear(40, GearType::Shoes, 'Hoka', 'Speedgoat 6', 'g1');

        $http = $this->http([1 => [
            self::activity(106, 'g1'), self::activity(105, 'b1'), self::activity(104, 'g1'),
            self::activity(103, null), self::activity(102, 'g1'), self::activity(101, 'g1'),
        ]], [
            'g1' => self::detail('g1', 'Hoka', 'Speedgoat 6', 'Hoka Speedgoat 6', 100_000.0),
            'b1' => self::detail('b1', 'Canyon', 'Endurace', 'Canyon Endurace', 20_000.0),
        ]);
        $rows = [
            ['id' => 1, 'stravaId' => 101, 'gearId' => null, 'distanceM' => 10_000.0],
            ['id' => 2, 'stravaId' => 102, 'gearId' => 5, 'distanceM' => 12_000.0],
            ['id' => 3, 'stravaId' => 103, 'gearId' => null, 'distanceM' => 8_000.0],
            ['id' => 4, 'stravaId' => 104, 'gearId' => 40, 'distanceM' => 20_000.0],
            ['id' => 5, 'stravaId' => 105, 'gearId' => null, 'distanceM' => 30_000.0],
            ['id' => 6, 'stravaId' => null, 'gearId' => 40, 'distanceM' => 5_000.0],
            ['id' => 7, 'stravaId' => 999, 'gearId' => null, 'distanceM' => 7_000.0],
        ];

        return [$http, [$manual, $imported], $rows, $imported];
    }

    public function testLinksOnlyActivitiesWithoutGear(): void
    {
        [$http, $existing, $rows, $g1] = $this->linkingFixture();

        $result = $this->importer($http, $existing, $rows)->import(self::user());

        $b1 = $this->created()['b1'];
        $this->assertSame(2, $result->linked);
        $this->assertSame([40 => [1], (int) $b1->getId() => [5]], $this->assigned, 'activity 2 keeps its manual gear, 3 and 7 untouched');
        // g1 : 100 km Strava - (10 + 20 + 5 km rattachés ici) ; l'activité 106 absente de la base reste dans le kilométrage initial
        $this->assertSame(65_000, $g1->getInitialDistanceM());
        $this->assertSame(0, $b1->getInitialDistanceM(), 'never negative (30 km linked > 20 km on Strava)');
        $this->assertSame(106, $g1->getStravaLastActivityId());
        $this->assertTrue($this->transaction);
    }

    public function testReplaceExistingLinks(): void
    {
        [$http, $existing, $rows, $g1] = $this->linkingFixture();

        $result = $this->importer($http, $existing, $rows)->import(self::user(), replaceLinks: true);

        $b1 = $this->created()['b1'];
        $this->assertSame(3, $result->linked, 'activity 4 already on g1 is not counted');
        $this->assertSame([40 => [1, 2], (int) $b1->getId() => [5]], $this->assigned);
        $this->assertSame(100_000 - 47_000, $g1->getInitialDistanceM());
    }

    // ── Erreurs Strava : rien n'est enregistré ─────────────────────────────────

    /** @return iterable<string, array{string, int, string}> */
    public static function httpErrorProvider(): iterable
    {
        yield 'activities 401'  => ['activities', 401, StravaGearImportResult::ERROR_AUTH];
        yield 'activities 403'  => ['activities', 403, StravaGearImportResult::ERROR_AUTH];
        yield 'activities 429'  => ['activities', 429, StravaGearImportResult::ERROR_RATE_LIMIT];
        yield 'activities 503'  => ['activities', 503, StravaGearImportResult::ERROR_UNAVAILABLE];
        yield 'page 2 429'      => ['page2', 429, StravaGearImportResult::ERROR_RATE_LIMIT];
        yield 'gear 401'        => ['gear', 401, StravaGearImportResult::ERROR_AUTH];
        yield 'gear 403'        => ['gear', 403, StravaGearImportResult::ERROR_AUTH];
        yield 'gear 429'        => ['gear', 429, StravaGearImportResult::ERROR_RATE_LIMIT];
        yield 'gear 500'        => ['gear', 500, StravaGearImportResult::ERROR_UNAVAILABLE];
        yield 'athlete 429'     => ['athlete', 429, StravaGearImportResult::ERROR_RATE_LIMIT];
    }

    #[DataProvider('httpErrorProvider')]
    public function testHttpErrorsStopTheImportBeforeWriting(string $where, int $status, string $expected): void
    {
        $error  = new MockResponse('{"message":"Error"}', ['http_code' => $status]);
        $full   = array_map(static fn (int $i): array => self::activity(5000 - $i, 'g1'), range(0, StravaGearImporter::PAGE_SIZE - 1));
        $pages  = [1 => $where === 'page2' ? $full : [self::activity(1, 'g1')], 2 => []];
        $http   = $this->http(
            $pages,
            ['g1' => $where === 'gear' ? $error : self::detail('g1', 'Hoka', 'Mach 6', 'Hoka Mach 6', 1.0)],
            $where === 'athlete' ? $error : ['id' => 42],
            match ($where) {
                'activities' => [1 => $error],
                'page2'      => [2 => $error],
                default      => [],
            },
        );

        $result = $this->importer($http)->import(self::user());

        $this->assertFalse($result->isSuccessful());
        $this->assertSame([$expected], $result->errors);
        $this->assertSame(0, $result->created);
        $this->assertFalse($this->transaction, 'nothing written');
        $this->assertSame([], $this->persisted);
    }

    public function testAthleteErrorOtherThanRateLimitIsIgnored(): void
    {
        $http = $this->http([1 => [self::activity(1, 'g1')]], ['g1' => self::detail('g1', 'Hoka', 'Mach 6', 'Hoka Mach 6', 1.0)],
            new MockResponse('{"message":"Forbidden"}', ['http_code' => 403]));

        $result = $this->importer($http)->import(self::user());

        $this->assertTrue($result->isSuccessful(), 'GET /athlete is best effort');
        $this->assertSame(1, $result->created);
        $this->assertFalse($result->profileScopeMissing);
    }

    public function testNetworkErrorAndInvalidJson(): void
    {
        $network = $this->http([], [], ['id' => 42], [1 => new MockResponse('', ['error' => 'Could not resolve host: www.strava.com'])]);
        $this->assertSame([StravaGearImportResult::ERROR_UNAVAILABLE], $this->importer($network)->import(self::user())->errors);

        $invalid = $this->http([], [], ['id' => 42], [1 => new MockResponse('<html>maintenance</html>')]);
        $this->assertSame([StravaGearImportResult::ERROR_UNAVAILABLE], $this->importer($invalid)->import(self::user())->errors);

        $notList = $this->http([], [], ['id' => 42], [1 => new JsonMockResponse(['message' => 'odd'])]);
        $this->assertSame([StravaGearImportResult::ERROR_UNAVAILABLE], $this->importer($notList)->import(self::user())->errors);
        $this->assertFalse($this->transaction);
    }

    public function testNotConnected(): void
    {
        $user = (new User())->setEmail('gearimport-test@example.invalid');

        $result = $this->importer($this->http([1 => []]))->import($user);

        $this->assertSame([StravaGearImportResult::ERROR_NOT_CONNECTED], $result->errors);
        $this->assertSame([], $this->requests);
    }

    // ── Jeton et garde-fous ────────────────────────────────────────────────────

    public function testExpiredTokenIsRefreshedFirst(): void
    {
        $user = self::user('expired', '-1 hour');
        $http = $this->http([1 => [self::activity(1, 'g1')]], ['g1' => self::detail('g1', 'Hoka', 'Mach 6', 'Hoka Mach 6', 1.0)]);

        $result = $this->importer($http)->import($user);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('POST', $this->requests[0]['method']);
        $this->assertSame('/oauth/token', $this->requests[0]['path']);
        $this->assertSame('fresh', $user->getStravaAccessToken());
        foreach (array_slice($this->requests, 1) as $request) {
            $this->assertSame('Authorization: Bearer fresh', $request['auth']);
        }
    }

    public function testRevokedRefreshTokenAsksToReconnect(): void
    {
        $user = self::user('expired', '-1 hour');
        $http = new MockHttpClient(new MockResponse('{"message":"Bad Request","errors":[{"field":"refresh_token","code":"invalid"}]}', ['http_code' => 400]));

        $result = $this->importer($http)->import($user);

        $this->assertSame([StravaGearImportResult::ERROR_AUTH], $result->errors);
    }

    public function testStopsAfterMaxPages(): void
    {
        $pages = static fn (int $page): array => array_map(
            static fn (int $i): array => self::activity(1_000_000 - $page * 1000 - $i, null),
            range(0, StravaGearImporter::PAGE_SIZE - 1),
        );

        $result = $this->importer($this->http($pages))->import(self::user());

        $this->assertTrue($result->truncated);
        $this->assertSame(StravaGearImporter::MAX_PAGES, $this->requestCounts()['GET /api/v3/athlete/activities']);
    }

    /** @return iterable<string, array{string, ?GearType}> */
    public static function gearIdProvider(): iterable
    {
        yield 'shoes' => ['g12345678', GearType::Shoes];
        yield 'bike'  => ['b1234', GearType::Bike];
        yield 'other' => ['c123', null];
        yield 'no digits' => ['g', null];
        yield 'injection' => ['g1/../athlete', null];
    }

    #[DataProvider('gearIdProvider')]
    public function testTypeFromId(string $id, ?GearType $expected): void
    {
        $this->assertSame($expected, StravaGearImporter::typeFromId($id));
    }
}
