<?php

namespace App\Tests\Unit\Controller\OAuth;

use App\Controller\OAuth\StravaController;
use App\Entity\User;
use App\Repository\ActivityRepository;
use App\Repository\GearRepository;
use App\Repository\SportRepository;
use App\Repository\UserRepository;
use App\Service\Strava\StravaOAuthState;
use App\Service\Strava\StravaService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route as RoutingRoute;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * StravaController avec un vrai StravaService branché sur MockHttpClient (jamais l'API Strava) : retour OAuth refusé
 * sans appel à Strava (state absent, différent, réutilisé), athlète déjà relié à un autre compte, autorisations
 * décochées, et synchro (CSRF, délai de 5 minutes).
 */
final class StravaControllerTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    /** @var list<MockResponse> */
    private array $responses = [];

    private Session $session;

    private InMemoryStorage $limiterStorage;

    private ?User $otherUser = null;

    protected function setUp(): void
    {
        $this->session        = new Session(new MockArraySessionStorage());
        $this->limiterStorage = new InMemoryStorage();
    }

    private static function user(bool $connected = false): User
    {
        $user = (new User())->setEmail('strava-test@example.invalid')->setPassword('hashed');
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 42);
        if ($connected) {
            $user->setStravaAthleteId(1000)->setStravaAccessToken('fake-access')->setStravaRefreshToken('fake-refresh')
                ->setStravaTokenExpiresAt(new \DateTimeImmutable('+1 hour'));
        }

        return $user;
    }

    private function controller(Request $request): StravaController
    {
        $this->calls = [];
        $request->setSession($this->session);
        $stack = new RequestStack();
        $stack->push($request);

        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $t): bool => 'strava_sync' === $t->getId() && 'valid-csrf' === $t->getValue());

        $routes = new RouteCollection();
        $routes->add('app_dashboard', new RoutingRoute('/'));

        $container = new Container();
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('request_stack', $stack);
        $container->set('router', new UrlGenerator($routes, new RequestContext()));

        $http = new MockHttpClient(function (string $method, string $url): ResponseInterface {
            $this->calls[] = $method.' '.$url;

            return array_shift($this->responses) ?? new MockResponse('unexpected', ['http_code' => 500]);
        });
        $em      = $this->createStub(EntityManagerInterface::class);
        $sports  = $this->createStub(SportRepository::class);
        $sports->method('findAll')->willReturn([]);
        $gear = $this->createStub(GearRepository::class);
        $gear->method('findDefaultsByType')->willReturn([]);
        $gear->method('findStravaGearMap')->willReturn([]);
        $activities = $this->createStub(ActivityRepository::class);
        $activities->method('findExistingStravaIds')->willReturn([]);
        $strava = new StravaService($http, $em, $activities, $sports, $gear, 'client-id', 'client-secret', 'https://app.test/oauth/strava/callback');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id.' '.json_encode($params));

        $controller = new StravaController($strava, new NullLogger(), $translator);
        $controller->setContainer($container);

        return $controller;
    }

    private function limiter(): RateLimiterFactory
    {
        return new RateLimiterFactory(['id' => 'strava_sync', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '5 minutes'], $this->limiterStorage);
    }

    private function users(): UserRepository
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findOneBy')->willReturnCallback(fn (): ?User => $this->otherUser);

        return $users;
    }

    /** @param array<string, mixed> $query */
    private function oauthReturn(array $query, User $user): Response
    {
        $request = Request::create('/oauth/strava/callback', 'GET', $query);

        return $this->controller($request)->callback($request, new StravaOAuthState(), $this->users(), $this->limiter(), $user);
    }

    /** @param array<string, string> $post */
    private function sync(array $post, User $user): Response
    {
        $request = Request::create('/strava/sync', 'POST', $post);

        return $this->controller($request)->sync($request, $this->limiter(), $user);
    }

    /** @return array<string, list<string>> */
    private function flashes(): array
    {
        return $this->session->getFlashBag()->all();
    }

    private static function tokenResponse(int $athleteId): MockResponse
    {
        return new MockResponse((string) json_encode([
            'access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_at' => 1_900_000_000,
            'athlete' => ['id' => $athleteId],
        ]));
    }

    private function issueState(): string
    {
        return (new StravaOAuthState())->issue($this->session);
    }

    public function testConnectStoresAStateAndSendsItToStrava(): void
    {
        $request  = Request::create('/oauth/strava');
        $response = $this->controller($request)->connect($request, new StravaOAuthState());

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $state = $this->session->get(StravaOAuthState::SESSION_KEY)['value'];
        parse_str((string) parse_url($response->getTargetUrl(), PHP_URL_QUERY), $query);
        $this->assertSame($state, $query['state']);
        $this->assertSame(StravaService::SCOPE, $query['scope']);
        $this->assertSame([], $this->calls);
    }

    public function testCallbackWithoutStateIsRefusedWithoutCallingStrava(): void
    {
        $this->issueState();
        $this->responses = [self::tokenResponse(1000)];
        $user = self::user();

        $response = $this->oauthReturn(['code' => 'abc', 'scope' => StravaService::SCOPE], $user);

        $this->assertSame('/', $response->headers->get('Location'));
        $this->assertSame(['error' => ['strava.flash_state_invalid']], $this->flashes());
        $this->assertSame([], $this->calls, 'Strava never called');
        $this->assertNull($user->getStravaAccessToken());
    }

    public function testCallbackWithWrongStateIsRefusedWithoutCallingStrava(): void
    {
        $this->issueState();
        $user = self::user();

        $this->oauthReturn(['code' => 'abc', 'state' => str_repeat('0', 32)], $user);

        $this->assertSame(['error' => ['strava.flash_state_invalid']], $this->flashes());
        $this->assertSame([], $this->calls);
        $this->assertNull($user->getStravaAccessToken());
    }

    public function testReusedStateIsRefused(): void
    {
        $state           = $this->issueState();
        $this->responses = [self::tokenResponse(1000), new MockResponse('[]')];
        $user            = self::user();
        $this->oauthReturn(['code' => 'abc', 'state' => $state, 'scope' => StravaService::SCOPE], $user);
        $this->assertSame('new-access', $user->getStravaAccessToken());
        $this->flashes();

        $this->responses = [self::tokenResponse(1000)];
        $this->oauthReturn(['code' => 'abc', 'state' => $state, 'scope' => StravaService::SCOPE], $user);
        $this->assertSame(['error' => ['strava.flash_state_invalid']], $this->flashes());
        $this->assertSame([], $this->calls, 'second use: Strava not called');
    }

    public function testValidCallbackLinksSyncsAndStartsTheCooldown(): void
    {
        $state           = $this->issueState();
        $this->responses = [self::tokenResponse(1000), new MockResponse('[]')];
        $user            = self::user();

        $this->oauthReturn(['code' => 'abc', 'state' => $state, 'scope' => StravaService::SCOPE], $user);

        $this->assertSame(['POST https://www.strava.com/oauth/token', 'GET '.StravaService::API_BASE.'/athlete/activities'], array_map(
            static fn (string $c): string => explode('?', $c)[0],
            $this->calls,
        ));
        $this->assertSame(1000, $user->getStravaAthleteId());
        $this->assertSame(['success' => ['strava.flash_connected', 'strava.flash_synced {"%count%":0}']], $this->flashes());

        // La synchro qui suit la connexion compte : le bouton attend 5 minutes
        $this->sync(['_token' => 'valid-csrf'], $user);
        $this->assertSame([], $this->calls);
        $this->assertStringStartsWith('strava.flash_sync_cooldown', $this->flashes()['info'][0]);
    }

    public function testAthleteLinkedToAnotherAccountIsRefused(): void
    {
        $other = self::user(true);
        (new \ReflectionProperty(User::class, 'id'))->setValue($other, 7);
        $this->otherUser = $other;
        $state           = $this->issueState();
        $this->responses = [self::tokenResponse(1000)];
        $user            = self::user();

        $this->oauthReturn(['code' => 'abc', 'state' => $state, 'scope' => StravaService::SCOPE], $user);

        $this->assertSame(['error' => ['strava.flash_athlete_taken']], $this->flashes());
        $this->assertNull($user->getStravaAccessToken());
        $this->assertNull($user->getStravaAthleteId());
        $this->assertCount(1, $this->calls, 'token exchange only, no deauthorization, no sync');
    }

    public function testSameAccountCanReconnect(): void
    {
        $user            = self::user(true);
        $this->otherUser = $user;
        $state           = $this->issueState();
        $this->responses = [self::tokenResponse(1000), new MockResponse('[]')];

        $this->oauthReturn(['code' => 'abc', 'state' => $state, 'scope' => StravaService::SCOPE], $user);

        $this->assertSame('new-access', $user->getStravaAccessToken());
        $this->assertSame('strava.flash_connected', $this->flashes()['success'][0]);
    }

    public function testMissingScopesStillLinkWithAnInfoMessage(): void
    {
        $state           = $this->issueState();
        $this->responses = [self::tokenResponse(1000)];
        $user            = self::user();

        $this->oauthReturn(['code' => 'abc', 'state' => $state, 'scope' => 'read'], $user);

        $this->assertSame('new-access', $user->getStravaAccessToken());
        $this->assertSame(['strava.scope_missing_activities', 'strava.scope_missing_profile'], $this->flashes()['info']);
        $this->assertCount(1, $this->calls, 'no activity access: no sync');

        $state           = $this->issueState();
        $this->responses = [self::tokenResponse(1000), new MockResponse('[]')];
        $this->oauthReturn(['code' => 'abc', 'state' => $state, 'scope' => 'read,activity:read,profile:read_all'], $user);
        $this->assertSame(['strava.scope_missing_private'], $this->flashes()['info']);
    }

    public function testCancelledOrCodeless(): void
    {
        $state = $this->issueState();
        $this->oauthReturn(['error' => 'access_denied', 'state' => $state], self::user());
        $this->assertSame(['warning' => ['strava.flash_cancelled']], $this->flashes());

        $state = $this->issueState();
        $this->oauthReturn(['code' => ['x'], 'state' => $state], self::user());
        $this->assertSame(['error' => ['strava.flash_code_missing']], $this->flashes());
        $this->assertSame([], $this->calls);
    }

    public function testSyncRequiresAValidCsrfToken(): void
    {
        $this->sync([], self::user(true));
        $this->assertSame(['error' => ['strava.flash_csrf']], $this->flashes());
        $this->sync(['_token' => 'forged'], self::user(true));
        $this->assertSame(['error' => ['strava.flash_csrf']], $this->flashes());
        $this->assertSame([], $this->calls);
    }

    public function testSyncCooldownIsPerUser(): void
    {
        $user            = self::user(true);
        $this->responses = [new MockResponse('[]')];
        $this->sync(['_token' => 'valid-csrf'], $user);
        $this->assertSame(['success' => ['strava.flash_synced {"%count%":0}']], $this->flashes());
        $this->assertCount(1, $this->calls);

        $this->sync(['_token' => 'valid-csrf'], $user);
        $this->assertSame([], $this->calls);
        $this->assertSame(['info' => ['strava.flash_sync_cooldown {"%minutes%":5}']], $this->flashes());

        $other = self::user(true);
        (new \ReflectionProperty(User::class, 'id'))->setValue($other, 43);
        $this->responses = [new MockResponse('[]')];
        $this->sync(['_token' => 'valid-csrf'], $other);
        $this->assertCount(1, $this->calls, 'another user is not affected');
    }
}
