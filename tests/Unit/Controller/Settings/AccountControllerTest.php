<?php

namespace App\Tests\Unit\Controller\Settings;

use App\Controller\Settings\AccountController;
use App\Entity\User;
use App\Service\Strava\StravaDeauthorizer;
use App\Service\User\AccountDeleter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route as RoutingRoute;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Règles de AccountController::delete() avec de vrais AccountDeleter et StravaDeauthorizer branchés sur une
 * base simulée et MockHttpClient : refus (CSRF, case, mot de passe) sans rien supprimer, ordre Strava puis
 * suppression puis déconnexion, échec de la suppression, échec Strava non bloquant, route et accès.
 */
final class AccountControllerTest extends TestCase
{
    private const CSRF     = 'valid-csrf-token';
    private const PASSWORD = 'correct horse battery';
    private const BACK     = '/settings?tab=account';

    /** @var list<string> */
    private array $calls = [];

    private Session $session;

    private bool $deleteFails = false;

    /** @var list<MockResponse> */
    private array $stravaResponses = [];

    private bool $logoutCalled = false;

    private ?InMemoryStorage $limiterStorage = null;

    private static function user(bool $strava = false): User
    {
        $user = (new User())->setEmail('delete-test@example.invalid')->setPassword('hashed');
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 42);
        if ($strava) {
            $user->setStravaAthleteId(4242)->setStravaAccessToken('fake-access')->setStravaRefreshToken('fake-refresh')
                ->setStravaTokenExpiresAt(new \DateTimeImmutable('+1 hour'));
        }

        return $user;
    }

    /** @param array<string, string> $post */
    private function post(array $post, ?User $user = null): Response
    {
        $this->calls        = [];
        $this->logoutCalled = false;
        $user ??= self::user();

        $request       = Request::create('/settings/account/delete', 'POST', $post);
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->set('_locale', 'en');
        $request->setSession($this->session);
        $stack = new RequestStack();
        $stack->push($request);

        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(
            static fn (CsrfToken $token): bool => $token->getId() === 'account_delete' && $token->getValue() === self::CSRF,
        );

        $routes = new RouteCollection();
        $routes->add('app_settings', new RoutingRoute('/settings'));
        $routes->add('app_login', new RoutingRoute('/login'));

        $container = new Container();
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('request_stack', $stack);
        $container->set('router', new UrlGenerator($routes, new RequestContext()));

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('isPasswordValid')->willReturnCallback(function (User $u, string $plain) use ($user): bool {
            $this->calls[] = 'hash';
            $this->assertSame($user, $u);

            return $plain === self::PASSWORD;
        });

        $http = new MockHttpClient(function (string $method, string $url): ResponseInterface {
            $this->calls[] = 'strava '.$method.' '.$url;

            return array_shift($this->stravaResponses) ?? new MockResponse('unexpected', ['http_code' => 500]);
        });
        $strava = new StravaDeauthorizer($http, 'client-id', 'client-secret', new NullLogger());

        $conn = $this->createMock(Connection::class);
        $conn->method('beginTransaction')->willReturnCallback(function (): void { $this->calls[] = 'begin'; });
        $conn->method('executeStatement')->willReturnCallback(function (string $sql, array $params): int {
            $this->calls[] = $sql.' '.json_encode($params);
            if ($this->deleteFails) {
                throw new \RuntimeException('database down');
            }

            return 1;
        });
        $conn->method('commit')->willReturnCallback(function (): void { $this->calls[] = 'commit'; });
        $conn->method('isTransactionActive')->willReturn(true);
        $conn->method('rollBack')->willReturnCallback(function (): void { $this->calls[] = 'rollBack'; });
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        $deleter = new AccountDeleter($em);

        $security = $this->createMock(Security::class);
        $security->method('logout')->willReturnCallback(function (bool $validateCsrf): RedirectResponse {
            $this->calls[]      = 'logout '.($validateCsrf ? 'csrf' : 'no-csrf');
            $this->logoutCalled = true;
            // Comme SessionLogoutListener : session vidée et nouvel identifiant
            $this->session->invalidate();

            return new RedirectResponse('/login');
        });

        $controller = new AccountController();
        $controller->setContainer($container);

        // Même stockage d'un appel à l'autre dans un test : le compteur d'essais continue
        $this->limiterStorage ??= new InMemoryStorage();
        $limiter = new RateLimiterFactory(['id' => 'account_delete', 'policy' => 'sliding_window', 'limit' => 5, 'interval' => '15 minutes'], $this->limiterStorage);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id.' '.json_encode($params));

        return $controller->delete($request, $hasher, $strava, $deleter, $security, new NullLogger(), $limiter, $translator, $user);
    }

    /** @return array<string, list<string>> */
    private function flashes(): array
    {
        return $this->session->getFlashBag()->peekAll();
    }

    private function assertRefused(Response $response, string $error): void
    {
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(self::BACK, $response->getTargetUrl());
        $this->assertSame(['error' => [$error]], $this->flashes());
        $this->assertFalse($this->logoutCalled);
        foreach ($this->calls as $call) {
            $this->assertStringNotContainsString('DELETE', $call, 'nothing may be deleted');
            $this->assertStringNotContainsString('strava', $call, 'Strava must not be called');
        }
        $this->assertSame('en', $this->session->get('_locale'));
    }

    /** @return array<string, string> */
    private static function valid(): array
    {
        return ['_token' => self::CSRF, 'confirm' => '1', 'password' => self::PASSWORD];
    }

    public function testMissingCsrfTokenIsRefused(): void
    {
        $post = self::valid();
        unset($post['_token']);
        $this->assertRefused($this->post($post), 'account.error_csrf');
        $this->assertNotContains('hash', $this->calls);
    }

    public function testInvalidCsrfTokenIsRefused(): void
    {
        $this->assertRefused($this->post(['_token' => 'forged'] + self::valid()), 'account.error_csrf');
        $this->assertNotContains('hash', $this->calls);
    }

    public function testUntickedBoxIsRefused(): void
    {
        $post = self::valid();
        unset($post['confirm']);
        $this->assertRefused($this->post($post), 'account.error_confirm');
        $this->assertSame([], $this->calls, 'password not even checked');
    }

    public function testOtherBoxValueIsRefused(): void
    {
        $this->assertRefused($this->post(['confirm' => 'on'] + self::valid()), 'account.error_confirm');
    }

    public function testWrongPasswordIsRefused(): void
    {
        $this->assertRefused($this->post(['password' => 'wrong'] + self::valid()), 'account.error_password');
        $this->assertSame(['hash'], $this->calls);
    }

    public function testEmptyPasswordIsRefused(): void
    {
        $this->assertRefused($this->post(['password' => ''] + self::valid()), 'account.error_password');
        $this->assertSame([], $this->calls);
    }

    public function testPasswordAttemptsAreLimitedPerUser(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->assertRefused($this->post(['password' => 'wrong-'.$i] + self::valid()), 'account.error_password');
        }

        // 6e essai dans les 15 minutes : refusé sans vérifier le mot de passe, même le bon
        $response = $this->post(self::valid());
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(self::BACK, $response->getTargetUrl());
        $flashes = $this->flashes();
        $this->assertCount(1, $flashes['error'] ?? []);
        $this->assertStringStartsWith('account.error_rate_limited {"%minutes%":', $flashes['error'][0]);
        $this->assertSame([], $this->calls, 'password not checked, nothing deleted');
        $this->assertFalse($this->logoutCalled);
    }

    public function testCsrfAndBoxFailuresDoNotUseAttempts(): void
    {
        for ($i = 0; $i < 8; ++$i) {
            $this->assertRefused($this->post(['_token' => 'forged'] + self::valid()), 'account.error_csrf');
            $this->assertRefused($this->post(['confirm' => 'on'] + self::valid()), 'account.error_confirm');
        }
        $this->post(self::valid());
        $this->assertTrue($this->logoutCalled);
    }

    public function testWrongPasswordDoesNotTouchStrava(): void
    {
        $this->assertRefused($this->post(['password' => 'wrong'] + self::valid(), self::user(true)), 'account.error_password');
    }

    public function testSuccessDeletesThenLogsOutWithoutCsrf(): void
    {
        $response = $this->post(self::valid());

        $this->assertSame(['hash', 'begin', 'DELETE FROM users WHERE id = ? [42]', 'commit', 'logout no-csrf'], $this->calls);
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/login', $response->getTargetUrl());
        // Message et langue dans la nouvelle session
        $this->assertSame(['success' => ['account.flash_deleted']], $this->flashes());
        $this->assertSame('en', $this->session->get('_locale'));
    }

    public function testStravaIsRevokedBeforeTheDeletion(): void
    {
        $this->stravaResponses = [new MockResponse('{"access_token":"fake-access"}', ['http_code' => 200])];
        $this->post(self::valid(), self::user(true));

        $this->assertSame(['hash', 'strava POST '.StravaDeauthorizer::DEAUTHORIZE_URL, 'begin', 'DELETE FROM users WHERE id = ? [42]', 'commit', 'logout no-csrf'], $this->calls);
        $this->assertSame(['success' => ['account.flash_deleted']], $this->flashes());
    }

    public function testStravaFailureDoesNotBlockTheDeletion(): void
    {
        $this->stravaResponses = [new MockResponse('', ['error' => 'Idle timeout reached'])];
        $response = $this->post(self::valid(), self::user(true));

        $this->assertContains('DELETE FROM users WHERE id = ? [42]', $this->calls);
        $this->assertContains('commit', $this->calls);
        $this->assertTrue($this->logoutCalled);
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/login', $response->getTargetUrl());
        $this->assertSame(['success' => ['account.flash_deleted'], 'warning' => ['account.flash_strava_failed']], $this->flashes());
    }

    public function testDeletionFailureKeepsTheUserLoggedIn(): void
    {
        $this->deleteFails = true;
        $response = $this->post(self::valid());

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(self::BACK, $response->getTargetUrl());
        $this->assertSame(['error' => ['account.error_failed']], $this->flashes());
        $this->assertContains('rollBack', $this->calls);
        $this->assertNotContains('commit', $this->calls);
        $this->assertFalse($this->logoutCalled);
    }

    public function testRouteIsPostOnlyWithoutIdentifierAndForTheCurrentUser(): void
    {
        $method = new \ReflectionMethod(AccountController::class, 'delete');
        $route  = $method->getAttributes(Route::class)[0]->newInstance();
        $this->assertSame('/settings/account/delete', $route->path);
        $this->assertSame('app_account_delete', $route->name);
        $this->assertSame(['POST'], $route->methods);
        $this->assertStringNotContainsString('{', (string) $route->path, 'no user id in the route');

        $granted = (new \ReflectionClass(AccountController::class))->getAttributes(IsGranted::class);
        $this->assertCount(1, $granted);
        $this->assertSame('ROLE_USER', $granted[0]->newInstance()->attribute);

        $userParam = array_values(array_filter($method->getParameters(), static fn (\ReflectionParameter $p): bool => $p->getName() === 'user'))[0];
        $this->assertCount(1, $userParam->getAttributes(CurrentUser::class));
        $this->assertSame(User::class, (string) $userParam->getType());
        $this->assertSame('account_delete', AccountController::CSRF_ID);
    }

    public function testRouteRequiresALoggedInUser(): void
    {
        $rules = Yaml::parseFile(__DIR__.'/../../../../config/packages/security.yaml')['security']['access_control'];
        // Première règle qui s'applique, comme le pare-feu
        $applied = null;
        foreach ($rules as $rule) {
            if (preg_match('#'.str_replace('#', '\\#', $rule['path']).'#', '/settings/account/delete') === 1) {
                $applied = $rule;
                break;
            }
        }
        $this->assertIsArray($applied);
        $this->assertSame('ROLE_USER', $applied['roles']);
    }
}
