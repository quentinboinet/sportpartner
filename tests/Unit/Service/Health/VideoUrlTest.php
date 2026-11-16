<?php

namespace App\Tests\Unit\Service\Health;

use App\Service\Health\VideoUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Liens vidéo : http(s) absolu avec un hôte, sans identifiants ni espaces ; tout autre schéma est refusé. */
final class VideoUrlTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function validProvider(): iterable
    {
        yield 'vide'               => ['', null];
        yield 'espaces seuls'      => ['   ', null];
        yield 'youtube'            => ['https://www.youtube.com/watch?v=abc123&t=5', 'https://www.youtube.com/watch?v=abc123&t=5'];
        yield 'espaces autour'     => ["  https://vimeo.com/12345 \n", 'https://vimeo.com/12345'];
        yield 'http'               => ['http://example.com/video', 'http://example.com/video'];
        yield 'majuscules'         => ['HTTPS://Example.com/V', 'HTTPS://Example.com/V'];
        yield 'port et fragment'   => ['https://example.com:8443/a/b#t=10', 'https://example.com:8443/a/b#t=10'];
        yield 'hôte accentué'      => ['https://vidéo.example.fr/x', 'https://vidéo.example.fr/x'];
        yield 'guillemets (inertes dans un href échappé)' => ['https://example.com/v?a="><script>', 'https://example.com/v?a="><script>'];
    }

    #[DataProvider('validProvider')]
    public function testValidLinksAreNormalized(string $raw, ?string $expected): void
    {
        self::assertSame($expected, VideoUrl::normalize($raw));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidProvider(): iterable
    {
        yield 'javascript:'          => ['javascript:alert(1)', VideoUrl::ERROR_INVALID];
        yield 'JavaScript: casse'    => ['JaVaScRiPt:alert(1)', VideoUrl::ERROR_INVALID];
        yield 'javascript déguisé'   => ['javascript://example.com/%0Aalert(1)', VideoUrl::ERROR_INVALID];
        yield 'data:'                => ['data:text/html,<script>alert(1)</script>', VideoUrl::ERROR_INVALID];
        yield 'file:'                => ['file:///etc/passwd', VideoUrl::ERROR_INVALID];
        yield 'ftp:'                 => ['ftp://example.com/v.mp4', VideoUrl::ERROR_INVALID];
        yield 'relatif'              => ['/videos/1', VideoUrl::ERROR_INVALID];
        yield 'sans schéma'          => ['www.youtube.com/watch?v=1', VideoUrl::ERROR_INVALID];
        yield 'protocole relatif'    => ['//example.com/v', VideoUrl::ERROR_INVALID];
        yield 'sans hôte'            => ['https:///path', VideoUrl::ERROR_INVALID];
        yield 'espace interne'       => ['https://example.com/a b', VideoUrl::ERROR_INVALID];
        yield 'retour à la ligne'    => ["https://example.com/a\nb", VideoUrl::ERROR_INVALID];
        yield 'caractère de contrôle' => ["https://example.com/\x01", VideoUrl::ERROR_INVALID];
        yield 'identifiants'         => ['https://user:pass@example.com/v', VideoUrl::ERROR_INVALID];
        yield 'hôte invalide'        => ['https://exa_mple.com/v', VideoUrl::ERROR_INVALID];
        yield 'trop long'            => ['https://example.com/'.str_repeat('a', 481), VideoUrl::ERROR_TOO_LONG];
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidLinksAreRefused(string $raw, string $error): void
    {
        try {
            VideoUrl::normalize($raw);
            self::fail('accepted: '.$raw);
        } catch (\InvalidArgumentException $e) {
            self::assertSame($error, $e->getMessage());
        }
    }

    public function testExactlyFiveHundredCharactersIsAccepted(): void
    {
        $url = 'https://example.com/'.str_repeat('a', 480);
        self::assertSame(500, strlen($url));
        self::assertSame($url, VideoUrl::normalize($url));
    }
}
