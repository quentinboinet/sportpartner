<?php

namespace App\Tests\Unit\Service\User;

use App\Entity\User;
use App\Repository\ActivityRepository;
use App\Repository\GearRepository;
use App\Repository\SportRepository;
use App\Service\Strava\StravaService;
use App\Service\User\AvatarException;
use App\Service\User\AvatarProcessor;
use App\Service\User\StravaAvatarException;
use App\Service\User\StravaAvatarFetcher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Photo de profil Strava avec MockHttpClient (aucun appel réel à Strava). Les URL de photo
 * utilisent des IP publiques littérales : pas de résolution DNS pendant les tests.
 */
class StravaAvatarFetcherTest extends TestCase
{
    private const ATHLETE_URL = 'https://www.strava.com/api/v3/athlete';
    private const TOKEN_URL   = 'https://www.strava.com/oauth/token';
    private const PHOTO_URL   = 'https://93.184.215.14/pictures/athletes/42/7/2/large.jpg';

    /** @var list<array{method: string, url: string, authorization: ?string}> */
    private array $requests = [];

    /**
     * @param array<string, list<MockResponse>> $routes Réponses par URL, servies dans l'ordre
     */
    private function fetcher(array $routes, int $maxBytes = AvatarProcessor::MAX_BYTES): StravaAvatarFetcher
    {
        $this->requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$routes): ResponseInterface {
            $this->requests[] = [
                'method'        => $method,
                'url'           => $url,
                'authorization' => $options['normalized_headers']['authorization'][0] ?? null,
            ];

            return array_shift($routes[$url]) ?? new MockResponse('unexpected request', ['http_code' => 500]);
        });

        $strava = new StravaService(
            $client,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(ActivityRepository::class),
            $this->createMock(SportRepository::class),
            $this->createMock(GearRepository::class),
            'client-id',
            'client-secret',
            'http://localhost/oauth/strava/callback',
        );

        return new StravaAvatarFetcher($client, $strava, new AvatarProcessor(), $maxBytes);
    }

    private static function user(string $expiresAt = '+1 hour'): User
    {
        return (new User())->setEmail('avatar-test@example.invalid')
            ->setStravaAthleteId(42)
            ->setStravaAccessToken('access-token')
            ->setStravaRefreshToken('refresh-token')
            ->setStravaTokenExpiresAt(new \DateTimeImmutable($expiresAt));
    }

    /** @param array<string, mixed> $athlete */
    private static function athlete(array $athlete): MockResponse
    {
        return new MockResponse((string) json_encode(['id' => 42, 'firstname' => 'Test'] + $athlete), [
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    private static function jpeg(int $size = 300): string
    {
        $im = imagecreatetruecolor($size, $size);
        self::assertInstanceOf(\GdImage::class, $im);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 252, 76, 2));
        ob_start();
        imagejpeg($im, null, 90);

        return (string) ob_get_clean();
    }

    /** @param array<string, list<MockResponse>> $routes */
    private function assertFetchFails(int $code, array $routes, ?User $user = null, int $maxBytes = AvatarProcessor::MAX_BYTES): StravaAvatarException
    {
        try {
            $this->fetcher($routes, $maxBytes)->fetch($user ?? self::user());
            $this->fail('StravaAvatarException expected');
        } catch (StravaAvatarException $e) {
            $this->assertSame($code, $e->getCode(), $e->getMessage());

            return $e;
        }
    }

    /** @return list<string> */
    private function requestedUrls(): array
    {
        return array_column($this->requests, 'url');
    }

    public function testPhotoIsDownloadedAndProcessed(): void
    {
        $avatar = $this->fetcher([
            self::ATHLETE_URL => [self::athlete(['profile_medium' => 'https://93.184.215.14/medium.jpg', 'profile' => self::PHOTO_URL])],
            self::PHOTO_URL   => [new MockResponse(self::jpeg(), ['response_headers' => ['content-type' => 'image/jpeg']])],
        ])->fetch(self::user());

        $this->assertSame('image/webp', $avatar->mimeType);
        $this->assertSame([256, 256], [$avatar->width, $avatar->height]);
        $this->assertSame([self::ATHLETE_URL, self::PHOTO_URL], $this->requestedUrls());
        // Le jeton Strava va à l'API, jamais au serveur de la photo
        $this->assertSame('Authorization: Bearer access-token', $this->requests[0]['authorization']);
        $this->assertNull($this->requests[1]['authorization']);
    }

    public function testMediumPhotoIsUsedWhenLargeIsMissing(): void
    {
        $medium = 'https://93.184.215.14/pictures/athletes/42/7/2/medium.jpg';
        $avatar = $this->fetcher([
            self::ATHLETE_URL => [self::athlete(['profile' => 'avatar/athlete/large.png', 'profile_medium' => $medium])],
            $medium           => [new MockResponse(self::jpeg(62))],
        ])->fetch(self::user());

        // Image de 62 px : pas d'agrandissement
        $this->assertSame([62, 62], [$avatar->width, $avatar->height]);
    }

    public function testDefaultStravaPlaceholderMeansNoPhoto(): void
    {
        $this->assertFetchFails(StravaAvatarException::NO_PHOTO, [
            self::ATHLETE_URL => [self::athlete(['profile' => 'avatar/athlete/large.png', 'profile_medium' => 'avatar/athlete/medium.png'])],
        ]);
        $this->assertSame([self::ATHLETE_URL], $this->requestedUrls());
    }

    public function testMissingOrInsecurePhotoUrlMeansNoPhoto(): void
    {
        $this->assertFetchFails(StravaAvatarException::NO_PHOTO, [self::ATHLETE_URL => [self::athlete([])]]);
        $this->assertFetchFails(StravaAvatarException::NO_PHOTO, [
            self::ATHLETE_URL => [self::athlete(['profile' => 'http://93.184.215.14/large.jpg', 'profile_medium' => null])],
        ]);
        $this->assertFetchFails(StravaAvatarException::NO_PHOTO, [
            self::ATHLETE_URL => [self::athlete(['profile' => 'https://user:pass@93.184.215.14/large.jpg'])],
        ]);
        $this->assertSame([self::ATHLETE_URL], $this->requestedUrls());
    }

    public function testNotConnectedMakesNoRequest(): void
    {
        $this->assertFetchFails(StravaAvatarException::NOT_CONNECTED, [], self::user()->setStravaAthleteId(null));
        $this->assertFetchFails(StravaAvatarException::NOT_CONNECTED, [], self::user()->setStravaAccessToken(null));
        $this->assertSame([], $this->requests);
    }

    public function testUnauthorizedAskToReconnect(): void
    {
        $e = $this->assertFetchFails(StravaAvatarException::AUTH, [self::ATHLETE_URL => [new MockResponse('{"message":"Authorization Error"}', ['http_code' => 401])]]);
        $this->assertSame('avatar.strava.auth', $e->translationKey());
        $this->assertFetchFails(StravaAvatarException::AUTH, [self::ATHLETE_URL => [new MockResponse('{}', ['http_code' => 403])]]);
    }

    public function testRateLimitAndServerErrors(): void
    {
        $this->assertFetchFails(StravaAvatarException::RATE_LIMIT, [self::ATHLETE_URL => [new MockResponse('{}', ['http_code' => 429])]]);
        $this->assertFetchFails(StravaAvatarException::UNAVAILABLE, [self::ATHLETE_URL => [new MockResponse('{}', ['http_code' => 503])]]);
        $this->assertFetchFails(StravaAvatarException::UNAVAILABLE, [self::ATHLETE_URL => [new MockResponse('<html>maintenance</html>')]]);
    }

    public function testNetworkErrorOnStravaApi(): void
    {
        $e = $this->assertFetchFails(StravaAvatarException::UNAVAILABLE, [self::ATHLETE_URL => [new MockResponse('', ['error' => 'Could not resolve host: www.strava.com'])]]);
        $this->assertSame('avatar.strava.unavailable', $e->translationKey());
    }

    public function testNetworkErrorOnPhotoDownload(): void
    {
        $e = $this->assertFetchFails(StravaAvatarException::DOWNLOAD_FAILED, [
            self::ATHLETE_URL => [self::athlete(['profile' => self::PHOTO_URL])],
            self::PHOTO_URL   => [new MockResponse('', ['error' => 'Connection timed out'])],
        ]);
        $this->assertSame('avatar.strava.download', $e->translationKey());
    }

    public function testPhotoHttpError(): void
    {
        $this->assertFetchFails(StravaAvatarException::DOWNLOAD_FAILED, [
            self::ATHLETE_URL => [self::athlete(['profile' => self::PHOTO_URL])],
            self::PHOTO_URL   => [new MockResponse('Not found', ['http_code' => 404])],
        ]);
    }

    public function testTooLargeStreamIsCutOff(): void
    {
        $chunks = (static function (): \Generator {
            for ($i = 0; $i < 100; $i++) {
                yield str_repeat('x', 10_000);
            }
        })();

        $e = $this->assertFetchFails(StravaAvatarException::TOO_LARGE, [
            self::ATHLETE_URL => [self::athlete(['profile' => self::PHOTO_URL])],
            self::PHOTO_URL   => [new MockResponse($chunks)],
        ], maxBytes: 50_000);
        $this->assertSame('avatar.strava.download', $e->translationKey());
    }

    public function testDeclaredContentLengthTooLarge(): void
    {
        $body = str_repeat('x', 5000);

        $this->assertFetchFails(StravaAvatarException::TOO_LARGE, [
            self::ATHLETE_URL => [self::athlete(['profile' => self::PHOTO_URL])],
            self::PHOTO_URL   => [new MockResponse($body, ['response_headers' => ['content-length' => (string) strlen($body)]])],
        ], maxBytes: 1000);
    }

    public function testPrivatePhotoAddressIsBlocked(): void
    {
        $private = 'https://10.0.0.5/large.jpg';
        $this->assertFetchFails(StravaAvatarException::DOWNLOAD_FAILED, [
            self::ATHLETE_URL => [self::athlete(['profile' => $private])],
            $private          => [new MockResponse(self::jpeg())],
        ]);
        $this->assertSame([self::ATHLETE_URL], $this->requestedUrls());
    }

    public function testRedirectToPrivateNetworkIsBlocked(): void
    {
        $metadata = 'https://169.254.169.254/latest/meta-data/';
        $e = $this->assertFetchFails(StravaAvatarException::DOWNLOAD_FAILED, [
            self::ATHLETE_URL => [self::athlete(['profile' => self::PHOTO_URL])],
            self::PHOTO_URL   => [new MockResponse('', ['http_code' => 302, 'redirect_url' => $metadata])],
            $metadata         => [new MockResponse('secret')],
        ]);
        $this->assertStringContainsString('is blocked', (string) $e->getPrevious()?->getMessage());
        $this->assertSame([self::ATHLETE_URL, self::PHOTO_URL], $this->requestedUrls());
    }

    public function testRedirectToPlainHttpIsRefused(): void
    {
        $insecure = 'http://93.184.215.14/large.jpg';
        $this->assertFetchFails(StravaAvatarException::DOWNLOAD_FAILED, [
            self::ATHLETE_URL => [self::athlete(['profile' => self::PHOTO_URL])],
            self::PHOTO_URL   => [new MockResponse('', ['http_code' => 301, 'redirect_url' => $insecure])],
            $insecure         => [new MockResponse(self::jpeg())],
        ]);
        $this->assertSame([self::ATHLETE_URL, self::PHOTO_URL], $this->requestedUrls());
    }

    public function testHttpsRedirectsAreFollowedUpToThree(): void
    {
        $hops = ['https://93.184.215.15/a.jpg', 'https://93.184.215.16/b.jpg', 'https://93.184.215.17/c.jpg'];
        $avatar = $this->fetcher([
            self::ATHLETE_URL => [self::athlete(['profile' => self::PHOTO_URL])],
            self::PHOTO_URL   => [new MockResponse('', ['http_code' => 302, 'redirect_url' => $hops[0]])],
            $hops[0]          => [new MockResponse('', ['http_code' => 302, 'redirect_url' => $hops[1]])],
            $hops[1]          => [new MockResponse('', ['http_code' => 307, 'redirect_url' => $hops[2]])],
            $hops[2]          => [new MockResponse(self::jpeg())],
        ])->fetch(self::user());

        $this->assertSame(256, $avatar->width);
        $this->assertSame([self::ATHLETE_URL, self::PHOTO_URL, ...$hops], $this->requestedUrls());
    }

    public function testFourthRedirectIsRefused(): void
    {
        $hops = ['https://93.184.215.15/a.jpg', 'https://93.184.215.16/b.jpg', 'https://93.184.215.17/c.jpg', 'https://93.184.215.18/d.jpg'];
        $this->assertFetchFails(StravaAvatarException::DOWNLOAD_FAILED, [
            self::ATHLETE_URL => [self::athlete(['profile' => self::PHOTO_URL])],
            self::PHOTO_URL   => [new MockResponse('', ['http_code' => 302, 'redirect_url' => $hops[0]])],
            $hops[0]          => [new MockResponse('', ['http_code' => 302, 'redirect_url' => $hops[1]])],
            $hops[1]          => [new MockResponse('', ['http_code' => 302, 'redirect_url' => $hops[2]])],
            $hops[2]          => [new MockResponse('', ['http_code' => 302, 'redirect_url' => $hops[3]])],
            $hops[3]          => [new MockResponse(self::jpeg())],
        ]);
        $this->assertNotContains($hops[3], $this->requestedUrls());
    }

    public function testExpiredTokenIsRefreshedFirst(): void
    {
        $user   = self::user('-1 hour');
        $avatar = $this->fetcher([
            self::TOKEN_URL   => [new MockResponse((string) json_encode(['access_token' => 'new-token', 'refresh_token' => 'new-refresh', 'expires_at' => time() + 21600]))],
            self::ATHLETE_URL => [self::athlete(['profile' => self::PHOTO_URL])],
            self::PHOTO_URL   => [new MockResponse(self::jpeg())],
        ])->fetch($user);

        $this->assertSame(256, $avatar->width);
        $this->assertSame([self::TOKEN_URL, self::ATHLETE_URL, self::PHOTO_URL], $this->requestedUrls());
        $this->assertSame('Authorization: Bearer new-token', $this->requests[1]['authorization']);
        $this->assertSame('new-token', $user->getStravaAccessToken());
    }

    public function testRevokedRefreshTokenAsksToReconnect(): void
    {
        $this->assertFetchFails(StravaAvatarException::AUTH, [
            self::TOKEN_URL => [new MockResponse('{"message":"Bad Request"}', ['http_code' => 400])],
        ], self::user('-1 hour'));
        $this->assertSame([self::TOKEN_URL], $this->requestedUrls());
    }

    public function testInvalidImageFromStravaIsRejectedByTheProcessor(): void
    {
        try {
            $this->fetcher([
                self::ATHLETE_URL => [self::athlete(['profile' => self::PHOTO_URL])],
                self::PHOTO_URL   => [new MockResponse('<!doctype html><html><body>Access denied</body></html>')],
            ])->fetch(self::user());
            $this->fail('AvatarException expected');
        } catch (AvatarException $e) {
            $this->assertSame(AvatarException::UNSUPPORTED_TYPE, $e->getCode());
        }
    }
}
