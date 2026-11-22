<?php

namespace App\Tests\Unit\Service\Strava;

use App\Controller\OAuth\StravaController;
use App\Service\Strava\StravaOAuthState;
use App\Service\Strava\StravaService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * `state` de l'OAuth Strava (usage unique, comparaison en temps constant, durée de vie), autorisations accordées et
 * routes du contrôleur (synchro en POST seulement).
 */
final class StravaOAuthStateTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private StravaOAuthState $state;
    private Session $session;

    protected function setUp(): void
    {
        $this->state   = new StravaOAuthState();
        $this->session = new Session(new MockArraySessionStorage());
    }

    public function testIssuedStateIsRandomAndStoredInTheSession(): void
    {
        $a = $this->state->issue($this->session, self::NOW);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $a);
        $this->assertSame(['value' => $a, 'at' => self::NOW], $this->session->get(StravaOAuthState::SESSION_KEY));

        $b = $this->state->issue($this->session, self::NOW);
        $this->assertNotSame($a, $b);
        $this->assertSame($b, $this->session->get(StravaOAuthState::SESSION_KEY)['value'], 'a new issue replaces the previous one');
    }

    public function testMatchingStateIsAcceptedOnce(): void
    {
        $value = $this->state->issue($this->session, self::NOW);

        $this->assertTrue($this->state->consume($this->session, $value, self::NOW + 30));
        $this->assertFalse($this->session->has(StravaOAuthState::SESSION_KEY), 'removed after use');
        $this->assertFalse($this->state->consume($this->session, $value, self::NOW + 31), 'reused state refused');
    }

    public function testMissingStateIsRefused(): void
    {
        $this->state->issue($this->session, self::NOW);
        $this->assertFalse($this->state->consume($this->session, null, self::NOW));
        $this->assertFalse($this->session->has(StravaOAuthState::SESSION_KEY), 'even a failed check uses the state');
    }

    /** @return iterable<string, array{mixed}> */
    public static function wrongStates(): iterable
    {
        yield 'empty' => [''];
        yield 'other value' => ['0123456789abcdef0123456789abcdef'];
        yield 'array' => [['x']];
        yield 'integer' => [42];
    }

    #[DataProvider('wrongStates')]
    public function testWrongStateIsRefusedAndTheRealOneIsBurnt(mixed $received): void
    {
        $value = $this->state->issue($this->session, self::NOW);

        $this->assertFalse($this->state->consume($this->session, $received, self::NOW));
        $this->assertFalse($this->state->consume($this->session, $value, self::NOW), 'one try only');
    }

    public function testNoStateInSessionIsRefused(): void
    {
        $this->assertFalse($this->state->consume($this->session, 'anything', self::NOW));
        $this->session->set(StravaOAuthState::SESSION_KEY, 'not-an-array');
        $this->assertFalse($this->state->consume($this->session, 'not-an-array', self::NOW));
    }

    public function testExpiredStateIsRefused(): void
    {
        $value = $this->state->issue($this->session, self::NOW);
        $this->assertFalse($this->state->consume($this->session, $value, self::NOW + StravaOAuthState::TTL_SECONDS + 1));

        $value = $this->state->issue($this->session, self::NOW);
        $this->assertTrue($this->state->consume($this->session, $value, self::NOW + StravaOAuthState::TTL_SECONDS));
    }

    public function testMissingScopes(): void
    {
        $this->assertSame([], StravaService::missingScopes('read,activity:read_all,profile:read_all'));
        $this->assertSame([], StravaService::missingScopes(' READ , profile:read_all,activity:read_all '));
        $this->assertSame(['activity:read_all'], StravaService::missingScopes('read,activity:read,profile:read_all'));
        $this->assertSame(['profile:read_all'], StravaService::missingScopes('read,activity:read_all'));
        $this->assertSame(['activity:read_all', 'profile:read_all'], StravaService::missingScopes('read'));
        $this->assertSame(['read', 'activity:read_all', 'profile:read_all'], StravaService::missingScopes(''));
    }

    public function testActivitiesGranted(): void
    {
        $this->assertTrue(StravaService::grantsActivities('read,activity:read_all'));
        $this->assertTrue(StravaService::grantsActivities('read,activity:read'));
        $this->assertFalse(StravaService::grantsActivities('read,profile:read_all'));
        $this->assertFalse(StravaService::grantsActivities(''));
    }

    public function testAthleteIdFromTokenResponse(): void
    {
        $this->assertSame(4242, StravaService::athleteId(['athlete' => ['id' => 4242]]));
        $this->assertNull(StravaService::athleteId(['athlete' => ['id' => '4242']]));
        $this->assertNull(StravaService::athleteId(['athlete' => ['id' => 0]]));
        $this->assertNull(StravaService::athleteId(['athlete' => 'x']));
        $this->assertNull(StravaService::athleteId([]));
        $this->assertNull(StravaService::athleteId(null));
    }

    public function testRoutes(): void
    {
        $route = static fn (string $method): Route => (new \ReflectionMethod(StravaController::class, $method))->getAttributes(Route::class)[0]->newInstance();

        $this->assertSame(['POST'], $route('sync')->methods, 'sync is POST only (CSRF)');
        $this->assertSame('/strava/sync', $route('sync')->path);
        $this->assertSame(['GET'], $route('connect')->methods);
        $this->assertSame(['GET'], $route('callback')->methods);
        $this->assertSame('strava_sync', StravaController::SYNC_CSRF_ID);

        $granted = (new \ReflectionClass(StravaController::class))->getAttributes(IsGranted::class);
        $this->assertCount(1, $granted);
        $this->assertSame('ROLE_USER', $granted[0]->newInstance()->attribute);
    }
}
