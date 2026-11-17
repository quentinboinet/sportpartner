<?php

namespace App\Tests\Unit\Service\Strava;

use App\Entity\User;
use App\Service\Strava\StravaDeauthorizer;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Retrait de l'accès Strava avant la suppression du compte, avec MockHttpClient (aucun appel réel à
 * Strava, jetons factices) : succès, rafraîchissement d'un jeton expiré, échecs HTTP, délai dépassé,
 * erreur réseau. Jamais d'exception, jamais de jeton dans les journaux.
 */
final class StravaDeauthorizerTest extends TestCase
{
    private const ACCESS  = 'fake-access-token-0001';
    private const REFRESH = 'fake-refresh-token-0002';
    private const FRESH   = 'fake-new-access-token-0003';
    private const SECRET  = 'fake-client-secret-0004';

    /** @var list<array{method: string, url: string, body: string, authorization: ?string, timeout: mixed, max_duration: mixed}> */
    private array $requests = [];

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logs = [];

    /** @param array<string, list<MockResponse>> $routes Réponses par URL, servies dans l'ordre */
    private function deauthorizer(array $routes): StravaDeauthorizer
    {
        $this->requests = [];
        $this->logs     = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$routes): ResponseInterface {
            $this->requests[] = [
                'method'        => $method,
                'url'           => $url,
                'body'          => is_string($options['body'] ?? null) ? $options['body'] : '',
                'authorization' => $options['normalized_headers']['authorization'][0] ?? null,
                'timeout'       => $options['timeout'] ?? null,
                'max_duration'  => $options['max_duration'] ?? null,
            ];

            return array_shift($routes[$url]) ?? new MockResponse('unexpected request', ['http_code' => 500]);
        });

        $logs   = &$this->logs;
        $logger = new class($logs) extends AbstractLogger {
            /** @param list<array{level: string, message: string, context: array<string, mixed>}> $logs */
            public function __construct(private array &$logs)
            {
            }

            /** @param array<string, mixed> $context */
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logs[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        return new StravaDeauthorizer($client, 'client-id', self::SECRET, $logger);
    }

    private static function user(?string $expiresAt = '+1 hour', ?string $access = self::ACCESS, ?string $refresh = self::REFRESH): User
    {
        return (new User())->setEmail('strava-revoke-test@example.invalid')
            ->setStravaAthleteId(4242)
            ->setStravaAccessToken($access)
            ->setStravaRefreshToken($refresh)
            ->setStravaTokenExpiresAt($expiresAt !== null ? new \DateTimeImmutable($expiresAt) : null);
    }

    private static function ok(string $json = '{"access_token":"'.self::ACCESS.'"}'): MockResponse
    {
        return new MockResponse($json, ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]);
    }

    /** @return list<string> */
    private function urls(): array
    {
        return array_column($this->requests, 'url');
    }

    private function assertNoSecretLogged(): void
    {
        $dump = (string) json_encode($this->logs);
        foreach ([self::ACCESS, self::REFRESH, self::FRESH, self::SECRET] as $secret) {
            $this->assertStringNotContainsString($secret, $dump, 'a token or secret was logged');
        }
    }

    /** @return list<array{level: string, message: string, context: array<string, mixed>}> */
    private function warnings(): array
    {
        return array_values(array_filter($this->logs, static fn (array $log): bool => $log['level'] === 'warning'));
    }

    public function testUserWithoutStravaTokensMakesNoRequest(): void
    {
        $user = (new User())->setEmail('no-strava@example.invalid');

        $this->assertSame(StravaDeauthorizer::NOT_CONNECTED, $this->deauthorizer([])->deauthorize($user));
        $this->assertSame([], $this->requests);
        $this->assertSame([], $this->logs);
    }

    public function testValidTokenIsRevokedWithOnePost(): void
    {
        $result = $this->deauthorizer([StravaDeauthorizer::DEAUTHORIZE_URL => [self::ok()]])->deauthorize(self::user());

        $this->assertSame(StravaDeauthorizer::REVOKED, $result);
        $this->assertSame([StravaDeauthorizer::DEAUTHORIZE_URL], $this->urls());
        $this->assertSame('POST', $this->requests[0]['method']);
        // Jeton dans le corps (formulaire), pas dans l'URL
        $this->assertSame('access_token='.self::ACCESS, $this->requests[0]['body']);
        $this->assertStringNotContainsString(self::ACCESS, $this->requests[0]['url']);
        $this->assertSame([], $this->warnings());
        $this->assertNoSecretLogged();
    }

    public function testRequestsHaveShortTimeouts(): void
    {
        $this->deauthorizer([
            StravaDeauthorizer::TOKEN_URL       => [self::ok('{"access_token":"'.self::FRESH.'"}')],
            StravaDeauthorizer::DEAUTHORIZE_URL => [self::ok()],
        ])->deauthorize(self::user('-1 hour'));

        $this->assertCount(2, $this->requests);
        foreach ($this->requests as $request) {
            $this->assertIsNumeric($request['timeout']);
            $this->assertLessThanOrEqual(5, $request['timeout']);
            $this->assertIsNumeric($request['max_duration']);
            $this->assertGreaterThan(0, $request['max_duration']);
            $this->assertLessThanOrEqual(10, $request['max_duration']);
        }
    }

    public function testExpiredTokenIsRefreshedThenRevoked(): void
    {
        $user   = self::user('-1 hour');
        $result = $this->deauthorizer([
            StravaDeauthorizer::TOKEN_URL       => [self::ok('{"access_token":"'.self::FRESH.'","refresh_token":"r2","expires_at":1}')],
            StravaDeauthorizer::DEAUTHORIZE_URL => [self::ok()],
        ])->deauthorize($user);

        $this->assertSame(StravaDeauthorizer::REVOKED, $result);
        $this->assertSame([StravaDeauthorizer::TOKEN_URL, StravaDeauthorizer::DEAUTHORIZE_URL], $this->urls());
        $refresh = json_decode($this->requests[0]['body'], true);
        $this->assertIsArray($refresh);
        $this->assertSame(['client_id' => 'client-id', 'client_secret' => self::SECRET, 'refresh_token' => self::REFRESH, 'grant_type' => 'refresh_token'], $refresh);
        $this->assertSame('access_token='.self::FRESH, $this->requests[1]['body']);
        // Rien n'est enregistré sur l'utilisateur (son compte va être supprimé)
        $this->assertSame(self::ACCESS, $user->getStravaAccessToken());
        $this->assertSame(self::REFRESH, $user->getStravaRefreshToken());
        $this->assertNoSecretLogged();
    }

    public function testTokenWithoutExpiryIsRefreshedFirst(): void
    {
        $this->deauthorizer([
            StravaDeauthorizer::TOKEN_URL       => [self::ok('{"access_token":"'.self::FRESH.'"}')],
            StravaDeauthorizer::DEAUTHORIZE_URL => [self::ok()],
        ])->deauthorize(self::user(null));

        $this->assertSame([StravaDeauthorizer::TOKEN_URL, StravaDeauthorizer::DEAUTHORIZE_URL], $this->urls());
    }

    public function testExpiredTokenWithoutRefreshTokenIsStillTried(): void
    {
        $result = $this->deauthorizer([StravaDeauthorizer::DEAUTHORIZE_URL => [self::ok()]])
            ->deauthorize(self::user('-1 hour', self::ACCESS, null));

        $this->assertSame(StravaDeauthorizer::REVOKED, $result);
        $this->assertSame([StravaDeauthorizer::DEAUTHORIZE_URL], $this->urls());
    }

    public function testUnauthorizedResponseIsAFailureLoggedWithoutToken(): void
    {
        $result = $this->deauthorizer([
            StravaDeauthorizer::DEAUTHORIZE_URL => [new MockResponse('{"message":"Authorization Error","errors":[{"field":"access_token","code":"invalid"}]}', ['http_code' => 401])],
        ])->deauthorize(self::user());

        $this->assertSame(StravaDeauthorizer::FAILED, $result);
        $warnings = $this->warnings();
        $this->assertCount(1, $warnings);
        $this->assertSame(401, $warnings[0]['context']['status']);
        $this->assertSame('deauthorize', $warnings[0]['context']['step']);
        $this->assertNoSecretLogged();
    }

    public function testServerErrorIsAFailure(): void
    {
        $result = $this->deauthorizer([
            StravaDeauthorizer::DEAUTHORIZE_URL => [new MockResponse('oops', ['http_code' => 503])],
        ])->deauthorize(self::user());

        $this->assertSame(StravaDeauthorizer::FAILED, $result);
        $this->assertSame(503, $this->warnings()[0]['context']['status']);
        $this->assertNoSecretLogged();
    }

    public function testIdleTimeoutIsAFailureAndNeverThrows(): void
    {
        // Un morceau vide simule un délai d'inactivité dépassé pendant la réponse
        $timeout = new MockResponse((static function (): \Generator {
            yield '';
        })(), ['http_code' => 200]);

        $result = $this->deauthorizer([StravaDeauthorizer::DEAUTHORIZE_URL => [$timeout]])->deauthorize(self::user());

        $this->assertSame(StravaDeauthorizer::FAILED, $result);
        $warnings = $this->warnings();
        $this->assertCount(1, $warnings);
        $this->assertNull($warnings[0]['context']['status']);
        $this->assertIsString($warnings[0]['context']['error']);
        $this->assertStringContainsString('timeout', $warnings[0]['context']['error']);
        $this->assertNoSecretLogged();
    }

    public function testNetworkErrorIsAFailureAndNeverThrows(): void
    {
        $result = $this->deauthorizer([
            StravaDeauthorizer::DEAUTHORIZE_URL => [new MockResponse('', ['error' => 'Could not resolve host: www.strava.com'])],
        ])->deauthorize(self::user());

        $this->assertSame(StravaDeauthorizer::FAILED, $result);
        $this->assertIsString($this->warnings()[0]['context']['error']);
        $this->assertStringContainsString('TransportException', $this->warnings()[0]['context']['error']);
        $this->assertNoSecretLogged();
    }

    public function testRefreshFailureStopsBeforeDeauthorizing(): void
    {
        $result = $this->deauthorizer([
            StravaDeauthorizer::TOKEN_URL => [new MockResponse('{"message":"Bad Request"}', ['http_code' => 400])],
        ])->deauthorize(self::user('-1 hour'));

        $this->assertSame(StravaDeauthorizer::FAILED, $result);
        $this->assertSame([StravaDeauthorizer::TOKEN_URL], $this->urls());
        $warnings = $this->warnings();
        $this->assertSame('refresh', $warnings[0]['context']['step']);
        $this->assertSame(400, $warnings[0]['context']['status']);
        $this->assertNoSecretLogged();
    }

    public function testRefreshTimeoutIsAFailure(): void
    {
        $result = $this->deauthorizer([
            StravaDeauthorizer::TOKEN_URL => [new MockResponse((static function (): \Generator {
                yield '';
            })())],
        ])->deauthorize(self::user('-1 hour'));

        $this->assertSame(StravaDeauthorizer::FAILED, $result);
        $this->assertSame([StravaDeauthorizer::TOKEN_URL], $this->urls());
        $this->assertNoSecretLogged();
    }

    public function testRefreshWithoutAccessTokenIsAFailure(): void
    {
        $result = $this->deauthorizer([
            StravaDeauthorizer::TOKEN_URL => [self::ok('{"message":"no token here"}')],
        ])->deauthorize(self::user('-1 hour'));

        $this->assertSame(StravaDeauthorizer::FAILED, $result);
        $this->assertSame([StravaDeauthorizer::TOKEN_URL], $this->urls());
    }

    public function testTokenEchoedInAnErrorMessageIsRedacted(): void
    {
        $result = $this->deauthorizer([
            StravaDeauthorizer::DEAUTHORIZE_URL => [new MockResponse('', ['error' => 'Proxy refused token '.self::ACCESS])],
        ])->deauthorize(self::user());

        $this->assertSame(StravaDeauthorizer::FAILED, $result);
        $this->assertIsString($this->warnings()[0]['context']['error']);
        $this->assertStringContainsString('[redacted]', $this->warnings()[0]['context']['error']);
        $this->assertNoSecretLogged();
    }
}
