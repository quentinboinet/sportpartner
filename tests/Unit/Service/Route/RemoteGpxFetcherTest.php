<?php

namespace App\Tests\Unit\Service\Route;

use App\Service\Route\RemoteGpxException;
use App\Service\Route\RemoteGpxFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Les URL de test utilisent des IP publiques littérales (pas de résolution DNS pendant les tests).
 */
class RemoteGpxFetcherTest extends TestCase
{
    private const GPX = '<?xml version="1.0"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1"><trk><trkseg>'
        .'<trkpt lat="45.9" lon="6.13"><ele>450</ele></trkpt><trkpt lat="45.91" lon="6.14"><ele>480</ele></trkpt>'
        .'</trkseg></trk></gpx>';

    private int $calls = 0;

    /** @param list<MockResponse>|MockResponse $responses */
    private function fetcher(array|MockResponse $responses, int $maxBytes = 1_000_000): RemoteGpxFetcher
    {
        $this->calls = 0;
        $queue = is_array($responses) ? $responses : [$responses];
        $client = new MockHttpClient(function () use (&$queue) {
            ++$this->calls;

            return array_shift($queue) ?? new MockResponse('', ['http_code' => 500]);
        });

        return new RemoteGpxFetcher($client, $maxBytes);
    }

    private function assertFails(int $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail('RemoteGpxException expected');
        } catch (RemoteGpxException $e) {
            $this->assertSame($code, $e->getCode(), $e->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidUrls(): iterable
    {
        yield 'empty' => [''];
        yield 'no scheme' => ['example.org/trace.gpx'];
        yield 'ftp' => ['ftp://93.184.215.14/trace.gpx'];
        yield 'file' => ['file:///etc/passwd'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'credentials' => ['https://user:pass@93.184.215.14/trace.gpx'];
        yield 'whitespace' => ["https://93.184.215.14/tra ce.gpx"];
        yield 'control char' => ["https://93.184.215.14/\ntrace.gpx"];
        yield 'too long' => ['https://93.184.215.14/'.str_repeat('a', 2100)];
    }

    #[DataProvider('invalidUrls')]
    public function testInvalidUrlsAreRejectedWithoutRequest(string $url): void
    {
        $fetcher = $this->fetcher(new MockResponse(self::GPX));
        $this->assertFails(RemoteGpxException::INVALID_URL, fn () => $fetcher->fetch($url));
        $this->assertSame(0, $this->calls);
    }

    /** @return iterable<string, array{string}> */
    public static function privateUrls(): iterable
    {
        yield 'loopback' => ['http://127.0.0.1/trace.gpx'];
        yield 'loopback port 80' => ['http://127.0.0.1:80/trace.gpx'];
        yield 'private 10/8' => ['http://10.1.2.3/trace.gpx'];
        yield 'private 192.168' => ['http://192.168.1.10/trace.gpx'];
        yield 'private 172.16' => ['http://172.16.5.5/trace.gpx'];
        yield 'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'ipv6 loopback' => ['http://[::1]/trace.gpx'];
        yield 'unspecified' => ['http://0.0.0.0/trace.gpx'];
        // Plages absentes du réglage par défaut de NoPrivateNetworkHttpClient
        yield 'cgnat alibaba metadata' => ['http://100.100.100.200/latest/meta-data/'];
        yield 'cgnat start' => ['http://100.64.0.1/trace.gpx'];
        yield 'cgnat end' => ['http://100.127.255.254/trace.gpx'];
        yield 'benchmarking' => ['http://198.18.0.1/trace.gpx'];
        yield 'ietf protocol' => ['http://192.0.0.170/trace.gpx'];
        yield 'test-net-1' => ['http://192.0.2.1/trace.gpx'];
        yield 'test-net-2' => ['http://198.51.100.7/trace.gpx'];
        yield 'test-net-3' => ['http://203.0.113.9/trace.gpx'];
        yield 'multicast' => ['http://224.0.0.1/trace.gpx'];
        yield 'broadcast' => ['http://255.255.255.255/trace.gpx'];
        yield 'ipv6 site-local' => ['http://[fec0::1]/trace.gpx'];
        yield 'ipv6 documentation' => ['http://[2001:db8::1]/trace.gpx'];
        yield 'ipv6 discard' => ['http://[100::1]/trace.gpx'];
        yield 'ipv6 multicast' => ['http://[ff02::1]/trace.gpx'];
        yield 'ipv6 ula' => ['http://[fd00:ec2::254]/trace.gpx'];
        yield 'ipv4-mapped cgnat' => ['http://[::ffff:100.100.100.200]/trace.gpx'];
        // Nom d'hôte qui se résout vers une adresse locale
        yield 'localhost name' => ['http://localhost/trace.gpx'];
    }

    public function testPublicAddressesStillAllowed(): void
    {
        foreach (['http://93.184.215.14/a.gpx', 'http://100.63.255.254/a.gpx', 'http://100.128.0.1/a.gpx', 'http://[2a00:1450:4007::1]/a.gpx'] as $url) {
            $fetcher = $this->fetcher(new MockResponse(self::GPX));
            $this->assertSame(self::GPX, $fetcher->fetch($url)->content, $url);
        }
    }

    public function testRedirectToCgnatIsBlocked(): void
    {
        $fetcher = $this->fetcher([
            new MockResponse('', ['http_code' => 302, 'redirect_url' => 'http://100.100.100.200/latest/meta-data/']),
            new MockResponse(self::GPX),
        ]);
        $this->assertFails(RemoteGpxException::BLOCKED, fn () => $fetcher->fetch('https://93.184.215.14/trace.gpx'));
    }

    public function testUnresolvableHostIsUnreachableNotBlocked(): void
    {
        // .invalid ne se résout jamais (RFC 6761) : site injoignable, pas une adresse interne
        $fetcher = $this->fetcher(new MockResponse(self::GPX));
        $this->assertFails(RemoteGpxException::UNREACHABLE, fn () => $fetcher->fetch('http://nonexistent-host-zz9.invalid/trace.gpx'));
        $this->assertSame(0, $this->calls);
    }

    public function testRewrittenDropboxUrlLongerThanLimitIsRejected(): void
    {
        $fetcher = $this->fetcher(new MockResponse(self::GPX));
        $url = 'https://www.dropbox.com/s/abc/f.gpx?x='.str_repeat('é', 1000);
        $this->assertLessThanOrEqual(RemoteGpxFetcher::MAX_URL_LENGTH, strlen($url));

        $this->assertFails(RemoteGpxException::INVALID_URL, fn () => $fetcher->normalizeUrl($url));
        $this->assertFails(RemoteGpxException::INVALID_URL, fn () => $fetcher->fetch($url));
        $this->assertSame(0, $this->calls);
    }

    #[DataProvider('privateUrls')]
    public function testPrivateNetworkIsBlocked(string $url): void
    {
        $fetcher = $this->fetcher(new MockResponse(self::GPX));
        $this->assertFails(RemoteGpxException::BLOCKED, fn () => $fetcher->fetch($url));
    }

    public function testDropboxShareLinkIsRewrittenToDirectDownload(): void
    {
        $fetcher = $this->fetcher([]);
        $this->assertSame(
            'https://www.dropbox.com/scl/fi/abc/trace.gpx?rlkey=xyz&dl=1',
            $fetcher->normalizeUrl('https://www.dropbox.com/scl/fi/abc/trace.gpx?rlkey=xyz&dl=0'),
        );
    }

    public function testGoogleDriveShareLinkIsRewrittenToDirectDownload(): void
    {
        $fetcher = $this->fetcher([]);
        $this->assertSame(
            'https://drive.google.com/uc?export=download&id=1AbC_d-9',
            $fetcher->normalizeUrl('https://drive.google.com/file/d/1AbC_d-9/view?usp=sharing'),
        );
    }

    public function testOtherUrlsAreKept(): void
    {
        $fetcher = $this->fetcher([]);
        $this->assertSame('https://93.184.215.14/a/b.gpx?x=1', $fetcher->normalizeUrl('  https://93.184.215.14/a/b.gpx?x=1 '));
    }

    public function testFetchReturnsContentAndFileNameFromUrl(): void
    {
        $fetcher = $this->fetcher(new MockResponse(self::GPX, ['response_headers' => ['content-type' => 'application/gpx+xml']]));
        $gpx = $fetcher->fetch('https://93.184.215.14/traces/Trail%20du%20Semnoz.gpx');

        $this->assertSame(self::GPX, $gpx->content);
        $this->assertSame('Trail du Semnoz.gpx', $gpx->fileName);
        $this->assertSame('https://93.184.215.14/traces/Trail%20du%20Semnoz.gpx', $gpx->sourceUrl);
    }

    public function testFileNameFromContentDisposition(): void
    {
        $fetcher = $this->fetcher(new MockResponse(self::GPX, ['response_headers' => [
            'content-disposition' => 'attachment; filename="utmb-2027.gpx"',
        ]]));

        $this->assertSame('utmb-2027.gpx', $fetcher->fetch('https://93.184.215.14/download?id=42')->fileName);
    }

    public function testFallbackFileName(): void
    {
        $fetcher = $this->fetcher(new MockResponse(self::GPX));

        $this->assertSame('parcours.gpx', $fetcher->fetch('https://93.184.215.14/download?id=42')->fileName);
    }

    public function testHttpErrorStatus(): void
    {
        $fetcher = $this->fetcher(new MockResponse('Not found', ['http_code' => 404]));
        $this->assertFails(RemoteGpxException::HTTP_ERROR, fn () => $fetcher->fetch('https://93.184.215.14/missing.gpx'));
    }

    public function testHtmlPageIsNotAGpx(): void
    {
        $html = "\xEF\xBB\xBF  <!DOCTYPE html><html><head><title>Trail</title></head><body>Télécharger le GPX</body></html>";
        $fetcher = $this->fetcher(new MockResponse($html, ['response_headers' => ['content-type' => 'text/html']]));
        $this->assertFails(RemoteGpxException::NOT_GPX, fn () => $fetcher->fetch('https://93.184.215.14/course'));
    }

    public function testTooLargeByContentLength(): void
    {
        $body = str_repeat('x', 5000);
        $fetcher = $this->fetcher(new MockResponse($body, ['response_headers' => ['content-length' => (string) strlen($body)]]), 1000);
        $this->assertFails(RemoteGpxException::TOO_LARGE, fn () => $fetcher->fetch('https://93.184.215.14/big.gpx'));
    }

    public function testTooLargeWithoutContentLengthIsCutWhileStreaming(): void
    {
        // Corps envoyé en morceaux, sans Content-Length fiable
        $chunks = (function () {
            for ($i = 0; $i < 50; ++$i) {
                yield str_repeat('x', 100);
            }
        })();
        $fetcher = $this->fetcher(new MockResponse($chunks), 1000);
        $this->assertFails(RemoteGpxException::TOO_LARGE, fn () => $fetcher->fetch('https://93.184.215.14/stream.gpx'));
    }

    /** @return iterable<string, array{string}> */
    public static function nonStandardPorts(): iterable
    {
        yield 'http 8080' => ['http://93.184.215.14:8080/trace.gpx'];
        yield 'https 8443' => ['https://93.184.215.14:8443/trace.gpx'];
        yield 'ssh' => ['http://93.184.215.14:22/'];
        yield 'mysql' => ['http://93.184.215.14:3306/'];
        yield 'redis on loopback' => ['http://127.0.0.1:6379/'];
        yield 'port 0' => ['http://93.184.215.14:0/trace.gpx'];
        yield 'dropbox with port' => ['https://www.dropbox.com:8443/s/abc/trace.gpx?dl=0'];
    }

    #[DataProvider('nonStandardPorts')]
    public function testNonStandardPortsAreRefusedWithoutRequest(string $url): void
    {
        $fetcher = $this->fetcher(new MockResponse(self::GPX));
        try {
            $fetcher->fetch($url);
            $this->fail('RemoteGpxException expected');
        } catch (RemoteGpxException $e) {
            // parse_url() refuse lui-même le port 0 (lien invalide) : refusé aussi, sans requête
            $this->assertContains($e->getCode(), [RemoteGpxException::PORT_NOT_ALLOWED, RemoteGpxException::INVALID_URL], $e->getMessage());
        }
        $this->assertSame(0, $this->calls);
    }

    public function testPortsOfTheSchemesAreAccepted(): void
    {
        foreach (['http://93.184.215.14:80/a.gpx', 'https://93.184.215.14:443/a.gpx', 'http://93.184.215.14:443/a.gpx', 'https://93.184.215.14/a.gpx'] as $url) {
            $fetcher = $this->fetcher(new MockResponse(self::GPX));
            $this->assertSame(self::GPX, $fetcher->fetch($url)->content, $url);
            $this->assertSame(1, $this->calls, $url);
        }
    }

    public function testRedirectIsFollowedAndChecked(): void
    {
        $fetcher = $this->fetcher([
            new MockResponse('', ['http_code' => 302, 'redirect_url' => 'https://93.184.215.15/files/real.gpx']),
            new MockResponse(self::GPX),
        ]);
        $gpx = $fetcher->fetch('https://93.184.215.14/share/abc');
        $this->assertSame(self::GPX, $gpx->content);
        $this->assertSame(2, $this->calls);
    }

    public function testRedirectToNonStandardPortIsRefused(): void
    {
        $fetcher = $this->fetcher([
            new MockResponse('', ['http_code' => 301, 'redirect_url' => 'http://93.184.215.15:8080/admin']),
            new MockResponse(self::GPX),
        ]);
        $this->assertFails(RemoteGpxException::PORT_NOT_ALLOWED, fn () => $fetcher->fetch('https://93.184.215.14/trace.gpx'));
        $this->assertSame(1, $this->calls, 'the redirect target is never requested');
    }

    public function testRedirectToOtherSchemeOrCredentialsIsRefused(): void
    {
        foreach (['ftp://93.184.215.15/trace.gpx', 'https://user:pass@93.184.215.15/trace.gpx'] as $target) {
            $fetcher = $this->fetcher([
                new MockResponse('', ['http_code' => 302, 'redirect_url' => $target]),
                new MockResponse(self::GPX),
            ]);
            $this->assertFails(RemoteGpxException::INVALID_URL, fn () => $fetcher->fetch('https://93.184.215.14/trace.gpx'));
            $this->assertSame(1, $this->calls, $target);
        }
    }

    public function testTooManyRedirectsIsAnHttpError(): void
    {
        $loop = [];
        for ($i = 0; $i < 6; ++$i) {
            $loop[] = new MockResponse('', ['http_code' => 302, 'redirect_url' => 'https://93.184.215.14/hop'.$i]);
        }
        $fetcher = $this->fetcher($loop);
        $this->assertFails(RemoteGpxException::HTTP_ERROR, fn () => $fetcher->fetch('https://93.184.215.14/trace.gpx'));
        $this->assertSame(4, $this->calls, 'first request + 3 redirections');
    }

    public function testRedirectWithoutLocationIsAnHttpError(): void
    {
        $fetcher = $this->fetcher(new MockResponse('', ['http_code' => 302]));
        $this->assertFails(RemoteGpxException::HTTP_ERROR, fn () => $fetcher->fetch('https://93.184.215.14/trace.gpx'));
    }

    public function testNetworkErrorIsUnreachable(): void
    {
        $fetcher = $this->fetcher(new MockResponse('', ['error' => 'Could not resolve host']));
        $this->assertFails(RemoteGpxException::UNREACHABLE, fn () => $fetcher->fetch('https://93.184.215.14/trace.gpx'));
    }
}
