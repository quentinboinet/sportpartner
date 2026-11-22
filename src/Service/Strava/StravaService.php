<?php

namespace App\Service\Strava;

use App\Entity\Activity;
use App\Entity\Gear;
use App\Entity\GearType;
use App\Entity\User;
use App\Repository\ActivityRepository;
use App\Repository\GearRepository;
use App\Repository\SportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class StravaService
{
    private const TOKEN_URL     = 'https://www.strava.com/oauth/token';
    private const AUTHORIZE_URL = 'https://www.strava.com/oauth/authorize';
    public const API_BASE       = 'https://www.strava.com/api/v3';
    private const PAGE_SIZE     = 200;

    /**
     * Autorisations demandées. profile:read_all (ajoutée avec l'import du matériel) fait figurer
     * vélos et chaussures dans GET /athlete, y compris le matériel encore jamais utilisé. Les comptes
     * connectés avant doivent reconnecter Strava pour l'obtenir ; l'import fonctionne sans
     * (matériel trouvé via les activités).
     */
    public const SCOPE = 'read,activity:read_all,profile:read_all';

    /**
     * Autorisations de SCOPE absentes de celles accordées (paramètre `scope` du retour OAuth, séparé par des
     * virgules) : l'utilisateur peut décocher des cases sur l'écran de Strava. Sans activity:read_all, seules les
     * activités visibles sont synchronisées (activity:read) ou aucune ; sans profile:read_all, l'import du
     * matériel ne trouve que le matériel déjà utilisé dans une activité.
     *
     * @return list<string>
     */
    public static function missingScopes(string $granted): array
    {
        return array_values(array_diff(explode(',', self::SCOPE), self::grantedScopes($granted)));
    }

    /** Lecture des activités accordée (toutes ou seulement les visibles) : la synchro peut importer quelque chose. */
    public static function grantsActivities(string $granted): bool
    {
        return [] !== array_intersect(['activity:read', 'activity:read_all'], self::grantedScopes($granted));
    }

    /** @return list<string> */
    private static function grantedScopes(string $granted): array
    {
        return array_values(array_filter(array_map('trim', explode(',', strtolower($granted))), static fn (string $s): bool => '' !== $s));
    }

    /** Identifiant de l'athlète Strava dans la réponse du jeton, null s'il est absent ou invalide. */
    public static function athleteId(mixed $tokenData): ?int
    {
        $id = \is_array($tokenData) && \is_array($tokenData['athlete'] ?? null) ? ($tokenData['athlete']['id'] ?? null) : null;

        return \is_int($id) && $id > 0 ? $id : null;
    }

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $em,
        private readonly ActivityRepository $activityRepo,
        private readonly SportRepository $sportRepo,
        private readonly GearRepository $gearRepo,
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
            'scope'         => self::SCOPE,
            'state'         => $state,
        ]);
    }

    /** @return array<string, mixed> */
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

    /** @param array<string, mixed> $tokenData */
    public function connectUser(User $user, array $tokenData): void
    {
        $user->setStravaAthleteId(self::athleteId($tokenData));
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

        // Build a lookup set of already-imported stravaIds - O(1) duplicate check.
        $existing = array_flip($this->activityRepo->findExistingStravaIds($user));

        // Pre-load all sports indexed by slug to avoid N+1 queries.
        $sports = [];
        foreach ($this->sportRepo->findAll() as $sport) {
            $sports[$sport->getSlug()] = $sport;
        }

        // Matériel par défaut de l'utilisateur, rattaché aux nouvelles activités du sport correspondant.
        $defaultGear = $this->gearRepo->findDefaultsByType($user);
        // Matériel importé de Strava : prioritaire quand l'activité Strava indique son gear_id.
        $stravaGear = $this->gearRepo->findStravaGearMap($user);

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

                $gearId = $data['gear_id'] ?? null;
                if (is_string($gearId) && isset($stravaGear[$gearId])) {
                    $activity->setGear($stravaGear[$gearId]);
                    $stravaGear[$gearId]->deductImportedStravaActivity($stravaId, $activity->getDistanceMeters());
                } else {
                    $activity->setGear(self::pickDefaultGear($defaultGear, $slug, $activity->getStartDate()));
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
            // Strava unavailable or activity has no GPS - store empty so we don't retry every view.
            $activity->setElevationProfile('[]');
        }

        $this->em->flush();
    }

    /**
     * Fetches the heart-rate stream for one activity and stores it as JSON.
     * Same pattern as fetchAltitudeStream - no-ops if already stored.
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

    /**
     * Matériel par défaut à rattacher à une nouvelle activité : celui du type lié au sport
     * (chaussures pour course / trail / marche / rando, vélo pour le vélo), sauf si l'activité
     * est antérieure à sa date d'achat.
     *
     * @param array<string, Gear> $defaults Matériel par défaut indexé par type ('shoes', 'bike').
     */
    public static function pickDefaultGear(array $defaults, string $sportSlug, ?\DateTimeImmutable $startDate): ?Gear
    {
        $type = GearType::forSportSlug($sportSlug);
        $gear = $type !== null ? ($defaults[$type->value] ?? null) : null;
        if ($gear === null) {
            return null;
        }
        $purchased = $gear->getPurchasedAt();
        if ($purchased !== null && $startDate !== null && $startDate < $purchased) {
            return null;
        }

        return $gear;
    }

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
