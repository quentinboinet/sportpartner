<?php

namespace App\Service\Strava;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Retire l'accès de SportPartner au compte Strava de l'utilisateur (POST /oauth/deauthorize), au mieux,
 * juste avant la suppression de son compte. Strava invalide alors tous les jetons de l'application pour
 * cet athlète et la retire de ses « Mes applications ». Les données restent sur Strava.
 *
 * - Jeton d'accès expiré (ou absent) : il est d'abord rafraîchi (POST /oauth/token), sans rien enregistrer.
 * - Délais courts (5 s sans réponse, 10 s au plus par requête) : un Strava lent ou injoignable ne retarde
 *   la suppression que de quelques secondes.
 * - Ne lève jamais d'exception : un échec (HTTP, réseau, délai dépassé) est journalisé et renvoie FAILED.
 * - Les jetons partent dans le corps de la requête, jamais dans l'URL, et ne sont jamais journalisés
 *   (messages d'erreur expurgés par précaution).
 */
final class StravaDeauthorizer
{
    public const DEAUTHORIZE_URL = 'https://www.strava.com/oauth/deauthorize';
    public const TOKEN_URL       = 'https://www.strava.com/oauth/token';

    /** Aucun jeton Strava enregistré : aucune requête. */
    public const NOT_CONNECTED = 'not_connected';
    /** Strava a confirmé (réponse 2xx). */
    public const REVOKED = 'revoked';
    /** Échec journalisé : la suppression du compte continue. */
    public const FAILED = 'failed';

    private const TIMEOUT_S      = 5;
    private const MAX_DURATION_S = 10;
    /** Même marge que StravaService::isTokenExpired(). */
    private const EXPIRY_MARGIN = '+5 minutes';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'STRAVA_CLIENT_ID')]
        private readonly string $clientId,
        #[Autowire(env: 'STRAVA_CLIENT_SECRET')]
        private readonly string $clientSecret,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @return self::NOT_CONNECTED|self::REVOKED|self::FAILED
     */
    public function deauthorize(User $user): string
    {
        $accessToken  = (string) $user->getStravaAccessToken();
        $refreshToken = (string) $user->getStravaRefreshToken();
        if ($accessToken === '' && $refreshToken === '') {
            return self::NOT_CONNECTED;
        }

        $secrets = [$accessToken, $refreshToken, $this->clientSecret];
        $step    = 'deauthorize';
        try {
            $expiresAt = $user->getStravaTokenExpiresAt();
            if ($refreshToken !== '' && ($accessToken === '' || $expiresAt === null || $expiresAt < new \DateTimeImmutable(self::EXPIRY_MARGIN))) {
                $step        = 'refresh';
                $accessToken = $this->refresh($refreshToken);
                $secrets[]   = $accessToken;
                $step        = 'deauthorize';
            }

            $response = $this->httpClient->request('POST', self::DEAUTHORIZE_URL, [
                'body'         => ['access_token' => $accessToken],
                'timeout'      => self::TIMEOUT_S,
                'max_duration' => self::MAX_DURATION_S,
            ]);
            $status = $response->getStatusCode();
            // Lecture complète : un délai dépassé pendant la réponse lève ici une exception de transport
            $response->getContent(false);
        } catch (\Throwable $e) {
            $this->logger->warning('Strava deauthorization failed at step {step} (HTTP {status}): {error}', [
                'user_id' => $user->getId(),
                'step'    => $step,
                'status'  => $e instanceof HttpExceptionInterface ? $e->getResponse()->getStatusCode() : null,
                'error'   => $e::class.': '.self::redact($e->getMessage(), $secrets),
            ]);

            return self::FAILED;
        }

        if ($status < 200 || $status >= 300) {
            $this->logger->warning('Strava deauthorization failed at step {step} (HTTP {status})', [
                'user_id' => $user->getId(),
                'step'    => $step,
                'status'  => $status,
            ]);

            return self::FAILED;
        }

        $this->logger->info('Strava access revoked before account deletion', ['user_id' => $user->getId()]);

        return self::REVOKED;
    }

    /** Nouveau jeton d'accès, gardé en mémoire seulement (le compte va être supprimé). */
    private function refresh(string $refreshToken): string
    {
        $data = $this->httpClient->request('POST', self::TOKEN_URL, [
            'json' => [
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type'    => 'refresh_token',
            ],
            'timeout'      => self::TIMEOUT_S,
            'max_duration' => self::MAX_DURATION_S,
        ])->toArray();

        $token = $data['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new \UnexpectedValueException('Strava token refresh returned no access token.');
        }

        return $token;
    }

    /** @param list<string> $secrets */
    private static function redact(string $message, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $message = str_replace($secret, '[redacted]', $message);
            }
        }

        return $message;
    }
}
