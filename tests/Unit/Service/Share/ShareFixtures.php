<?php

namespace App\Tests\Unit\Service\Share;

use App\Entity\CheckpointType;
use App\Entity\Race;
use App\Entity\RaceCheckpoint;
use App\Entity\RaceIntent;
use App\Entity\RaceRoute;
use App\Entity\RaceScenario;
use App\Entity\RaceShare;
use App\Entity\User;
use App\Repository\RaceCheckpointRepository;
use App\Repository\RaceRouteRepository;
use App\Repository\RaceScenarioRepository;
use App\Repository\RaceScenarioSplitRepository;
use App\Service\Route\ScenarioViewBuilder;
use App\Service\Route\SplitCalculator;

/**
 * Données de test du partage public : une course piégée (valeurs sentinelles dans tout ce qui est privé), quatre
 * repères, un tracé synthétique et des scénarios calculés par le VRAI ScenarioViewBuilder (dépôts remplacés par des
 * bouchons), comme en production.
 */
trait ShareFixtures
{
    public const SENTINELS = [
        'email' => 'sentinel-owner@private.example.invalid',
        'raceNotes' => 'SENTINEL-RACE-NOTES-5e1d',
        'website' => 'https://sentinel-website.example.invalid/',
        'cpNotes' => 'SENTINEL-CHECKPOINT-NOTES-77aa',
        'fileName' => 'sentinel-gpx-file-name.gpx',
        'sourceUrl' => 'https://sentinel-gpx-source.example.invalid/trace.gpx',
        'scenarioName' => 'SENTINEL-SHARED-SCENARIO-NAME',
        'otherScenario' => 'SENTINEL-OTHER-SCENARIO-NAME',
        'lastName' => 'SENTINEL-LAST-NAME',
        'stravaToken' => 'SENTINEL-STRAVA-TOKEN',
        'token' => 'SentinelTokenAAAAAAAAAAAAAAAAAAA',
    ];
    public const USER_ID = 987654;

    private static function setId(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }

    private static function user(): User
    {
        $user = (new User())->setEmail(self::SENTINELS['email'])->setFirstName('Camille')->setLastName(self::SENTINELS['lastName'])
            ->setStravaAccessToken(self::SENTINELS['stravaToken'])->setStravaAthleteId(4242);
        self::setId($user, self::USER_ID);

        return $user;
    }

    private static function race(?\DateTimeImmutable $date = new \DateTimeImmutable('2027-06-12')): Race
    {
        $race = (new Race())->setUser(self::user())->setName('Trail des Aravis')->setYear(2027)->setRaceDate($date)
            ->setLocation('Annecy')->setIntent(RaceIntent::Registered)->setNotes(self::SENTINELS['raceNotes'])
            ->setWebsite(self::SENTINELS['website']);
        self::setId($race, 41);

        return $race;
    }

    /**
     * Tracé de 25 km, un point tous les $stepM m, une montée de 500 m entre les km 10 et 15.
     */
    private static function route(Race $race, int $stepM = 50): RaceRoute
    {
        $points = [];
        for ($d = 0; $d <= 25000; $d += $stepM) {
            $ele = 500.0 + ($d <= 10000 ? 0.0 : ($d <= 15000 ? ($d - 10000) / 10 : 500.0));
            $points[] = [$d, $ele, 45.0 + $d / 111195.08, 6.0];
        }
        $route = (new RaceRoute())->setRace($race)->setFileName(self::SENTINELS['fileName'])->setSourceUrl(self::SENTINELS['sourceUrl'])
            ->setPoints($points)->setDistanceM(25000)->setGainM(500)->setLossM(0)->setMinEle(500.0)->setMaxEle(1000.0);
        self::setId($route, 7);

        return $route;
    }

    /** @return list<RaceCheckpoint> */
    private static function checkpoints(Race $race): array
    {
        $defs = [
            [101, 'Col', 5000, CheckpointType::Point, null, null],
            [102, 'Ravito 1', 10000, CheckpointType::AidStation, 180, 3 * 3600],
            [103, 'Sommet', 15000, CheckpointType::Point, null, null],
            [104, 'Ravito 2', 20000, CheckpointType::AidStation, 300, 5 * 3600],
        ];
        $cps = [];
        foreach ($defs as [$id, $name, $distance, $type, $stop, $cutoff]) {
            $cp = (new RaceCheckpoint())->setRace($race)->setName($name)->setDistanceM($distance)->setType($type)
                ->setStopSeconds($stop)->setCutoffSeconds($cutoff)->setNotes(self::SENTINELS['cpNotes']);
            self::setId($cp, $id);
            $cps[] = $cp;
        }

        return $cps;
    }

    private static function scenario(Race $race, int $id, string $name, ?string $start, ?int $target, int $position = 0): RaceScenario
    {
        $scenario = (new RaceScenario())->setRace($race)->setName($name)->setPosition($position)->setTargetSeconds($target)
            ->setStartTime(null !== $start ? new \DateTimeImmutable('1970-01-01 '.$start.':00') : null);
        self::setId($scenario, $id);

        return $scenario;
    }

    private static function share(Race $race): RaceShare
    {
        $share = (new RaceShare())->setRace($race)->setToken(self::SENTINELS['token'])->setEnabled(true)->setDisplayName('Camille')
            ->setShowPaces(true)->setShowCutoffs(true);
        self::setId($share, 3);

        return $share;
    }

    /**
     * Vrai ScenarioViewBuilder::buildWith(), dépôts remplacés par des bouchons.
     *
     * @param list<RaceCheckpoint>         $checkpoints
     * @param list<RaceScenario>           $scenarios
     * @param array<int, array<int, int>>  $splits      scenarioId => [checkpointId => secondes fixées]
     *
     * @return array<string, mixed>
     */
    private function scenarioView(Race $race, ?RaceRoute $route, array $checkpoints, array $scenarios, array $splits = []): array
    {
        $scenarioRepo = $this->createStub(RaceScenarioRepository::class);
        $scenarioRepo->method('findByRaceOrdered')->willReturn($scenarios);
        $splitRepo = $this->createStub(RaceScenarioSplitRepository::class);
        $splitRepo->method('findForScenarios')->willReturn($splits + array_fill_keys(array_map(static fn (RaceScenario $s): int => (int) $s->getId(), $scenarios), []));
        $builder = new ScenarioViewBuilder($this->createStub(RaceRouteRepository::class), $this->createStub(RaceCheckpointRepository::class), $scenarioRepo, $splitRepo, new SplitCalculator());

        return $builder->buildWith($race, $route, $checkpoints);
    }
}
