<?php

namespace App\Service\Strava;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class StravaOAuthService
{
    private const TOKEN_URL = 'https://www.strava.com/oauth/token';
    private const AUTHORIZE_URL = 'https://www.strava.com/oauth/authorize';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $em,
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
}
