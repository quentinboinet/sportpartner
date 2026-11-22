<?php

namespace App\Tests\Unit\Security;

use App\Security\ContentSecurityPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Content-Security-Policy des pages HTML : en-tête exact, nonce dans script-src seulement, aucune source qui annulerait
 * la protection des scripts, origines externes limitées et en https, nonce refusé s'il pourrait injecter l'en-tête.
 */
final class ContentSecurityPolicyTest extends TestCase
{
    private const NONCE = 'q1w2e3r4t5y6u7i8o9p0Aa==';

    public function testExactHeader(): void
    {
        self::assertSame(
            "default-src 'self'; script-src 'self' 'nonce-".self::NONCE."'; style-src 'self' 'unsafe-inline'; "
            ."img-src 'self' data: https://*.tile.openstreetmap.org; font-src 'self' data:; "
            ."connect-src 'self' https://api.open-meteo.com https://nominatim.openstreetmap.org; object-src 'none'; "
            ."base-uri 'none'; form-action 'self'; frame-ancestors 'none'; frame-src 'none'; worker-src 'none'",
            ContentSecurityPolicy::header(self::NONCE),
        );
    }

    public function testScriptsNeedSameOriginOrTheNonce(): void
    {
        $script = ContentSecurityPolicy::directives(self::NONCE)['script-src'];

        self::assertSame(["'self'", "'nonce-".self::NONCE."'"], $script);
        foreach (["'unsafe-inline'", "'unsafe-eval'", "'strict-dynamic'", 'data:', 'blob:', '*', 'https:'] as $weak) {
            self::assertNotContains($weak, $script, $weak);
        }
    }

    public function testStylesKeepUnsafeInlineWithoutNonce(): void
    {
        $style = ContentSecurityPolicy::directives(self::NONCE)['style-src'];

        self::assertContains("'unsafe-inline'", $style);
        // Un nonce dans style-src désactiverait 'unsafe-inline' (attributs style de toute l'application)
        foreach ($style as $source) {
            self::assertStringNotContainsString('nonce-', $source);
        }
    }

    public function testExternalOriginsAreHttpsAndListed(): void
    {
        $directives = ContentSecurityPolicy::directives(self::NONCE);
        $external   = [];
        foreach ($directives as $sources) {
            foreach ($sources as $source) {
                if (str_contains($source, '://')) {
                    $external[] = $source;
                    self::assertStringStartsWith('https://', $source);
                }
            }
        }

        self::assertSame(
            [...ContentSecurityPolicy::IMG_ORIGINS, ...ContentSecurityPolicy::CONNECT_ORIGINS],
            $external,
        );
        foreach (['object-src', 'base-uri', 'frame-ancestors', 'frame-src', 'worker-src'] as $none) {
            self::assertSame(["'none'"], $directives[$none], $none);
        }
        self::assertSame(["'self'"], $directives['form-action']);
        self::assertSame(["'self'"], $directives['default-src']);
        // data: : police d'icônes intégrée au CSS de FullCalendar ; aucune police externe
        self::assertSame(["'self'", 'data:'], $directives['font-src']);
    }

    public function testHexAndBase64UrlNoncesAreAccepted(): void
    {
        self::assertStringContainsString("'nonce-0123abcdef'", ContentSecurityPolicy::header('0123abcdef'));
        self::assertStringContainsString("'nonce-a_b-c'", ContentSecurityPolicy::header('a_b-c'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNonces(): iterable
    {
        yield 'empty' => [''];
        yield 'header injection' => ["abc'; script-src *"];
        yield 'new line' => ["abc\r\nX-Evil: 1"];
        yield 'space' => ['abc def'];
        yield 'too much padding' => ['abc==='];
    }

    #[DataProvider('invalidNonces')]
    public function testInvalidNonceIsRefused(string $nonce): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ContentSecurityPolicy::header($nonce);
    }
}
