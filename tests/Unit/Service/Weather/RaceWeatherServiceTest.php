<?php

namespace App\Tests\Unit\Service\Weather;

use App\Entity\Race;
use App\Entity\RaceRoute;
use App\Service\Weather\RaceWeatherService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Aujourd'hui = 15/11/2026 (horloge du service). Le cache (ArrayAdapter) a sa propre horloge,
 * partie de l'heure réelle, que l'on avance pour tester les durées de vie.
 */
class RaceWeatherServiceTest extends TestCase
{
    private const TODAY = '2026-11-15 10:00:00';

    /** @var list<array{host: string, path: string, query: array<string, string>}> */
    private array $requests = [];
    private MockClock $cacheClock;
    private ArrayAdapter $cache;

    protected function setUp(): void
    {
        $this->requests = [];
        $this->cacheClock = new MockClock();
        $this->cache = new ArrayAdapter(0, true, 0, 0, $this->cacheClock);
    }

    // ── Lieu ────────────────────────────────────────────────────────────

    public function testNoDateMakesNoRequest(): void
    {
        $data = $this->service([])->forRace($this->race(null, 'Annecy'), $this->route(45.9, 6.13));

        $this->assertSame('no_date', $data['state']);
        $this->assertNull($data['date']);
        $this->assertNull($data['place']);
        $this->assertSame([], $this->requests);
    }

    public function testNoLocationWithoutGpxNorPlace(): void
    {
        $data = $this->service([])->forRace($this->race('2026-11-20', '   '), null);

        $this->assertSame('no_location', $data['state']);
        $this->assertSame('missing', $data['reason']);
        $this->assertSame('2026-11-20', $data['date']);
        $this->assertSame([], $this->requests);
    }

    public function testGpxStartIsPreferredOverGeocoding(): void
    {
        $service = $this->service([new MockResponse(self::forecastJson('2026-11-20'))]);
        $data = $service->forRace($this->race('2026-11-20', 'Annecy'), $this->route(45.8990024, 6.1290261));

        $this->assertSame('forecast', $data['state']);
        $this->assertSame(['name' => 'Annecy', 'region' => null, 'lat' => 45.899, 'lon' => 6.12903, 'source' => 'gpx'], $data['place']);
        $this->assertCount(1, $this->requests, 'no geocoding call when the GPX gives the start point');
        $this->assertSame('api.open-meteo.com', $this->requests[0]['host']);
        $this->assertSame('45.899', $this->requests[0]['query']['latitude']);
        $this->assertSame('6.129', $this->requests[0]['query']['longitude']);
    }

    public function testGpxStartWithoutLocationHasNoName(): void
    {
        $service = $this->service([new MockResponse(self::forecastJson('2026-11-20'))]);
        $data = $service->forRace($this->race('2026-11-20', null), $this->route(45.9, 6.13));

        $this->assertSame('gpx', $data['place']['source']);
        $this->assertNull($data['place']['name'], 'the controller adds the translated « Départ du parcours » label');
    }

    public function testEmptyRouteFallsBackToGeocoding(): void
    {
        $service = $this->service([
            new MockResponse(self::geocodingJson('Annecy', 45.90878, 6.12565)),
            new MockResponse(self::forecastJson('2026-11-20')),
        ]);
        $data = $service->forRace($this->race('2026-11-20', 'Annecy'), (new RaceRoute())->setPoints([]));

        $this->assertSame('forecast', $data['state']);
        $this->assertSame('location', $data['place']['source']);
        $this->assertSame('geocoding-api.open-meteo.com', $this->requests[0]['host']);
    }

    public function testGeocodingTriesTheFirstPartOfTheLocation(): void
    {
        $service = $this->service([
            new MockResponse('{"generationtime_ms":0.2}'),
            new MockResponse(self::geocodingJson('Chamonix-Mont-Blanc', 45.92375, 6.86933)),
            new MockResponse(self::forecastJson('2026-11-20')),
        ]);
        $data = $service->forRace($this->race('2026-11-20', 'Chamonix (74)'), null);

        $this->assertSame(['Chamonix (74)', 'Chamonix'], [$this->requests[0]['query']['name'], $this->requests[1]['query']['name']]);
        $this->assertSame(['count' => '1', 'language' => 'fr', 'format' => 'json'], array_intersect_key($this->requests[0]['query'], ['count' => 1, 'language' => 1, 'format' => 1]));
        $this->assertSame([
            'name'   => 'Chamonix-Mont-Blanc',
            'region' => 'Auvergne-Rhône-Alpes, France',
            'lat'    => 45.92375,
            'lon'    => 6.86933,
            'source' => 'location',
        ], $data['place']);
        $this->assertSame('6.869', $this->requests[2]['query']['longitude']);
    }

    public function testUnknownLocationIsNoLocationAndCached(): void
    {
        $service = $this->service([new MockResponse('{"generationtime_ms":0.2}'), new MockResponse('{"results":[]}')]);
        $race = $this->race('2026-11-20', 'Lac imaginaire (99)');

        $data = $service->forRace($race, null);
        $this->assertSame('no_location', $data['state']);
        $this->assertSame('not_found', $data['reason']);
        $this->assertCount(2, $this->requests);

        // Même nom (casse et espaces normalisés) : réponse en cache, aucun appel
        $service->forRace($this->race('2026-11-20', '  LAC   imaginaire (99) '), null);
        $this->assertCount(2, $this->requests);
    }

    public function testGeocodingFailureIsUnavailableNotNoLocation(): void
    {
        $data = $this->service([new MockResponse('oops', ['http_code' => 503])])->forRace($this->race('2026-11-20', 'Annecy'), null);

        $this->assertSame('unavailable', $data['state']);
        $this->assertNull($data['place']);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function candidates(): iterable
    {
        yield 'simple' => ['Annecy', ['Annecy']];
        yield 'department' => ['Annecy (74)', ['Annecy (74)', 'Annecy']];
        yield 'country' => ['  Chamonix ,  France ', ['Chamonix , France', 'Chamonix']];
        yield 'postcode' => ['74000 Annecy', ['74000 Annecy', 'Annecy']];
        yield 'spaced dash' => ['Saint-Martin-Vésubie - Alpes-Maritimes', ['Saint-Martin-Vésubie - Alpes-Maritimes', 'Saint-Martin-Vésubie']];
        yield 'hyphenated name kept' => ['Chamonix-Mont-Blanc', ['Chamonix-Mont-Blanc']];
        yield 'too short' => ['X', []];
    }

    /** @param list<string> $expected */
    #[DataProvider('candidates')]
    public function testGeocodingCandidates(string $location, array $expected): void
    {
        $this->assertSame($expected, RaceWeatherService::geocodingCandidates($location));
    }

    // ── Prévision (J à J+15) ────────────────────────────────────────────

    public function testForecastMapping(): void
    {
        $service = $this->service([new MockResponse(self::forecastJson('2026-11-20'))]);
        $data = $service->forRace($this->race('2026-11-20', 'Annecy'), $this->route(45.9, 6.13));

        $q = $this->requests[0]['query'];
        $this->assertSame('/v1/forecast', $this->requests[0]['path']);
        $this->assertSame(['2026-11-20', '2026-11-20', 'auto'], [$q['start_date'], $q['end_date'], $q['timezone']]);
        $this->assertStringContainsString('precipitation_probability_max', $q['daily']);
        $this->assertStringContainsString('uv_index_max', $q['daily']);
        $this->assertSame('temperature_2m,apparent_temperature,precipitation_probability,precipitation,weather_code,wind_speed_10m,wind_gusts_10m', $q['hourly']);

        $this->assertSame('forecast', $data['state']);
        $this->assertSame('2026-11-20', $data['date']);
        $this->assertSame('Europe/Paris', $data['timezone']);
        $this->assertSame('2026-11-15T10:00:00+00:00', $data['fetchedAt']);
        $this->assertSame([
            'weatherCode'              => 80,
            'tempMax'                  => 12.3,
            'tempMin'                  => 4.1,
            'apparentMax'              => 10.0,
            'apparentMin'              => 1.6,
            'precipitation'            => 20.3,
            'precipitationProbability' => 100,
            'windSpeedMax'             => 18.4,
            'windGustsMax'             => 45.0,
            'windDirection'            => 195,
            'uvIndexMax'               => 0.4,
            'sunrise'                  => '07:42',
            'sunset'                   => '16:59',
            'rainRisk'                 => 'high',
            'rainyDay'                 => null,
        ], $data['forecast']['daily']);

        $slots = $data['forecast']['slots'];
        $this->assertSame(['06:00', '09:00', '12:00', '15:00', '18:00', '21:00'], array_column($slots, 'time'));
        // Créneau 06:00 = heures 6, 7 et 8 : valeur à 6 h pour la température, cumul / max sinon
        $this->assertSame([
            'time'                     => '06:00',
            'weatherCode'              => 61,
            'temperature'              => 8.0,
            'apparentTemperature'      => 6.0,
            'precipitation'            => 1.0,
            'precipitationProbability' => 60,
            'windSpeed'                => 14.5,
            'windGusts'                => 30.0,
        ], $slots[0]);
        $this->assertSame(15.5, $slots[5]['temperature']);
    }

    public function testForecastIsCachedOneHour(): void
    {
        $json = self::forecastJson('2026-11-20');
        $service = $this->service([new MockResponse($json), new MockResponse($json)]);
        $race = $this->race('2026-11-20', 'Annecy');
        $route = $this->route(45.9, 6.13);

        $first = $service->forRace($race, $route);
        $second = $service->forRace($race, $route);
        $this->assertCount(1, $this->requests, 'second call served from the cache');
        $this->assertSame($first, $second);

        $this->cacheClock->sleep(3500);
        $service->forRace($race, $route);
        $this->assertCount(1, $this->requests);

        $this->cacheClock->sleep(200);
        $service->forRace($race, $route);
        $this->assertCount(2, $this->requests, 'refreshed after one hour');
    }

    public function testRainRiskFallsBackOnPrecipitationWhenNoProbability(): void
    {
        $json = self::forecastJson('2026-11-30', ['precipitation_probability_max' => [null], 'precipitation_sum' => [2.4]]);
        $data = $this->service([new MockResponse($json)])->forRace($this->race('2026-11-30', 'Annecy'), $this->route(45.9, 6.13));

        $this->assertSame('forecast', $data['state']);
        $this->assertNull($data['forecast']['daily']['precipitationProbability']);
        $this->assertSame('moderate', $data['forecast']['daily']['rainRisk']);
    }

    public function testLastForecastDayOutOfRangeFallsBackToClimate(): void
    {
        $service = $this->service([
            new MockResponse('{"error":true,"reason":"Parameter \'start_date\' is out of allowed range from 2026-08-14 to 2026-11-29"}', ['http_code' => 400]),
            new MockResponse(json_encode(['timezone' => 'America/New_York', 'daily' => self::series('2016-11-20', '2026-11-03', static fn () => [10.0, 2.0, 0.0, 12.0, 3])])),
        ]);
        $data = $service->forRace($this->race('2026-11-30', 'Boston'), $this->route(42.36, -71.06));

        $this->assertSame('climate', $data['state']);
        $this->assertSame('/v1/archive', $this->requests[1]['path']);
        $this->assertSame('2026-11-15', $data['climate']['availableFrom']);
    }

    /** @return iterable<string, array{int|float|null, float|null, string|null}> */
    public static function rainRisks(): iterable
    {
        yield 'nothing' => [null, null, null];
        yield '0 %' => [0, null, 'low'];
        yield 'probability wins over amount' => [29, 10.0, 'low'];
        yield '30 %' => [30, null, 'moderate'];
        yield '59 %' => [59, null, 'moderate'];
        yield '60 %' => [60, null, 'high'];
        yield '0.5 mm' => [null, 0.5, 'low'];
        yield '1 mm' => [null, 1.0, 'moderate'];
        yield '5 mm' => [null, 5.0, 'high'];
    }

    #[DataProvider('rainRisks')]
    public function testRainRisk(int|float|null $probability, ?float $mm, ?string $expected): void
    {
        $this->assertSame($expected, RaceWeatherService::rainRisk($probability, $mm));
    }

    public function testMapDailyIgnoresMissingOrEmptyDay(): void
    {
        $this->assertNull(RaceWeatherService::mapDaily(['time' => ['2026-11-19']], '2026-11-20'));
        $this->assertNull(RaceWeatherService::mapDaily([
            'time'               => ['2026-11-20'],
            'weather_code'       => [null],
            'temperature_2m_max' => [null],
            'temperature_2m_min' => [null],
            'precipitation_sum'  => [null],
            'sunrise'            => ['2026-11-20T07:42'],
        ], '2026-11-20'));
        $this->assertNull(RaceWeatherService::mapDaily(['time' => 'oops'], '2026-11-20'));
    }

    public function testSlotsSkipMissingHours(): void
    {
        $hourly = [
            'time'           => ['2026-11-20T05:00', '2026-11-20T06:00', '2026-11-20T07:00', '2026-11-21T06:00'],
            'temperature_2m' => [1.0, 2.0, 3.0, 99.0],
            'weather_code'   => [0, 1, 2, 99],
            'precipitation'  => [null, null, null, 5.0],
        ];
        $slots = RaceWeatherService::buildSlots($hourly, '2026-11-20');

        $this->assertCount(1, $slots);
        $this->assertSame(['06:00', 2, 2.0, null, null], [$slots[0]['time'], $slots[0]['weatherCode'], $slots[0]['temperature'], $slots[0]['precipitation'], $slots[0]['windSpeed']]);
    }

    // ── Météo observée (course passée) ─────────────────────────────────

    public function testObservedRecentUsesTheForecastApi(): void
    {
        $service = $this->service([new MockResponse(self::forecastJson('2026-11-12', [], observed: true))]);
        $data = $service->forRace($this->race('2026-11-12', 'Annecy'), $this->route(45.9, 6.13));

        $this->assertSame('observed', $data['state']);
        $this->assertSame('api.open-meteo.com', $this->requests[0]['host']);
        $this->assertSame('2026-11-12', $this->requests[0]['query']['start_date']);
        $this->assertStringNotContainsString('precipitation_probability', $this->requests[0]['query']['daily'].$this->requests[0]['query']['hourly']);
        $this->assertNull($data['observed']['daily']['rainRisk']);
        $this->assertTrue($data['observed']['daily']['rainyDay']);
        $this->assertNull($data['observed']['slots'][0]['precipitationProbability']);
    }

    public function testObservedOldUsesTheArchiveAndIsCached(): void
    {
        $json = self::forecastJson('2026-10-16', ['precipitation_sum' => [0.4]], observed: true);
        $service = $this->service([new MockResponse($json)]);
        $race = $this->race('2026-10-16', 'Annecy');
        $route = $this->route(45.9, 6.13);

        $data = $service->forRace($race, $route);
        $this->assertSame('observed', $data['state']);
        $this->assertSame('archive-api.open-meteo.com', $this->requests[0]['host']);
        $this->assertSame('/v1/archive', $this->requests[0]['path']);
        $this->assertFalse($data['observed']['daily']['rainyDay']);
        $this->assertSame('07:42', $data['observed']['daily']['sunrise']);

        $this->cacheClock->sleep(29 * 86400);
        $service->forRace($race, $route);
        $this->assertCount(1, $this->requests, 'archive data kept 30 days');
    }

    public function testIncompleteArchiveFallsBackToTheForecastApi(): void
    {
        $empty = self::forecastJson('2026-11-05', [
            'weather_code' => [null], 'temperature_2m_max' => [null], 'temperature_2m_min' => [null], 'precipitation_sum' => [null],
        ], observed: true);
        $service = $this->service([
            new MockResponse($empty),
            new MockResponse(self::forecastJson('2026-11-05', [], observed: true)),
        ]);
        $data = $service->forRace($this->race('2026-11-05', 'Annecy'), $this->route(45.9, 6.13));

        $this->assertSame(['archive-api.open-meteo.com', 'api.open-meteo.com'], array_column($this->requests, 'host'));
        $this->assertSame('observed', $data['state']);
        $this->assertSame(12.3, $data['observed']['daily']['tempMax']);
    }

    // ── Tendance historique (au-delà de J+15) ──────────────────────────

    public function testClimateModeMakesOneArchiveRequestAndIsCached(): void
    {
        $service = $this->service([
            new MockResponse(self::geocodingJson('Chamonix-Mont-Blanc', 45.92375, 6.86933)),
            new MockResponse(json_encode([
                'timezone' => 'Europe/Paris',
                'daily'    => self::series('2017-05-31', '2026-06-06', static fn (\DateTimeImmutable $d) => [20.0, 8.0, 0.0, 15.0, 2]),
            ])),
        ]);
        $race = $this->race('2027-06-03', 'Chamonix');

        $data = $service->forRace($race, null);

        $this->assertCount(2, $this->requests);
        $q = $this->requests[1]['query'];
        $this->assertSame('archive-api.open-meteo.com', $this->requests[1]['host']);
        $this->assertSame(['2017-05-31', '2026-06-06'], [$q['start_date'], $q['end_date']]);
        $this->assertSame('temperature_2m_max,temperature_2m_min,precipitation_sum,wind_speed_10m_max,weather_code', $q['daily']);

        $this->assertSame('climate', $data['state']);
        $c = $data['climate'];
        $this->assertSame([10, 2017, 2026, 70], [$c['years'], $c['fromYear'], $c['toYear'], $c['days']]);
        $this->assertSame(['avg' => 20.0, 'min' => 20.0, 'max' => 20.0], $c['tempMax']);
        $this->assertSame(['2027-05-31', '2027-06-06', '2027-05-19', 3], [$c['windowFrom'], $c['windowTo'], $c['availableFrom'], $c['windowDays']]);
        $this->assertSame('low', $c['rainRisk']);

        // Autre course au même endroit (coordonnées arrondies à 2 décimales) et même jour : cache
        $service->forRace($this->race('2027-06-03', 'Chamonix'), null);
        $this->assertCount(2, $this->requests);
    }

    public function testClimateYearsKeepTheLastCompleteWindows(): void
    {
        $today = new \DateTimeImmutable('2026-11-15');

        $this->assertSame(range(2017, 2026), RaceWeatherService::climateYears(6, 3, $today));
        $this->assertSame(range(2016, 2025), RaceWeatherService::climateYears(12, 20, $today), 'the 2026 window is not over yet');
        $this->assertSame(range(2017, 2026), RaceWeatherService::climateYears(11, 6, $today), 'window ending 6 days ago is complete');
        $this->assertSame(range(2016, 2025), RaceWeatherService::climateYears(11, 7, $today));
        $this->assertSame(range(2017, 2026), RaceWeatherService::climateYears(2, 29, $today));
    }

    public function testClimateStatisticsWithLeapYearsAndFebruary29(): void
    {
        // Fenêtre attendue : 26/02 -> 03/03 les années bissextiles (29/02 ±3 j), 25/02 -> 03/03 sinon (28/02 ±3 j)
        $inside = static function (\DateTimeImmutable $d): bool {
            $y = (int) $d->format('Y');
            $ymd = $d->format('Y-m-d');

            return $ymd >= ($y % 4 === 0 ? "$y-02-26" : "$y-02-25") && $ymd <= "$y-03-03";
        };
        $rain = ['2016-03-01' => 1.0, '2017-03-01' => 0.9, '2018-03-01' => 2.0, '2020-02-29' => 3.0, '2022-02-27' => 5.0, '2022-02-28' => 3.0];

        $daily = self::series('2015-12-01', '2026-03-31', static function (\DateTimeImmutable $d) use ($inside, $rain): array {
            if (!$inside($d)) {
                return [99.0, 99.0, 50.0, 99.0, 95]; // hors fenêtre : ne doit jamais compter
            }
            $ymd = $d->format('Y-m-d');
            $year = (int) $d->format('Y');
            $mm = $rain[$ymd] ?? 0.0;
            $code = match (true) {
                $mm >= 1.0              => 61,
                $ymd === '2019-02-28'   => 71,
                default                 => 3,
            };

            return [
                $d->format('m-d') === '02-25' ? 30.0 : 20.0,
                (float) ($year - 2015),
                $mm,
                $year === 2025 ? 19.0 : 12.0,
                $code,
            ];
        });
        // Une valeur manquante ne fausse pas les moyennes
        $i = array_search('2024-03-02', $daily['time'], true);
        $daily['temperature_2m_max'][$i] = null;

        $stats = RaceWeatherService::climateStats($daily, 2, 29, range(2016, 2025));

        $this->assertNotNull($stats);
        $this->assertSame(10, $stats['years']);
        $this->assertSame(70, $stats['days'], '7 days per year, leap or not');
        // 69 maximales valides : 7 « 25 février » à 30 °C (années non bissextiles), 62 à 20 °C
        $this->assertSame(['avg' => 21.0, 'min' => 20.0, 'max' => 30.0], $stats['tempMax']);
        $this->assertSame(['avg' => 5.5, 'min' => 1.0, 'max' => 10.0], $stats['tempMin']);
        $this->assertSame(4, $stats['rainyYears'], '2016 (1 mm), 2018, 2020 (29/02), 2022 - not 2017 (0.9 mm)');
        $this->assertSame(5, $stats['rainyDays']);
        $this->assertSame(0.07, $stats['rainyShare']);
        $this->assertSame('low', $stats['rainRisk']);
        $this->assertSame(12.7, $stats['windMaxAvg']);
        $this->assertSame(3, $stats['dominantCode']);
        $this->assertSame(1, $stats['snowDays']);
        $this->assertSame([2016, 2025], [$stats['fromYear'], $stats['toYear']]);
    }

    public function testFebruary29RaceRequestsNonLeapYearsAroundFebruary28(): void
    {
        $service = $this->service([
            new MockResponse(json_encode(['timezone' => 'Europe/Paris', 'daily' => self::series('2017-02-25', '2026-03-03', static fn () => [5.0, -2.0, 1.5, 20.0, 71])])),
        ]);
        $data = $service->forRace($this->race('2028-02-29', 'Annecy'), $this->route(45.9, 6.13));

        $q = $this->requests[0]['query'];
        $this->assertSame(['2017-02-25', '2026-03-03'], [$q['start_date'], $q['end_date']]);
        $this->assertSame('climate', $data['state']);
        $c = $data['climate'];
        $this->assertSame([70, 10, 70, 1.0, 'high'], [$c['days'], $c['rainyYears'], $c['snowDays'], $c['rainyShare'], $c['rainRisk']]);
        $this->assertSame(['2028-02-26', '2028-03-03', '2028-02-14'], [$c['windowFrom'], $c['windowTo'], $c['availableFrom']]);
    }

    public function testClimateStatsWithoutDataIsNull(): void
    {
        $this->assertNull(RaceWeatherService::climateStats(['time' => ['2016-06-03'], 'temperature_2m_max' => [null]], 6, 3, [2016]));
        $this->assertNull(RaceWeatherService::climateStats([], 6, 3, [2016]));
    }

    // ── Échecs ─────────────────────────────────────────────────────────

    public function testHttpErrorIsUnavailableAndRetriedOnlyAfterFiveMinutesOrOnRetry(): void
    {
        $json = self::forecastJson('2026-11-20');
        $service = $this->service([
            new MockResponse('{"error":true}', ['http_code' => 502]),
            new MockResponse('{"error":true}', ['http_code' => 502]),
            new MockResponse($json),
        ]);
        $race = $this->race('2026-11-20', 'Annecy');
        $route = $this->route(45.9, 6.13);

        $data = $service->forRace($race, $route);
        $this->assertSame('unavailable', $data['state']);
        $this->assertSame('gpx', $data['place']['source'], 'the place is still reported');
        $this->assertSame('2026-11-20', $data['date']);

        // Échec mémorisé : pas de nouvel appel immédiat...
        $this->assertSame('unavailable', $service->forRace($race, $route)['state']);
        $this->assertCount(1, $this->requests);

        // ... sauf demande explicite (bouton « Réessayer »)
        $this->assertSame('unavailable', $service->forRace($race, $route, retry: true)['state']);
        $this->assertCount(2, $this->requests);

        // ... ou au bout de 5 minutes au plus
        $this->cacheClock->sleep(301);
        $this->assertSame('forecast', $service->forRace($race, $route)['state']);
        $this->assertCount(3, $this->requests);

        // Un succès en cache n'est pas rappelé, même avec retry
        $service->forRace($race, $route, retry: true);
        $this->assertCount(3, $this->requests);
    }

    public function testTimeoutIsUnavailable(): void
    {
        $data = $this->service([new MockResponse('', ['error' => 'Idle timeout reached for "https://api.open-meteo.com/v1/forecast".'])])
            ->forRace($this->race('2026-11-20', 'Annecy'), $this->route(45.9, 6.13));

        $this->assertSame('unavailable', $data['state']);
    }

    public function testInvalidJsonIsUnavailable(): void
    {
        $data = $this->service([new MockResponse('<html>maintenance</html>')])
            ->forRace($this->race('2026-11-20', 'Annecy'), $this->route(45.9, 6.13));

        $this->assertSame('unavailable', $data['state']);
    }

    public function testRequestsUseAFiveSecondTimeout(): void
    {
        $options = null;
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$options) {
            $options = $opts;

            return new MockResponse(self::forecastJson('2026-11-20'));
        });
        (new RaceWeatherService($client, $this->cache, new MockClock(self::TODAY, 'UTC')))
            ->forRace($this->race('2026-11-20', 'Annecy'), $this->route(45.9, 6.13));

        $this->assertNotNull($options);
        $this->assertEquals(5.0, $options['timeout']);
        $this->assertEquals(5.0, $options['max_duration']);
    }

    // ── Outils ─────────────────────────────────────────────────────────

    /** @param list<MockResponse> $responses */
    private function service(array $responses): RaceWeatherService
    {
        $client = new MockHttpClient(function (string $method, string $url) use (&$responses) {
            $parts = parse_url($url);
            parse_str($parts['query'] ?? '', $query);
            /** @var array<string, string> $query */
            $this->requests[] = ['host' => $parts['host'] ?? '', 'path' => $parts['path'] ?? '', 'query' => $query];

            return array_shift($responses) ?? new MockResponse('{"error":true}', ['http_code' => 500]);
        });

        return new RaceWeatherService($client, $this->cache, new MockClock(self::TODAY, 'UTC'));
    }

    private function race(?string $date, ?string $location): Race
    {
        $race = (new Race())->setName('Course test')->setYear(2026)->setLocation($location);
        if ($date !== null) {
            $race->setRaceDate(new \DateTimeImmutable($date));
        }

        return $race;
    }

    private function route(float $lat, float $lon): RaceRoute
    {
        return (new RaceRoute())->setPoints([[0, 447.1, $lat, $lon], [120, 449.0, $lat + 0.001, $lon + 0.001]]);
    }

    private static function geocodingJson(string $name, float $lat, float $lon): string
    {
        return (string) json_encode(['results' => [[
            'id' => 1, 'name' => $name, 'latitude' => $lat, 'longitude' => $lon, 'elevation' => 1060.0,
            'country_code' => 'FR', 'timezone' => 'Europe/Paris', 'country' => 'France',
            'admin1' => 'Auvergne-Rhône-Alpes', 'admin2' => 'Haute-Savoie',
        ]], 'generationtime_ms' => 0.2]);
    }

    /**
     * Réponse Open-Meteo d'un jour (forecast ou, avec $observed, variables de l'archive).
     *
     * @param array<string, list<mixed>> $daily valeurs quotidiennes remplacées
     */
    private static function forecastJson(string $date, array $daily = [], bool $observed = false): string
    {
        $time = [];
        for ($h = 0; $h < 24; ++$h) {
            $time[] = sprintf('%sT%02d:00', $date, $h);
        }
        $byHour = static fn (array $special, float|int $default): array => array_map(
            static fn (int $h) => $special[$h] ?? $default,
            range(0, 23),
        );
        $hourly = [
            'time'                      => $time,
            'temperature_2m'            => array_map(static fn (int $h) => 5.0 + $h * 0.5, range(0, 23)),
            'apparent_temperature'      => array_map(static fn (int $h) => 3.0 + $h * 0.5, range(0, 23)),
            'precipitation_probability' => $byHour([6 => 20, 7 => 60, 8 => 40], 10),
            'precipitation'             => $byHour([6 => 0.2, 7 => 0.3, 8 => 0.5], 0.0),
            'weather_code'              => $byHour([6 => 3, 7 => 61, 8 => 2], 1),
            'wind_speed_10m'            => $byHour([6 => 10.0, 7 => 14.5, 8 => 12.0], 8.0),
            'wind_gusts_10m'            => $byHour([6 => 20.0, 7 => 30.0, 8 => 25.0], 15.0),
        ];
        $days = [
            'time'                          => [$date],
            'weather_code'                  => [80],
            'temperature_2m_max'            => [12.34],
            'temperature_2m_min'            => [4.06],
            'apparent_temperature_max'      => [10.0],
            'apparent_temperature_min'      => [1.56],
            'precipitation_sum'             => [20.26],
            'precipitation_probability_max' => [100],
            'wind_speed_10m_max'            => [18.4],
            'wind_gusts_10m_max'            => [45],
            'wind_direction_10m_dominant'   => [195],
            'uv_index_max'                  => [0.36],
            'sunrise'                       => [$date.'T07:42'],
            'sunset'                        => [$date.'T16:59'],
        ];
        if ($observed) {
            unset($hourly['precipitation_probability'], $days['precipitation_probability_max'], $days['uv_index_max']);
        }

        return (string) json_encode([
            'latitude'           => 45.90578,
            'longitude'          => 6.1245956,
            'utc_offset_seconds' => 3600,
            'timezone'           => 'Europe/Paris',
            'daily'              => array_replace($days, $daily),
            'hourly'             => $hourly,
        ]);
    }

    /**
     * Série quotidienne d'archive : $row(jour) = [max, min, pluie, vent, code].
     *
     * @param callable(\DateTimeImmutable): array{0: float|null, 1: float|null, 2: float|null, 3: float|null, 4: int|null} $row
     *
     * @return array<string, list<mixed>>
     */
    private static function series(string $from, string $to, callable $row): array
    {
        $daily = ['time' => [], 'temperature_2m_max' => [], 'temperature_2m_min' => [], 'precipitation_sum' => [], 'wind_speed_10m_max' => [], 'weather_code' => []];
        $end = new \DateTimeImmutable($to);
        for ($d = new \DateTimeImmutable($from); $d <= $end; $d = $d->modify('+1 day')) {
            [$high, $low, $rain, $wind, $code] = $row($d);
            $daily['time'][] = $d->format('Y-m-d');
            $daily['temperature_2m_max'][] = $high;
            $daily['temperature_2m_min'][] = $low;
            $daily['precipitation_sum'][] = $rain;
            $daily['wind_speed_10m_max'][] = $wind;
            $daily['weather_code'][] = $code;
        }

        return $daily;
    }
}
