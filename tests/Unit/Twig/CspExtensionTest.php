<?php

namespace App\Tests\Unit\Twig;

use App\EventSubscriber\SecurityHeadersSubscriber;
use App\Twig\CspExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * csp_nonce() : nonce de la requête PRINCIPALE, identique à celui de l'en-tête Content-Security-Policy (y compris
 * pendant une sous-requête), stable pendant la requête, vide hors requête HTTP.
 */
final class CspExtensionTest extends TestCase
{
    private static function kernel(): HttpKernelInterface
    {
        return new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };
    }

    public function testFunctionIsRegistered(): void
    {
        $names = array_map(static fn ($f) => $f->getName(), (new CspExtension(new RequestStack()))->getFunctions());

        self::assertSame(['csp_nonce'], $names);
    }

    public function testEmptyWithoutRequest(): void
    {
        self::assertSame('', (new CspExtension(new RequestStack()))->nonce());
    }

    public function testRenderedNonceMatchesTheCspHeader(): void
    {
        $stack      = new RequestStack();
        $request    = Request::create('/');
        $subscriber = new SecurityHeadersSubscriber('dev');
        $stack->push($request);
        $subscriber->onKernelRequest(new RequestEvent(self::kernel(), $request, HttpKernelInterface::MAIN_REQUEST));

        $twig = new Environment(new ArrayLoader([
            'page' => '<script nonce="{{ csp_nonce() }}">ok()</script><script nonce="{{ csp_nonce() }}">ok()</script>',
        ]));
        $twig->addExtension(new CspExtension($stack));
        $html = $twig->render('page');

        self::assertSame(1, preg_match('/nonce="([^"]+)"/', $html, $m));
        self::assertSame(2, substr_count($html, 'nonce="'.$m[1].'"'), 'même nonce pour tous les scripts de la page');

        $response = new Response($html, 200, ['Content-Type' => 'text/html']);
        $subscriber->onKernelResponse(new ResponseEvent(self::kernel(), $request, HttpKernelInterface::MAIN_REQUEST, $response));
        self::assertStringContainsString("'nonce-".$m[1]."'", (string) $response->headers->get('Content-Security-Policy'));
    }

    public function testSubRequestUsesTheMainRequestNonce(): void
    {
        $stack = new RequestStack();
        $main  = Request::create('/');
        $stack->push($main);
        $mainNonce = SecurityHeadersSubscriber::nonce($main);
        $stack->push(Request::create('/_fragment'));

        self::assertSame($mainNonce, (new CspExtension($stack))->nonce());
    }

    public function testNonceIsCreatedLazilyAndStable(): void
    {
        $stack   = new RequestStack();
        $request = Request::create('/');
        $stack->push($request);
        $extension = new CspExtension($stack);

        $nonce = $extension->nonce();
        self::assertNotSame('', $nonce);
        self::assertSame($nonce, $extension->nonce());
        self::assertSame($nonce, $request->attributes->get(SecurityHeadersSubscriber::NONCE_ATTRIBUTE));
    }
}
