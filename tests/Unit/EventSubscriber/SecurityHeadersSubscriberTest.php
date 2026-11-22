<?php

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\SecurityHeadersSubscriber;
use App\Security\ContentSecurityPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * SecurityHeadersSubscriber avec un environnement de noyau simulé (dev / prod) : nonce par requête, en-têtes des pages
 * HTML sans écraser ceux d'un controller, redirection https et HSTS en prod seulement, no-store pour un utilisateur
 * connecté, profileur ignoré en dev, sous-requêtes ignorées.
 */
final class SecurityHeadersSubscriberTest extends TestCase
{
    private static function subscriber(string $env = 'dev', bool $authenticated = false): SecurityHeadersSubscriber
    {
        $storage = new TokenStorage();
        if ($authenticated) {
            $user = new InMemoryUser('runner@example.invalid', null, ['ROLE_USER']);
            $storage->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
        }

        return new SecurityHeadersSubscriber($env, $storage);
    }

    private static function kernel(): HttpKernelInterface
    {
        return new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };
    }

    private static function requestEvent(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        return new RequestEvent(self::kernel(), $request, $type);
    }

    /** Requête complète (requête puis réponse), comme le noyau. */
    private static function handle(
        SecurityHeadersSubscriber $subscriber,
        Request $request,
        ?Response $response = null,
        int $type = HttpKernelInterface::MAIN_REQUEST,
    ): Response {
        $event = self::requestEvent($request, $type);
        $subscriber->onKernelRequest($event);
        $response = $event->getResponse() ?? $response ?? new Response('<html></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        $subscriber->onKernelResponse(new ResponseEvent(self::kernel(), $request, $type, $response));

        return $response;
    }

    public function testSubscribedEventsAndPriorities(): void
    {
        self::assertSame([
            KernelEvents::REQUEST  => ['onKernelRequest', 255],
            KernelEvents::RESPONSE => ['onKernelResponse', -10],
        ], SecurityHeadersSubscriber::getSubscribedEvents());
    }

    public function testEachMainRequestGetsItsOwnNonce(): void
    {
        $subscriber = self::subscriber();
        $nonces     = [];
        for ($i = 0; $i < 5; ++$i) {
            $request = Request::create('/');
            $subscriber->onKernelRequest(self::requestEvent($request));
            $nonce = $request->attributes->get(SecurityHeadersSubscriber::NONCE_ATTRIBUTE);
            self::assertIsString($nonce);
            self::assertSame(16, \strlen((string) base64_decode($nonce, true)), 'random_bytes(16) en base64');
            $nonces[] = $nonce;
        }
        self::assertCount(5, array_unique($nonces));
    }

    public function testSubRequestGetsNoNonceOfItsOwn(): void
    {
        $request = Request::create('/');
        self::subscriber()->onKernelRequest(self::requestEvent($request, HttpKernelInterface::SUB_REQUEST));

        self::assertFalse($request->attributes->has(SecurityHeadersSubscriber::NONCE_ATTRIBUTE));
    }

    public function testNonceHelperCreatesOnceThenReuses(): void
    {
        $request = Request::create('/');
        $nonce   = SecurityHeadersSubscriber::nonce($request);

        self::assertSame($nonce, SecurityHeadersSubscriber::nonce($request));
        self::assertSame($nonce, $request->attributes->get(SecurityHeadersSubscriber::NONCE_ATTRIBUTE));
    }

    public function testHtmlResponseGetsEveryHeaderWithTheRequestNonce(): void
    {
        $request  = Request::create('/');
        $response = self::handle(self::subscriber(), $request);
        $nonce    = $request->attributes->get(SecurityHeadersSubscriber::NONCE_ATTRIBUTE);
        self::assertIsString($nonce);

        self::assertSame(ContentSecurityPolicy::header($nonce), $response->headers->get('Content-Security-Policy'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        self::assertSame(
            'camera=(), microphone=(), payment=(), usb=(), geolocation=(self)',
            $response->headers->get('Permissions-Policy'),
        );
        self::assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
        self::assertFalse($response->headers->has('Strict-Transport-Security'), 'pas de HSTS en dev');
    }

    public function testResponseWithoutContentTypeIsTreatedAsHtml(): void
    {
        $response = self::handle(self::subscriber(), Request::create('/'), new Response('<p>x</p>'));

        self::assertTrue($response->headers->has('Content-Security-Policy'));
    }

    public function testNonHtmlResponseOnlyGetsNosniff(): void
    {
        $response = self::handle(self::subscriber('dev', true), Request::create('/notifications'), new JsonResponse(['items' => []]));

        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        foreach (['Content-Security-Policy', 'X-Frame-Options', 'Referrer-Policy', 'Permissions-Policy'] as $name) {
            self::assertFalse($response->headers->has($name), $name);
        }
        self::assertFalse($response->headers->hasCacheControlDirective('no-store'));
    }

    public function testHeadersSetByAControllerAreKept(): void
    {
        $response = new Response('<html></html>', 200, [
            'Content-Type'            => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => "default-src 'none'",
            'X-Frame-Options'         => 'SAMEORIGIN',
            'Referrer-Policy'         => 'no-referrer',
            'Permissions-Policy'      => 'geolocation=()',
            'X-Content-Type-Options'  => 'nosniff',
        ]);
        $response = self::handle(self::subscriber(), Request::create('/'), $response);

        self::assertSame("default-src 'none'", $response->headers->get('Content-Security-Policy'));
        self::assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertSame('geolocation=()', $response->headers->get('Permissions-Policy'));
        self::assertCount(1, $response->headers->all('x-content-type-options'));
    }

    public function testPublicSharePageKeepsItsOwnHeadersAndGetsTheCsp(): void
    {
        // Comme RacePublicController::secure()
        $response = new Response('<html></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        $response = self::handle(self::subscriber('prod', true), Request::create('https://example.com/p/abc'), $response);

        self::assertTrue($response->headers->has('Content-Security-Policy'));
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    public function testSubRequestResponseIsLeftAlone(): void
    {
        $response = self::handle(self::subscriber('prod'), Request::create('https://example.com/'), null, HttpKernelInterface::SUB_REQUEST);

        self::assertFalse($response->headers->has('Content-Security-Policy'));
        self::assertFalse($response->headers->has('Strict-Transport-Security'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function profilerPaths(): iterable
    {
        yield 'toolbar' => ['/_wdt/abc123'];
        yield 'profiler' => ['/_profiler/abc123'];
        yield 'profiler home' => ['/_profiler'];
    }

    #[DataProvider('profilerPaths')]
    public function testProfilerIsSkippedInDev(string $path): void
    {
        $response = self::handle(self::subscriber('dev'), Request::create($path));

        self::assertFalse($response->headers->has('Content-Security-Policy'));
        self::assertFalse($response->headers->has('X-Frame-Options'));
    }

    public function testProfilerPathsAreNotSpecialInProd(): void
    {
        $response = self::handle(self::subscriber('prod'), Request::create('https://example.com/_profiler'));

        self::assertTrue($response->headers->has('Content-Security-Policy'));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function insecureRequests(): iterable
    {
        yield 'GET' => ['GET', 301];
        yield 'HEAD' => ['HEAD', 301];
        yield 'POST' => ['POST', 308];
        yield 'PUT' => ['PUT', 308];
        yield 'DELETE' => ['DELETE', 308];
    }

    #[DataProvider('insecureRequests')]
    public function testProdRedirectsHttpToHttps(string $method, int $status): void
    {
        $request = Request::create('http://sportpartner.example/courses/5/plan?scenario=3&format=band', $method);
        $event   = self::requestEvent($request);
        self::subscriber('prod')->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame($status, $response->getStatusCode());
        self::assertSame('https://sportpartner.example/courses/5/plan?scenario=3&format=band', $response->getTargetUrl());
    }

    public function testRedirectDropsTheHttpPort(): void
    {
        $event = self::requestEvent(Request::create('http://sportpartner.example:8080/login'));
        self::subscriber('prod')->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('https://sportpartner.example/login', $response->getTargetUrl());
    }

    public function testProdHttpsRequestIsNotRedirected(): void
    {
        $event = self::requestEvent(Request::create('https://sportpartner.example/'));
        self::subscriber('prod')->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testProdHttpSubRequestIsNotRedirected(): void
    {
        $event = self::requestEvent(Request::create('http://sportpartner.example/'), HttpKernelInterface::SUB_REQUEST);
        self::subscriber('prod')->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonProdEnvironments(): iterable
    {
        yield 'dev' => ['dev'];
        yield 'test' => ['test'];
    }

    #[DataProvider('nonProdEnvironments')]
    public function testNoRedirectNorHstsOutsideProd(string $env): void
    {
        $event = self::requestEvent(Request::create('http://127.0.0.1:8099/'));
        self::subscriber($env)->onKernelRequest($event);
        self::assertNull($event->getResponse());

        $response = self::handle(self::subscriber($env), Request::create('https://localhost/'));
        self::assertFalse($response->headers->has('Strict-Transport-Security'));
    }

    public function testProdHttpsResponsesGetHsts(): void
    {
        $html = self::handle(self::subscriber('prod'), Request::create('https://sportpartner.example/login'));
        $json = self::handle(self::subscriber('prod'), Request::create('https://sportpartner.example/notifications'), new JsonResponse([]));

        self::assertSame('max-age=31536000', $html->headers->get('Strict-Transport-Security'));
        self::assertSame('max-age=31536000', $json->headers->get('Strict-Transport-Security'));
    }

    public function testProdHttpRedirectHasNoHsts(): void
    {
        $response = self::handle(self::subscriber('prod'), Request::create('http://sportpartner.example/'));

        self::assertSame(301, $response->getStatusCode());
        self::assertFalse($response->headers->has('Strict-Transport-Security'));
    }

    public function testAuthenticatedHtmlIsNotStored(): void
    {
        $response = self::handle(self::subscriber('dev', true), Request::create('/'));

        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertFalse($response->headers->hasCacheControlDirective('public'));
    }

    public function testAnonymousHtmlKeepsDefaultCaching(): void
    {
        $response = self::handle(self::subscriber('dev', false), Request::create('/login'));

        self::assertFalse($response->headers->hasCacheControlDirective('no-store'));
    }

    public function testControllerManagedCacheIsLeftAlone(): void
    {
        $response = new Response('<html></html>', 200, ['Content-Type' => 'text/html']);
        $response->setPrivate();
        $response->setMaxAge(60);
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        $response = self::handle(self::subscriber('dev', true), Request::create('/'), $response);

        self::assertFalse($response->headers->hasCacheControlDirective('no-store'));
        self::assertSame('60', $response->headers->getCacheControlDirective('max-age'));
    }

    public function testAvatarImageKeepsItsImmutableCache(): void
    {
        // Comme AvatarController::image() avec la version courante
        $response = new Response('webp', 200, ['Content-Type' => 'image/webp', 'X-Content-Type-Options' => 'nosniff']);
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->setPrivate();
        $response->setMaxAge(31536000);
        $response->setImmutable();

        $response = self::handle(self::subscriber('dev', true), Request::create('/settings/avatar/image?v=1'), $response);

        self::assertTrue($response->headers->hasCacheControlDirective('immutable'));
        self::assertFalse($response->headers->hasCacheControlDirective('no-store'));
        self::assertFalse($response->headers->has('Content-Security-Policy'));
    }
}
