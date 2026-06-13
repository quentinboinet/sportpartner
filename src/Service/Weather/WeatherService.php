<?php

declare(strict_types=1);

namespace App\Service\Weather;

use App\Entity\Activity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WeatherService
{
    private const WMO_ICONS = [
        0 => 'bi-sun',        1 => 'bi-sun-fill',    2 => 'bi-cloud-sun',        3 => 'bi-clouds',
        45 => 'bi-cloud-fog2', 48 => 'bi-cloud-fog2',
        51 => 'bi-cloud-drizzle', 53 => 'bi-cloud-drizzle', 55 => 'bi-cloud-drizzle',
        61 => 'bi-cloud-rain', 63 => 'bi-cloud-rain', 65 => 'bi-cloud-rain-heavy',
        71 => 'bi-cloud-snow', 73 => 'bi-cloud-snow', 75 => 'bi-cloud-snow',
        80 => 'bi-cloud-rain', 81 => 'bi-cloud-rain', 82 => 'bi-cloud-rain-heavy',
        95 => 'bi-cloud-lightning-rain', 96 => 'bi-cloud-lightning-rain', 99 => 'bi-cloud-lightning-rain',
    ];

    private const WMO_LABELS = [
        'fr' => [
            0 => 'Ciel dégagé',      1 => 'Principalement dégagé', 2 => 'Partiellement nuageux', 3 => 'Couvert',
            45 => 'Brouillard',       48 => 'Brouillard givrant',
            51 => 'Bruine légère',    53 => 'Bruine modérée',    55 => 'Bruine dense',
            61 => 'Pluie légère',     63 => 'Pluie modérée',     65 => 'Pluie forte',
            71 => 'Neige légère',     73 => 'Neige modérée',     75 => 'Neige forte',
            80 => 'Averses légères',  81 => 'Averses modérées',  82 => 'Averses violentes',
            95 => 'Orage',            96 => 'Orage avec grêle',  99 => 'Orage fort avec grêle',
        ],
        'en' => [
            0 => 'Clear sky',     1 => 'Mainly clear',    2 => 'Partly cloudy',  3 => 'Overcast',
            45 => 'Foggy',         48 => 'Freezing fog',
            51 => 'Light drizzle', 53 => 'Moderate drizzle', 55 => 'Heavy drizzle',
            61 => 'Light rain',    63 => 'Moderate rain',    65 => 'Heavy rain',
            71 => 'Light snow',    73 => 'Moderate snow',    75 => 'Heavy snow',
            80 => 'Light showers', 81 => 'Moderate showers', 82 => 'Heavy showers',
            95 => 'Thunderstorm',  96 => 'Thunderstorm with hail', 99 => 'Heavy thunderstorm',
        ],
    ];

    public function __construct(
        private HttpClientInterface  $http,
        private EntityManagerInterface $em,
    ) {}

    /**
     * Fetch weather conditions for an activity at its start time and location,
     * store the result in the entity (lazy pattern — fetches only once).
     *
     * Returns an empty array if coordinates are missing or fetch fails.
     *
     * @return array{temperature:int,weather_code:int,precipitation:float,wind_speed:int}|array{}
     */
    public function fetchAndStore(Activity $activity, float $lat, float $lon): array
    {
        $date = $activity->getStartDate();
        if (!$date) {
            $activity->setWeatherData('{}');
            $this->em->flush();
            return [];
        }

        try {
            $dateStr   = $date->format('Y-m-d');
            $todayStr  = (new \DateTimeImmutable())->format('Y-m-d');
            $yestStr   = (new \DateTimeImmutable('-1 day'))->format('Y-m-d');

            if ($dateStr >= $todayStr) {
                $url = sprintf(
                    'https://api.open-meteo.com/v1/forecast?latitude=%s&longitude=%s'
                    . '&hourly=temperature_2m,precipitation,weather_code,wind_speed_10m'
                    . '&timezone=auto&forecast_days=1',
                    $lat, $lon
                );
            } elseif ($dateStr >= $yestStr) {
                $url = sprintf(
                    'https://api.open-meteo.com/v1/forecast?latitude=%s&longitude=%s'
                    . '&hourly=temperature_2m,precipitation,weather_code,wind_speed_10m'
                    . '&timezone=auto&past_days=1&forecast_days=1',
                    $lat, $lon
                );
            } else {
                $url = sprintf(
                    'https://archive.open-meteo.com/v1/archive?latitude=%s&longitude=%s'
                    . '&start_date=%s&end_date=%s'
                    . '&hourly=temperature_2m,precipitation,weather_code,wind_speed_10m'
                    . '&timezone=auto',
                    $lat, $lon, $dateStr, $dateStr
                );
            }

            $response = $this->http->request('GET', $url, ['timeout' => 6])->toArray();
            $hourly   = $response['hourly'] ?? null;

            if (!$hourly) {
                $activity->setWeatherData('{}');
                $this->em->flush();
                return [];
            }

            $targetHour = (int) $date->format('G');
            $idx = null;
            foreach ($hourly['time'] as $i => $t) {
                if ((int) (new \DateTimeImmutable($t))->format('G') === $targetHour
                    && str_starts_with($t, $dateStr)) {
                    $idx = $i;
                    break;
                }
            }

            if ($idx === null) {
                $activity->setWeatherData('{}');
                $this->em->flush();
                return [];
            }

            $data = [
                'temperature'   => (int) round($hourly['temperature_2m'][$idx]),
                'weather_code'  => (int) $hourly['weather_code'][$idx],
                'precipitation' => round((float) ($hourly['precipitation'][$idx] ?? 0), 1),
                'wind_speed'    => (int) round($hourly['wind_speed_10m'][$idx]),
            ];

            $activity->setWeatherData(json_encode($data));
            $this->em->flush();

            return $data;
        } catch (\Throwable) {
            $activity->setWeatherData('{}');
            $this->em->flush();
            return [];
        }
    }

    /**
     * Build display-ready array from stored raw weather data.
     *
     * @param array{temperature:int,weather_code:int,precipitation:float,wind_speed:int} $raw
     * @return array{icon:string,description:string,temperature:int,wind_speed:int,precipitation:float}|null
     */
    public function getDisplay(array $raw, string $locale): ?array
    {
        if (empty($raw)) {
            return null;
        }

        $code = $raw['weather_code'];

        return [
            'icon'          => self::WMO_ICONS[$code]                           ?? 'bi-cloud',
            'description'   => (self::WMO_LABELS[$locale] ?? self::WMO_LABELS['fr'])[$code] ?? '',
            'temperature'   => $raw['temperature'],
            'wind_speed'    => $raw['wind_speed'],
            'precipitation' => $raw['precipitation'],
        ];
    }
}
