<?php

namespace App\Service\User;

use App\Entity\User;
use App\Service\Route\RemoteGpxFetcher;
use App\Service\Strava\StravaService;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Photo de profil Strava de l'utilisateur, retraitée par AvatarProcessor.
 *
 * 1. Jeton rafraîchi s'il expire (StravaService), puis GET /athlete : champ "profile" (124 x 124),
 *    à défaut "profile_medium". Un profil sans photo renvoie un chemin relatif vers l'image par
 *    défaut de Strava ("avatar/athlete/large.png") : seule une URL absolue https est retenue.
 * 2. Téléchargement durci : https uniquement (y compris après chaque redirection, 3 au plus,
 *    suivies ici), adresses privées refusées (NoPrivateNetworkHttpClient, mêmes plages que
 *    l'import GPX par lien), délai de 5 s, 5 Mo au plus vérifiés pendant la lecture.
 */
final class StravaAvatarFetcher
{
    private const API_TIMEOUT_S        = 10;
    private const API_MAX_DURATION_S   = 15;
    private const PHOTO_TIMEOUT_S      = 5;
    private const PHOTO_MAX_DURATION_S = 10;
    private const MAX_REDIRECTS        = 3;
    private const MAX_URL_LENGTH       = 2048;

    private readonly HttpClientInterface $photoClient;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly StravaService $strava,
        private readonly AvatarProcessor $processor,
        private readonly int $maxBytes = AvatarProcessor::MAX_BYTES,
    ) {
        $this->photoClient = new NoPrivateNetworkHttpClient($httpClient, RemoteGpxFetcher::BLOCKED_SUBNETS);
    }

    /**
     * @throws StravaAvatarException Strava injoignable, accès refusé, pas de photo, téléchargement impossible
     * @throws AvatarException       Photo téléchargée mais refusée par AvatarProcessor
     */
    public function fetch(User $user): ProcessedAvatar
    {
        if (!$user->isStravaConnected() || ($user->getStravaAccessToken() ?? '') === '') {
            throw new StravaAvatarException('Strava is not connected.', StravaAvatarException::NOT_CONNECTED);
        }

        $this->ensureFreshToken($user);

        return $this->processor->process($this->download($this->photoUrl($user)));
    }

    /** @throws StravaAvatarException */
    private function ensureFreshToken(User $user): void
    {
        if (!$this->strava->isTokenExpired($user)) {
            return;
        }
        try {
            $this->strava->refreshAccessToken($user);
        } catch (HttpExceptionInterface $e) {
            $status = $e->getResponse()->getStatusCode();
            throw new StravaAvatarException('Strava token refresh failed.', match (true) {
                $status === 429                  => StravaAvatarException::RATE_LIMIT,
                $status >= 400 && $status < 500 => StravaAvatarException::AUTH, // jeton de rafraîchissement révoqué
                default                          => StravaAvatarException::UNAVAILABLE,
            }, $e);
        } catch (\Throwable $e) {
            throw new StravaAvatarException('Strava token refresh failed.', StravaAvatarException::UNAVAILABLE, $e);
        }
    }

    /** @throws StravaAvatarException */
    private function photoUrl(User $user): string
    {
        try {
            $response = $this->httpClient->request('GET', StravaService::API_BASE.'/athlete', [
                'headers'      => ['Authorization' => 'Bearer '.$user->getStravaAccessToken()],
                'timeout'      => self::API_TIMEOUT_S,
                'max_duration' => self::API_MAX_DURATION_S,
            ]);
            $status = $response->getStatusCode();
            if ($status !== 200) {
                throw new StravaAvatarException(sprintf('Strava API returned HTTP %d.', $status), match ($status) {
                    401, 403 => StravaAvatarException::AUTH,
                    429      => StravaAvatarException::RATE_LIMIT,
                    default  => StravaAvatarException::UNAVAILABLE,
                });
            }
            $athlete = $response->toArray(false);
        } catch (TransportExceptionInterface|DecodingExceptionInterface $e) {
            throw new StravaAvatarException($e->getMessage(), StravaAvatarException::UNAVAILABLE, $e);
        }

        foreach (['profile', 'profile_medium'] as $key) {
            $url = $athlete[$key] ?? null;
            if (is_string($url) && self::isHttpsUrl($url)) {
                return $url;
            }
        }

        throw new StravaAvatarException('No profile photo on Strava.', StravaAvatarException::NO_PHOTO);
    }

    /** @throws StravaAvatarException */
    private function download(string $url): string
    {
        try {
            for ($redirects = 0; ; $redirects++) {
                if (!self::isHttpsUrl($url)) {
                    throw new StravaAvatarException('Photo URL is not https.', StravaAvatarException::DOWNLOAD_FAILED);
                }
                $response = $this->photoClient->request('GET', $url, [
                    'timeout'       => self::PHOTO_TIMEOUT_S,
                    'max_duration'  => self::PHOTO_MAX_DURATION_S,
                    'max_redirects' => 0, // redirections suivies ici, pour vérifier chaque adresse
                    'buffer'        => false,
                    'headers'       => ['Accept' => 'image/webp, image/jpeg, image/png, image/gif;q=0.9, */*;q=0.1'],
                ]);

                $status = $response->getStatusCode();
                if ($status >= 300 && $status < 400) {
                    $next = $response->getInfo('redirect_url');
                    $response->cancel();
                    if (!is_string($next) || $next === '' || $redirects >= self::MAX_REDIRECTS) {
                        throw new StravaAvatarException('Too many or invalid redirects.', StravaAvatarException::DOWNLOAD_FAILED);
                    }
                    $url = $next;
                    continue;
                }
                if ($status !== 200) {
                    $response->cancel();
                    throw new StravaAvatarException(sprintf('Photo download returned HTTP %d.', $status), StravaAvatarException::DOWNLOAD_FAILED);
                }

                $declared = (int) ($response->getHeaders(false)['content-length'][0] ?? 0);
                if ($declared > $this->maxBytes) {
                    $response->cancel();
                    throw new StravaAvatarException('Photo too large.', StravaAvatarException::TOO_LARGE);
                }

                // Lecture en flux avec plafond : Content-Length peut être absent ou faux
                $content = '';
                foreach ($this->photoClient->stream($response) as $chunk) {
                    $content .= $chunk->getContent();
                    if (strlen($content) > $this->maxBytes) {
                        $response->cancel();
                        throw new StravaAvatarException('Photo too large.', StravaAvatarException::TOO_LARGE);
                    }
                }

                return $content;
            }
        } catch (StravaAvatarException $e) {
            throw $e;
        } catch (ExceptionInterface $e) {
            // Réseau, délai dépassé, adresse privée refusée ("Host ... is blocked")
            throw new StravaAvatarException($e->getMessage(), StravaAvatarException::DOWNLOAD_FAILED, $e);
        }
    }

    /** URL absolue https, sans identifiants ni caractères de contrôle. */
    private static function isHttpsUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > self::MAX_URL_LENGTH || preg_match('/[\s\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts) && strtolower($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') !== ''
            && !isset($parts['user']) && !isset($parts['pass']);
    }
}
