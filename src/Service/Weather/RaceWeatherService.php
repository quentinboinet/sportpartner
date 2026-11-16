<?php

declare(strict_types=1);

namespace App\Service\Weather;

use App\Entity\Race;
use App\Entity\RaceRoute;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Météo du jour J d'une course (carte « Météo du jour J » de la page d'une course,
 * servie en JSON par app_race_weather). Données Open-Meteo (CC BY 4.0), sans clé d'API.
 *
 * Lieu : point de départ du GPX s'il existe ; sinon géocodage du lieu saisi (API de géocodage
 * Open-Meteo, cache 30 jours par nom normalisé) ; sinon état « no_location ».
 *
 * État selon la date de la course (jours comptés depuis aujourd'hui ; timezone=auto, les dates
 * et heures renvoyées sont locales au lieu) :
 *  - no_date     : pas de date précise ;
 *  - no_location : ni GPX ni lieu exploitable (reason : missing | not_found) ;
 *  - forecast    : de J à J+15, prévision du jour (API forecast, cache 1 h) ;
 *  - climate     : au-delà de J+15, tendance des 10 dernières années (archive, jour de la course
 *                  ±3 jours, une seule requête, cache 30 jours) - ce n'est pas une prévision ;
 *  - observed    : date passée, météo observée (archive, cache 30 jours ; les 5 derniers jours
 *                  passent par l'API forecast car l'archive peut encore être incomplète) ;
 *  - unavailable : échec ou délai dépassé (5 s) côté Open-Meteo. Un échec est gardé en cache
 *                  5 minutes au plus ; $retry force un nouvel essai. Aucune exception n'est propagée.
 */
final class RaceWeatherService
{
    public const STATE_FORECAST = 'forecast';
    public const STATE_CLIMATE = 'climate';
    public const STATE_OBSERVED = 'observed';
    public const STATE_NO_DATE = 'no_date';
    public const STATE_NO_LOCATION = 'no_location';
    public const STATE_UNAVAILABLE = 'unavailable';

    /** Prévision disponible jusqu'à J+15 (16 jours, aujourd'hui compris). */
    public const FORECAST_DAYS = 15;
    /** Jours passés pour lesquels l'archive peut être incomplète (API forecast à la place). */
    public const RECENT_DAYS = 5;
    /** L'API forecast remonte jusqu'à environ 3 mois en arrière. */
    public const FORECAST_PAST_DAYS = 92;
    public const CLIMATE_YEARS = 10;
    /** Fenêtre de la tendance : jour de la course ± CLIMATE_WINDOW_DAYS jours, chaque année. */
    public const CLIMATE_WINDOW_DAYS = 3;
    /** Seuil d'un « jour de pluie ». */
    public const RAINY_DAY_MM = 1.0;
    /** Début des créneaux de 3 h renvoyés (heure locale du lieu). */
    public const SLOT_HOURS = [6, 9, 12, 15, 18, 21];

    private const SLOT_SPAN_H = 3;
    private const SNOW_CODES = [71, 73, 75, 77, 85, 86];

    private const GEOCODING_URL = 'https://geocoding-api.open-meteo.com/v1/search';
    private const FORECAST_URL = 'https://api.open-meteo.com/v1/forecast';
    private const ARCHIVE_URL = 'https://archive-api.open-meteo.com/v1/archive';

    private const DAILY_FORECAST = 'weather_code,temperature_2m_max,temperature_2m_min,apparent_temperature_max,apparent_temperature_min,'
        .'precipitation_sum,precipitation_probability_max,wind_speed_10m_max,wind_gusts_10m_max,wind_direction_10m_dominant,'
        .'uv_index_max,sunrise,sunset';
    private const HOURLY_FORECAST = 'temperature_2m,apparent_temperature,precipitation_probability,precipitation,weather_code,'
        .'wind_speed_10m,wind_gusts_10m';
    /** Variables communes à l'archive et à l'API forecast (pas de probabilité ni d'UV dans l'archive). */
    private const DAILY_OBSERVED = 'weather_code,temperature_2m_max,temperature_2m_min,apparent_temperature_max,apparent_temperature_min,'
        .'precipitation_sum,wind_speed_10m_max,wind_gusts_10m_max,wind_direction_10m_dominant,sunrise,sunset';
    private const HOURLY_OBSERVED = 'temperature_2m,apparent_temperature,precipitation,weather_code,wind_speed_10m,wind_gusts_10m';
    private const DAILY_CLIMATE = 'temperature_2m_max,temperature_2m_min,precipitation_sum,wind_speed_10m_max,weather_code';

    private const TIMEOUT_S = 5.0;
    private const TTL_FORECAST = 3600;
    private const TTL_RECENT = 6 * 3600;
    private const TTL_LONG = 30 * 86400;
    private const TTL_NOT_FOUND = 86400;
    private const TTL_FAILURE = 300;
    /** Code d'échec interne : réponse valide mais sans données pour le jour demandé. */
    private const NO_DATA = 204;
    private const CACHE_PREFIX = 'race_weather.v1.';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Contrat JSON de app_race_weather :
     * {state, place: {name, region, lat, lon, source: gpx|location}|null, date, timezone, fetchedAt,
     *  [reason (no_location)], [forecast|observed: {daily, slots} | climate: {...}]}.
     *
     * @return array<string, mixed>
     */
    public function forRace(Race $race, ?RaceRoute $route, bool $retry = false): array
    {
        $date = $race->getRaceDate();
        $result = [
            'state'     => self::STATE_NO_DATE,
            'place'     => null,
            'date'      => $date?->format('Y-m-d'),
            'timezone'  => null,
            'fetchedAt' => null,
        ];
        if ($date === null) {
            return $result;
        }

        $location = self::normalizeSpaces((string) $race->getLocation());
        $place = $route !== null ? self::routeStart($route, $location !== '' ? $location : null) : null;
        if ($place === null) {
            if ($location === '') {
                return ['state' => self::STATE_NO_LOCATION, 'reason' => 'missing'] + $result;
            }
            $geo = $this->geocode($location, $retry);
            if (!$geo['ok']) {
                return ['state' => self::STATE_UNAVAILABLE] + $result;
            }
            $found = $geo['data'];
            if (!is_array($found) || !isset($found['lat'], $found['lon'])) {
                return ['state' => self::STATE_NO_LOCATION, 'reason' => 'not_found'] + $result;
            }
            $place = [
                'name'   => is_string($found['name'] ?? null) ? $found['name'] : $location,
                'region' => is_string($found['region'] ?? null) ? $found['region'] : null,
                'lat'    => (float) $found['lat'],
                'lon'    => (float) $found['lon'],
                'source' => 'location',
            ];
        }
        $result['place'] = $place;

        $raceDay = self::day($date);
        $today = self::day($this->clock->now());
        $days = (int) $today->diff($raceDay)->format('%r%a');

        if ($days > self::FORECAST_DAYS) {
            return $this->climate($result, $place, $raceDay, $today, $retry);
        }
        if ($days >= 0) {
            $res = $this->dayWeather('forecast', $place, $raceDay, $retry);
            // Dernier jour de prévision : selon le fuseau du lieu, il n'est pas forcément encore ouvert
            if (!$res['ok'] && $res['status'] === 400 && $days >= self::FORECAST_DAYS - 1) {
                return $this->climate($result, $place, $raceDay, $today, $retry);
            }

            return self::withDay($result, self::STATE_FORECAST, $res);
        }
        if ($days >= -self::RECENT_DAYS) {
            return self::withDay($result, self::STATE_OBSERVED, $this->dayWeather('recent', $place, $raceDay, $retry));
        }
        $res = $this->dayWeather('archive', $place, $raceDay, $retry);
        if (!$res['ok'] && $res['status'] === self::NO_DATA && $days >= -self::FORECAST_PAST_DAYS) {
            $res = $this->dayWeather('recent', $place, $raceDay, $retry);
        }

        return self::withDay($result, self::STATE_OBSERVED, $res);
    }

    /**
     * Point de départ du parcours GPX (points [distanceM, ele, lat, lon]).
     *
     * @return array{name: string|null, region: null, lat: float, lon: float, source: 'gpx'}|null
     */
    public static function routeStart(RaceRoute $route, ?string $name): ?array
    {
        $first = $route->getPoints()[0] ?? null;
        if ($first === null) {
            return null;
        }
        $lat = (float) $first[2];
        $lon = (float) $first[3];
        if (abs($lat) > 90 || abs($lon) > 180) {
            return null;
        }

        return ['name' => $name, 'region' => null, 'lat' => round($lat, 5), 'lon' => round($lon, 5), 'source' => 'gpx'];
    }

    /**
     * Noms essayés pour le géocodage : le lieu saisi, puis sa première partie sans précisions
     * (« Annecy (74) », « Chamonix, France », « 74000 Annecy » donnent « Annecy », « Chamonix »).
     *
     * @return list<string>
     */
    public static function geocodingCandidates(string $location): array
    {
        $full = self::normalizeSpaces($location);
        $candidates = mb_strlen($full) >= 2 ? [$full] : [];

        $parts = preg_split('/\s*(?:[,;(\/]|\s-\s)\s*/u', $full);
        $first = self::normalizeSpaces((string) preg_replace('/\b\d+\b/u', ' ', is_array($parts) ? $parts[0] : ''));
        if (mb_strlen($first) >= 2 && !in_array($first, $candidates, true)) {
            $candidates[] = $first;
        }

        return $candidates;
    }

    /**
     * Premier résultat exploitable de l'API de géocodage.
     *
     * @param array<mixed> $json
     *
     * @return array{name: string, region: string|null, lat: float, lon: float}|null
     */
    public static function firstGeocodingResult(array $json): ?array
    {
        $results = $json['results'] ?? null;
        $first = is_array($results) ? ($results[0] ?? null) : null;
        if (!is_array($first)) {
            return null;
        }
        $lat = self::float($first['latitude'] ?? null);
        $lon = self::float($first['longitude'] ?? null);
        $name = is_string($first['name'] ?? null) ? self::normalizeSpaces($first['name']) : '';
        if ($lat === null || $lon === null || abs($lat) > 90 || abs($lon) > 180 || $name === '') {
            return null;
        }

        $region = [];
        foreach ([$first['admin1'] ?? null, $first['country'] ?? null] as $part) {
            if (is_string($part) && trim($part) !== '' && trim($part) !== $name) {
                $region[] = trim($part);
            }
        }

        return ['name' => $name, 'region' => $region !== [] ? implode(', ', $region) : null, 'lat' => round($lat, 5), 'lon' => round($lon, 5)];
    }

    /**
     * Valeurs quotidiennes d'un jour (bloc « daily » d'Open-Meteo).
     *
     * @param array<mixed> $daily
     *
     * @return array{weatherCode: int|null, tempMax: float|null, tempMin: float|null, apparentMax: float|null, apparentMin: float|null, precipitation: float|null, precipitationProbability: int|null, windSpeedMax: float|null, windGustsMax: float|null, windDirection: int|null, uvIndexMax: float|null, sunrise: string|null, sunset: string|null}|null
     *         null si le jour est absent de la réponse ou sans aucune valeur météo
     */
    public static function mapDaily(array $daily, string $date): ?array
    {
        $times = $daily['time'] ?? null;
        $i = is_array($times) ? array_search($date, $times, true) : false;
        if (!is_int($i)) {
            return null;
        }
        $num = static fn (string $key): ?float => self::round1(self::float(self::at($daily, $key, $i)));

        $row = [
            'weatherCode'              => self::int(self::at($daily, 'weather_code', $i)),
            'tempMax'                  => $num('temperature_2m_max'),
            'tempMin'                  => $num('temperature_2m_min'),
            'apparentMax'              => $num('apparent_temperature_max'),
            'apparentMin'              => $num('apparent_temperature_min'),
            'precipitation'            => $num('precipitation_sum'),
            'precipitationProbability' => self::int(self::at($daily, 'precipitation_probability_max', $i)),
            'windSpeedMax'             => $num('wind_speed_10m_max'),
            'windGustsMax'             => $num('wind_gusts_10m_max'),
            'windDirection'            => self::int(self::at($daily, 'wind_direction_10m_dominant', $i)),
            'uvIndexMax'               => $num('uv_index_max'),
            'sunrise'                  => self::localTime(self::at($daily, 'sunrise', $i)),
            'sunset'                   => self::localTime(self::at($daily, 'sunset', $i)),
        ];
        // Jour présent mais vide (archive pas encore à jour) : lever/coucher seuls ne suffisent pas
        if ($row['weatherCode'] === null && $row['tempMax'] === null && $row['tempMin'] === null && $row['precipitation'] === null) {
            return null;
        }

        return $row;
    }

    /**
     * Créneaux de 3 h (début à 06:00, 09:00 ... 21:00, heure locale) depuis le bloc « hourly » :
     * température et ressenti au début du créneau, pluie cumulée, probabilité, vent et rafales max,
     * code météo le plus élevé (le plus marquant, comme le code quotidien d'Open-Meteo).
     *
     * @param array<mixed> $hourly
     *
     * @return list<array{time: string, weatherCode: int|null, temperature: float|null, apparentTemperature: float|null, precipitation: float|null, precipitationProbability: int|null, windSpeed: float|null, windGusts: float|null}>
     */
    public static function buildSlots(array $hourly, string $date): array
    {
        $times = $hourly['time'] ?? null;
        if (!is_array($times)) {
            return [];
        }
        $byHour = [];
        foreach ($times as $i => $t) {
            if (is_int($i) && is_string($t) && strlen($t) >= 13 && str_starts_with($t, $date.'T')) {
                $byHour[(int) substr($t, 11, 2)][] = $i;
            }
        }

        $slots = [];
        foreach (self::SLOT_HOURS as $start) {
            $idx = [];
            for ($h = $start; $h < $start + self::SLOT_SPAN_H; ++$h) {
                foreach ($byHour[$h] ?? [] as $i) {
                    $idx[] = $i;
                }
            }
            if ($idx === []) {
                continue;
            }
            $first = $byHour[$start][0] ?? $idx[0];
            $slot = [
                'time'                     => sprintf('%02d:00', $start),
                'weatherCode'              => self::int(self::maxAt($hourly, 'weather_code', $idx)),
                'temperature'              => self::round1(self::float(self::at($hourly, 'temperature_2m', $first))),
                'apparentTemperature'      => self::round1(self::float(self::at($hourly, 'apparent_temperature', $first))),
                'precipitation'            => self::round1(self::sumAt($hourly, 'precipitation', $idx)),
                'precipitationProbability' => self::int(self::maxAt($hourly, 'precipitation_probability', $idx)),
                'windSpeed'                => self::round1(self::maxAt($hourly, 'wind_speed_10m', $idx)),
                'windGusts'                => self::round1(self::maxAt($hourly, 'wind_gusts_10m', $idx)),
            ];
            if ($slot['weatherCode'] === null && $slot['temperature'] === null && $slot['precipitation'] === null) {
                continue;
            }
            $slots[] = $slot;
        }

        return $slots;
    }

    /**
     * Niveau de risque de pluie d'après la probabilité (%) ou, à défaut, le cumul prévu (mm).
     * L'interface l'affiche en texte et icône, jamais par la couleur seule.
     *
     * @return 'low'|'moderate'|'high'|null
     */
    public static function rainRisk(int|float|null $probability, ?float $precipitation): ?string
    {
        if ($probability !== null) {
            return $probability >= 60 ? 'high' : ($probability >= 30 ? 'moderate' : 'low');
        }
        if ($precipitation !== null) {
            return $precipitation >= 5 ? 'high' : ($precipitation >= self::RAINY_DAY_MM ? 'moderate' : 'low');
        }

        return null;
    }

    /**
     * Années retenues pour la tendance : les CLIMATE_YEARS dernières années dont la fenêtre
     * (jour de la course ±3 jours) est entièrement passée et consolidée dans l'archive.
     * Ex. au 15/11/2026 : course un 3 juin, 2017 à 2026 ; course un 20 décembre, 2016 à 2025.
     *
     * @return list<int> par ordre croissant
     */
    public static function climateYears(int $month, int $day, \DateTimeInterface $today): array
    {
        $lastComplete = self::day($today)->sub(new \DateInterval('P'.(self::RECENT_DAYS + 1).'D'));
        $window = new \DateInterval('P'.self::CLIMATE_WINDOW_DAYS.'D');
        $year = (int) $today->format('Y');
        while (self::anchor($year, $month, $day)->add($window) > $lastComplete) {
            --$year;
        }

        $years = [];
        for ($y = $year - self::CLIMATE_YEARS + 1; $y <= $year; ++$y) {
            $years[] = $y;
        }

        return $years;
    }

    /** Jour de la course pour une année donnée : le 29 février devient le 28 les années non bissextiles. */
    public static function anchor(int $year, int $month, int $day): \DateTimeImmutable
    {
        if ($month === 2 && $day === 29 && !checkdate(2, 29, $year)) {
            $day = 28;
        }

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day), new \DateTimeZone('UTC'));
    }

    /**
     * Statistiques de la fenêtre « jour de la course ±$window jours » sur les années données :
     * moyenne et extrêmes des maximales et des minimales, années ayant connu au moins un jour de
     * pluie (cumul quotidien >= 1 mm), part des jours de pluie, vent max moyen, temps le plus fréquent.
     *
     * @param array<mixed> $daily bloc « daily » de l'archive (time, temperature_2m_max, temperature_2m_min, precipitation_sum, wind_speed_10m_max, weather_code)
     * @param list<int>    $years
     *
     * @return array{years: int, fromYear: int, toYear: int, days: int, tempMax: array{avg: float, min: float, max: float}|null, tempMin: array{avg: float, min: float, max: float}|null, rainyYears: int, rainyDays: int, rainyShare: float|null, windMaxAvg: float|null, dominantCode: int|null, snowDays: int, rainRisk: string|null}|null
     *         null si aucune donnée dans les fenêtres
     */
    public static function climateStats(array $daily, int $month, int $day, array $years, int $window = self::CLIMATE_WINDOW_DAYS): ?array
    {
        $times = $daily['time'] ?? null;
        if ($years === [] || !is_array($times)) {
            return null;
        }

        // Date (Y-m-d) => année de la fenêtre à laquelle elle appartient
        $yearOf = [];
        foreach ($years as $year) {
            $anchor = self::anchor($year, $month, $day);
            for ($offset = -$window; $offset <= $window; ++$offset) {
                $interval = new \DateInterval('P'.abs($offset).'D');
                $yearOf[($offset < 0 ? $anchor->sub($interval) : $anchor->add($interval))->format('Y-m-d')] = $year;
            }
        }

        $highs = $lows = $winds = $codes = $withData = $rainy = [];
        $days = $precipDays = $rainyDays = $snowDays = 0;
        foreach ($times as $i => $t) {
            if (!is_int($i) || !is_string($t) || !isset($yearOf[$t])) {
                continue;
            }
            $year = $yearOf[$t];
            $high = self::float(self::at($daily, 'temperature_2m_max', $i));
            $low = self::float(self::at($daily, 'temperature_2m_min', $i));
            $rain = self::float(self::at($daily, 'precipitation_sum', $i));
            $wind = self::float(self::at($daily, 'wind_speed_10m_max', $i));
            $code = self::int(self::at($daily, 'weather_code', $i));
            if ($high === null && $low === null && $rain === null && $wind === null && $code === null) {
                continue;
            }
            ++$days;
            $withData[$year] = true;
            if ($high !== null) {
                $highs[] = $high;
            }
            if ($low !== null) {
                $lows[] = $low;
            }
            if ($wind !== null) {
                $winds[] = $wind;
            }
            if ($rain !== null) {
                ++$precipDays;
                if ($rain >= self::RAINY_DAY_MM) {
                    ++$rainyDays;
                    $rainy[$year] = true;
                }
            }
            if ($code !== null) {
                $codes[$code] = ($codes[$code] ?? 0) + 1;
                if (in_array($code, self::SNOW_CODES, true)) {
                    ++$snowDays;
                }
            }
        }
        if ($days === 0) {
            return null;
        }

        // Temps le plus fréquent ; à égalité, le code le plus marquant (le plus élevé)
        $dominant = null;
        $best = 0;
        foreach ($codes as $code => $count) {
            if ($count > $best || ($count === $best && $code > $dominant)) {
                $dominant = $code;
                $best = $count;
            }
        }
        $share = $precipDays > 0 ? round($rainyDays / $precipDays, 2) : null;

        return [
            'years'        => count($withData),
            'fromYear'     => min($years),
            'toYear'       => max($years),
            'days'         => $days,
            'tempMax'      => self::summary($highs),
            'tempMin'      => self::summary($lows),
            'rainyYears'   => count($rainy),
            'rainyDays'    => $rainyDays,
            'rainyShare'   => $share,
            'windMaxAvg'   => $winds !== [] ? round(array_sum($winds) / count($winds), 1) : null,
            'dominantCode' => $dominant,
            'snowDays'     => $snowDays,
            'rainRisk'     => $share !== null ? self::rainRisk($share * 100, null) : null,
        ];
    }

    /**
     * Tendance historique : une seule requête d'archive couvrant toutes les fenêtres.
     *
     * @param array<string, mixed>          $result
     * @param array{lat: float, lon: float} $place
     *
     * @return array<string, mixed>
     */
    private function climate(array $result, array $place, \DateTimeImmutable $raceDay, \DateTimeImmutable $today, bool $retry): array
    {
        $month = (int) $raceDay->format('n');
        $day = (int) $raceDay->format('j');
        $years = self::climateYears($month, $day, $today);
        if ($years === []) {
            return ['state' => self::STATE_UNAVAILABLE] + $result;
        }
        $window = new \DateInterval('P'.self::CLIMATE_WINDOW_DAYS.'D');
        $lastYear = max($years);
        $from = self::anchor(min($years), $month, $day)->sub($window)->format('Y-m-d');
        $to = self::anchor($lastYear, $month, $day)->add($window)->format('Y-m-d');
        $key = sprintf('%sclimate.%.2F.%.2F.%02d-%02d.%d', self::CACHE_PREFIX, $place['lat'], $place['lon'], $month, $day, $lastYear);

        $res = $this->remember($key, self::TTL_LONG, function () use ($place, $from, $to, $month, $day, $years): array {
            $json = $this->getJson(self::ARCHIVE_URL, [
                'latitude'   => self::coord($place['lat']),
                'longitude'  => self::coord($place['lon']),
                'start_date' => $from,
                'end_date'   => $to,
                'daily'      => self::DAILY_CLIMATE,
                'timezone'   => 'auto',
            ]);
            $stats = self::climateStats(is_array($json['daily'] ?? null) ? $json['daily'] : [], $month, $day, $years);
            if ($stats === null) {
                throw new WeatherUnavailableException(sprintf('No climate data from %s to %s', $from, $to), self::NO_DATA);
            }

            return ['timezone' => is_string($json['timezone'] ?? null) ? $json['timezone'] : null, 'stats' => $stats];
        }, $retry);

        $data = $res['data'];
        if (!$res['ok'] || !is_array($data) || !is_array($data['stats'] ?? null)) {
            return ['state' => self::STATE_UNAVAILABLE] + $result;
        }

        $result['state'] = self::STATE_CLIMATE;
        $result['timezone'] = $data['timezone'] ?? null;
        $result['fetchedAt'] = $res['fetchedAt'];
        $result['climate'] = $data['stats'] + [
            'windowDays'    => self::CLIMATE_WINDOW_DAYS,
            'windowFrom'    => $raceDay->sub($window)->format('Y-m-d'),
            'windowTo'      => $raceDay->add($window)->format('Y-m-d'),
            // Premier jour où la course entrera dans la fenêtre de prévision (J-15)
            'availableFrom' => $raceDay->sub(new \DateInterval('P'.self::FORECAST_DAYS.'D'))->format('Y-m-d'),
        ];

        return $result;
    }

    /**
     * Météo d'un jour pour un lieu : valeurs quotidiennes + créneaux de 3 h.
     *  - forecast : prévision (API forecast, cache 1 h) ;
     *  - recent   : jour passé récent (API forecast, cache 6 h) ;
     *  - archive  : jour passé (archive, cache 30 jours).
     *
     * @param 'forecast'|'recent'|'archive' $kind
     * @param array{lat: float, lon: float}  $place
     *
     * @return array{ok: bool, status: int, fetchedAt: string|null, data: mixed}
     */
    private function dayWeather(string $kind, array $place, \DateTimeImmutable $day, bool $retry): array
    {
        $date = $day->format('Y-m-d');
        $forecast = $kind === 'forecast';
        $key = sprintf('%sday.%s.%.3F.%.3F.%s', self::CACHE_PREFIX, $kind, $place['lat'], $place['lon'], $date);
        $ttl = match ($kind) {
            'forecast' => self::TTL_FORECAST,
            'recent'   => self::TTL_RECENT,
            'archive'  => self::TTL_LONG,
        };

        return $this->remember($key, $ttl, function () use ($kind, $forecast, $place, $date): array {
            $json = $this->getJson($kind === 'archive' ? self::ARCHIVE_URL : self::FORECAST_URL, [
                'latitude'   => self::coord($place['lat']),
                'longitude'  => self::coord($place['lon']),
                'start_date' => $date,
                'end_date'   => $date,
                'daily'      => $forecast ? self::DAILY_FORECAST : self::DAILY_OBSERVED,
                'hourly'     => $forecast ? self::HOURLY_FORECAST : self::HOURLY_OBSERVED,
                'timezone'   => 'auto',
            ]);
            $daily = self::mapDaily(is_array($json['daily'] ?? null) ? $json['daily'] : [], $date);
            if ($daily === null) {
                throw new WeatherUnavailableException(sprintf('No %s data for %s', $kind, $date), self::NO_DATA);
            }
            // Prévision : niveau de risque ; observé : jour de pluie ou non (>= 1 mm)
            $daily['rainRisk'] = $forecast ? self::rainRisk($daily['precipitationProbability'], $daily['precipitation']) : null;
            $daily['rainyDay'] = (!$forecast && $daily['precipitation'] !== null) ? $daily['precipitation'] >= self::RAINY_DAY_MM : null;

            return [
                'timezone' => is_string($json['timezone'] ?? null) ? $json['timezone'] : null,
                'daily'    => $daily,
                'slots'    => self::buildSlots(is_array($json['hourly'] ?? null) ? $json['hourly'] : [], $date),
            ];
        }, $retry);
    }

    /**
     * @return array{ok: bool, status: int, fetchedAt: string|null, data: mixed} data : array{name, region, lat, lon} ou null (lieu introuvable)
     */
    private function geocode(string $location, bool $retry): array
    {
        $key = self::CACHE_PREFIX.'geo.'.sha1(mb_strtolower($location));

        return $this->remember($key, self::TTL_LONG, function (ItemInterface $item) use ($location): ?array {
            foreach (self::geocodingCandidates($location) as $name) {
                $hit = self::firstGeocodingResult($this->getJson(self::GEOCODING_URL, [
                    'name'     => $name,
                    'count'    => 1,
                    'language' => 'fr',
                    'format'   => 'json',
                ]));
                if ($hit !== null) {
                    return $hit;
                }
            }
            // Introuvable : réessayé le lendemain (le lieu saisi a pu être corrigé entre-temps, nouvelle clé)
            $item->expiresAfter(self::TTL_NOT_FOUND);

            return null;
        }, $retry);
    }

    /**
     * Lecture du cache, calcul sinon. Un échec est mémorisé TTL_FAILURE secondes au plus (pas
     * d'appels répétés vers une API en panne) ; $retry force un nouvel essai si l'entrée est un échec.
     *
     * @param callable(ItemInterface): mixed $compute lève WeatherUnavailableException en cas d'échec
     *
     * @return array{ok: bool, status: int, fetchedAt: string|null, data: mixed}
     */
    private function remember(string $key, int $ttl, callable $compute, bool $retry): array
    {
        $callback = function (ItemInterface $item) use ($ttl, $compute): array {
            $item->expiresAfter($ttl);
            try {
                $data = $compute($item);
            } catch (WeatherUnavailableException $e) {
                $this->logger->warning('Race weather: Open-Meteo call failed ({key}): {message}', ['key' => $item->getKey(), 'message' => $e->getMessage()]);
                $item->expiresAfter(self::TTL_FAILURE);

                return ['ok' => false, 'status' => (int) $e->getCode(), 'fetchedAt' => null, 'data' => null];
            }

            return ['ok' => true, 'status' => 200, 'fetchedAt' => $this->clock->now()->format(\DATE_ATOM), 'data' => $data];
        };

        // Clés versionnées (CACHE_PREFIX) : une entrée lue a toujours la forme produite par $callback
        $value = $this->cache->get($key, $callback);
        if ($retry && !$value['ok']) {
            $value = $this->cache->get($key, $callback, \INF);
        }

        return $value;
    }

    /**
     * @param array<string, string|int> $query
     *
     * @return array<mixed>
     *
     * @throws WeatherUnavailableException
     */
    private function getJson(string $url, array $query): array
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'query'        => $query,
                'timeout'      => self::TIMEOUT_S,
                'max_duration' => self::TIMEOUT_S,
                'headers'      => ['Accept' => 'application/json'],
            ]);
            $status = $response->getStatusCode();
            if ($status !== 200) {
                // Ex. 400 « Parameter 'start_date' is out of allowed range » : utile dans les logs
                $reason = mb_substr(trim(strip_tags($response->getContent(false))), 0, 200);
                throw new WeatherUnavailableException(sprintf('HTTP %d from %s: %s', $status, $url, $reason), $status);
            }

            return $response->toArray();
        } catch (HttpClientExceptionInterface $e) {
            throw new WeatherUnavailableException(sprintf('%s (%s)', $e->getMessage(), $url), 0, $e);
        }
    }

    /**
     * @param array<string, mixed>                                              $result
     * @param array{ok: bool, status: int, fetchedAt: string|null, data: mixed} $res
     *
     * @return array<string, mixed>
     */
    private static function withDay(array $result, string $state, array $res): array
    {
        $data = $res['data'];
        if (!$res['ok'] || !is_array($data) || !is_array($data['daily'] ?? null)) {
            return ['state' => self::STATE_UNAVAILABLE] + $result;
        }

        $result['state'] = $state;
        $result['timezone'] = $data['timezone'] ?? null;
        $result['fetchedAt'] = $res['fetchedAt'];
        $result[$state] = ['daily' => $data['daily'], 'slots' => is_array($data['slots'] ?? null) ? $data['slots'] : []];

        return $result;
    }

    /**
     * @param list<float> $values
     *
     * @return array{avg: float, min: float, max: float}|null
     */
    private static function summary(array $values): ?array
    {
        if ($values === []) {
            return null;
        }

        return [
            'avg' => round(array_sum($values) / count($values), 1),
            'min' => round(min($values), 1),
            'max' => round(max($values), 1),
        ];
    }

    /** Date seule, à minuit UTC (comparaisons en jours sans effet d'heure d'été). */
    private static function day(\DateTimeInterface $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date->format('Y-m-d'), new \DateTimeZone('UTC'));
    }

    /** Coordonnée envoyée à l'API (~100 m, indépendant de la locale). */
    private static function coord(float $value): string
    {
        return sprintf('%.3F', $value);
    }

    private static function normalizeSpaces(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /** @param array<mixed> $block */
    private static function at(array $block, string $key, int $i): mixed
    {
        $values = $block[$key] ?? null;

        return is_array($values) ? ($values[$i] ?? null) : null;
    }

    /**
     * @param array<mixed> $block
     * @param list<int>    $idx
     */
    private static function maxAt(array $block, string $key, array $idx): ?float
    {
        $max = null;
        foreach ($idx as $i) {
            $v = self::float(self::at($block, $key, $i));
            if ($v !== null && ($max === null || $v > $max)) {
                $max = $v;
            }
        }

        return $max;
    }

    /**
     * @param array<mixed> $block
     * @param list<int>    $idx
     */
    private static function sumAt(array $block, string $key, array $idx): ?float
    {
        $sum = null;
        foreach ($idx as $i) {
            $v = self::float(self::at($block, $key, $i));
            if ($v !== null) {
                $sum = ($sum ?? 0.0) + $v;
            }
        }

        return $sum;
    }

    private static function float(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    private static function int(mixed $value): ?int
    {
        return is_int($value) || is_float($value) ? (int) round((float) $value) : null;
    }

    private static function round1(?float $value): ?float
    {
        return $value === null ? null : round($value, 1);
    }

    /** « 2026-11-20T07:42 » => « 07:42 » (heure locale du lieu, telle que renvoyée). */
    private static function localTime(mixed $value): ?string
    {
        return is_string($value) && preg_match('/T(\d{2}:\d{2})/', $value, $m) === 1 ? $m[1] : null;
    }
}
