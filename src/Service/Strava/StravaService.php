<?php

namespace App\Service\Strava;

use App\Entity\Activity;
use App\Entity\User;
use App\Repository\ActivityRepository;
use App\Repository\SportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class StravaService
{
    private const TOKEN_URL     = 'https://www.strava.com/oauth/token';
    private const AUTHORIZE_URL = 'https://www.strava.com/oauth/authorize';
    private const API_BASE      = 'https://www.strava.com/api/v3';
    private const PAGE_SIZE     = 200;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $em,
        private readonly ActivityRepository $activityRepo,
        private readonly SportRepository $sportRepo,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
    ) {}

    public function getAuthorizationUrl(string $state = ''): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'response_type' => 'code',
            'scope'         => 'read,activity:read_all',
            'state'         => $state,
        ]);
    }

    public function exchangeCodeForTokens(string $code): array
    {
        $response = $this->httpClient->request('POST', self::TOKEN_URL, [
            'json' => [
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'code'          => $code,
                'grant_type'    => 'authorization_code',
            ],
        ]);

        return $response->toArray();
    }

    public function refreshAccessToken(User $user): void
    {
        if (!$user->getStravaRefreshToken()) return;

        $response = $this->httpClient->request('POST', self::TOKEN_URL, [
            'json' => [
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'refresh_token' => $user->getStravaRefreshToken(),
                'grant_type'    => 'refresh_token',
            ],
        ]);

        $data = $response->toArray();
        $user->setStravaAccessToken($data['access_token']);
        $user->setStravaRefreshToken($data['refresh_token']);
        $user->setStravaTokenExpiresAt(new \DateTimeImmutable('@'.$data['expires_at']));

        $this->em->flush();
    }

    public function isTokenExpired(User $user): bool
    {
        if (!$user->getStravaTokenExpiresAt()) return true;
        return $user->getStravaTokenExpiresAt() < new \DateTimeImmutable('+5 minutes');
    }

    public function connectUser(User $user, array $tokenData): void
    {
        $user->setStravaAthleteId($tokenData['athlete']['id'] ?? null);
        $user->setStravaAccessToken($tokenData['access_token']);
        $user->setStravaRefreshToken($tokenData['refresh_token']);
        $user->setStravaTokenExpiresAt(new \DateTimeImmutable('@'.$tokenData['expires_at']));

        $this->em->flush();
    }

    /**
     * Fetches the last 30 days of activities from Strava and persists new ones.
     * Already-imported activities (matched by stravaId) are silently skipped.
     *
     * @return int Number of newly imported activities.
     */
    public function syncUserActivities(User $user): int
    {
        if ($this->isTokenExpired($user)) {
            $this->refreshAccessToken($user);
        }

        // Build a lookup set of already-imported stravaIds — O(1) duplicate check.
        $existing = array_flip($this->activityRepo->findExistingStravaIds($user));

        // Pre-load all sports indexed by slug to avoid N+1 queries.
        $sports = [];
        foreach ($this->sportRepo->findAll() as $sport) {
            $sports[$sport->getSlug()] = $sport;
        }

        $since    = new \DateTimeImmutable('-30 days');
        $imported = 0;
        $page     = 1;

        do {
            $raw = $this->httpClient->request('GET', self::API_BASE.'/athlete/activities', [
                'headers' => ['Authorization' => 'Bearer '.$user->getStravaAccessToken()],
                'query'   => [
                    'after'    => $since->getTimestamp(),
                    'per_page' => self::PAGE_SIZE,
                    'page'     => $page,
                ],
            ])->toArray();

            foreach ($raw as $data) {
                $stravaId = (int) $data['id'];

                if (isset($existing[$stravaId])) {
                    continue;
                }

                $slug  = $this->mapStravaType($data['sport_type'] ?? $data['type'] ?? '');
                $sport = $sports[$slug] ?? $sports['other'] ?? null;

                $activity = (new Activity())
                    ->setUser($user)
                    ->setStravaId($stravaId)
                    ->setSport($sport)
                    ->setName($data['name'] ?? null)
                    ->setDistanceMeters($data['distance'] ?? null)
                    ->setMovingTimeSeconds($data['moving_time'] ?? null)
                    ->setElapsedTimeSeconds($data['elapsed_time'] ?? null)
                    ->setTotalElevationGain($data['total_elevation_gain'] ?? null)
                    ->setAverageHeartrate($data['average_heartrate'] ?? null)
                    ->setMaxHeartrate($data['max_heartrate'] ?? null)
                    ->setAverageCadence($data['average_cadence'] ?? null)
                    ->setAverageSpeed($data['average_speed'] ?? null)
                    ->setTimezone($data['timezone'] ?? null)
                    ->setSummaryPolyline($data['map']['summary_polyline'] ?? null);

                if (!empty($data['start_date'])) {
                    $activity->setStartDate(new \DateTimeImmutable($data['start_date']));
                }

                $this->em->persist($activity);

                // Mark as seen so a duplicate within the same batch is also skipped.
                $existing[$stravaId] = true;
                $imported++;
            }

            $this->em->flush();
            $page++;

        } while (count($raw) === self::PAGE_SIZE);

        return $imported;
    }

    /**
     * Fetches the altitude stream for one activity from Strava and stores it as JSON.
     * Sets an empty array when the activity has no GPS data (indoor).
     * No-ops if the elevation profile is already stored.
     */
    public function fetchAltitudeStream(User $user, Activity $activity): void
    {
        if ($activity->getElevationProfile() !== null || !$activity->getStravaId()) {
            return;
        }

        if ($this->isTokenExpired($user)) {
            $this->refreshAccessToken($user);
        }

        try {
            $streams = $this->httpClient->request('GET',
                self::API_BASE.'/activities/'.$activity->getStravaId().'/streams',
                [
                    'headers' => ['Authorization' => 'Bearer '.$user->getStravaAccessToken()],
                    'query'   => ['keys' => 'altitude', 'resolution' => 'low', 'series_type' => 'distance'],
                ]
            )->toArray();

            $data = [];
            foreach ($streams as $stream) {
                if ($stream['type'] === 'altitude') {
                    $data = $stream['data'];
                    break;
                }
            }

            $activity->setElevationProfile(json_encode($data));
        } catch (\Throwable) {
            // Strava unavailable or activity has no GPS — store empty so we don't retry every view.
            $activity->setElevationProfile('[]');
        }

        $this->em->flush();
    }

    /**
     * Fetches the heart-rate stream for one activity and stores it as JSON.
     * Same pattern as fetchAltitudeStream — no-ops if already stored.
     */
    public function fetchHeartrateStream(User $user, Activity $activity): void
    {
        if ($activity->getHeartrateStream() !== null || !$activity->getStravaId()) {
            return;
        }

        if ($this->isTokenExpired($user)) {
            $this->refreshAccessToken($user);
        }

        try {
            $streams = $this->httpClient->request('GET',
                self::API_BASE.'/activities/'.$activity->getStravaId().'/streams',
                [
                    'headers' => ['Authorization' => 'Bearer '.$user->getStravaAccessToken()],
                    'query'   => ['keys' => 'heartrate', 'resolution' => 'low', 'series_type' => 'distance'],
                ]
            )->toArray();

            $data = [];
            foreach ($streams as $stream) {
                if ($stream['type'] === 'heartrate') {
                    $data = $stream['data'];
                    break;
                }
            }

            $activity->setHeartrateStream(json_encode($data));
        } catch (\Throwable) {
            $activity->setHeartrateStream('[]');
        }

        $this->em->flush();
    }

    /**
     * Fetches splits_metric from the Strava detailed activity endpoint and stores it as JSON.
     * No-ops if already stored or no stravaId.
     */
    public function fetchSplitsMetric(User $user, Activity $activity): void
    {
        if ($activity->getSplitsMetric() !== null || !$activity->getStravaId()) {
            return;
        }

        if ($this->isTokenExpired($user)) {
            $this->refreshAccessToken($user);
        }

        try {
            $data = $this->httpClient->request('GET',
                self::API_BASE.'/activities/'.$activity->getStravaId(),
                ['headers' => ['Authorization' => 'Bearer '.$user->getStravaAccessToken()]]
            )->toArray();

            $splits = $data['splits_metric'] ?? [];
            $activity->setSplitsMetric(json_encode($splits));
        } catch (\Throwable) {
            $activity->setSplitsMetric('[]');
        }

        $this->em->flush();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function mapStravaType(string $type): string
    {
        return match ($type) {
            'Run', 'VirtualRun'                               => 'run',
            'TrailRun'                                        => 'trail',
            'Ride', 'VirtualRide', 'EBikeRide', 'GravelRide' => 'ride',
            'Swim'                                            => 'swim',
            'Walk'                                            => 'walk',
            'Hike'                                            => 'hike',
            'WeightTraining', 'Workout', 'Crossfit'           => 'weight_training',
            default                                           => 'other',
        };
    }
}
