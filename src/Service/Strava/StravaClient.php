<?php

namespace App\Service\Strava;

use App\Entity\User;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class StravaClient
{
    private const BASE_URL = 'https://www.strava.com/api/v3';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly StravaService $oauthService,
    ) {}

    public function getActivities(User $user, int $page = 1, int $perPage = 30): array
    {
        $this->refreshIfNeeded($user);

        $response = $this->httpClient->request('GET', self::BASE_URL.'/athlete/activities', [
            'headers' => ['Authorization' => 'Bearer '.$user->getStravaAccessToken()],
            'query'   => ['page' => $page, 'per_page' => $perPage],
        ]);

        return $response->toArray();
    }

    public function getActivity(User $user, int $stravaActivityId): array
    {
        $this->refreshIfNeeded($user);

        $response = $this->httpClient->request('GET', self::BASE_URL.'/activities/'.$stravaActivityId, [
            'headers' => ['Authorization' => 'Bearer '.$user->getStravaAccessToken()],
        ]);

        return $response->toArray();
    }

    private function refreshIfNeeded(User $user): void
    {
        if ($this->oauthService->isTokenExpired($user)) {
            $this->oauthService->refreshAccessToken($user);
        }
    }
}
