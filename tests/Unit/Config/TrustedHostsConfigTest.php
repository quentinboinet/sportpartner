<?php

namespace App\Tests\Unit\Config;

use App\Env\RegexQuoteEnvVarProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\EnvVarProcessor;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Yaml;

/**
 * Hôtes de confiance (config/packages/framework.yaml) : en prod, seul l'hôte exact d'APP_URL est accepté (points
 * échappés par %env(regex_quote:...)%, motif ancré) ; en dev et en test, aucune restriction (localhost, 127.0.0.1 et
 * tous les ports des tests E2E). Le motif est résolu par un vrai conteneur, avec les processeurs de Symfony et le nôtre.
 * Vérifie aussi le cookie de session sécurisé en prod.
 */
final class TrustedHostsConfigTest extends TestCase
{
    private const APP_URL = 'https://sportpartner.alwaysdata.net';

    private mixed $previousAppUrl = null;

    /** @var array<string, mixed> */
    private static array $config;

    public static function setUpBeforeClass(): void
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 3).'/config/packages/framework.yaml');
        self::assertIsArray($config);
        self::$config = $config;
    }

    protected function setUp(): void
    {
        $this->previousAppUrl = $_ENV['APP_URL'] ?? null;
    }

    protected function tearDown(): void
    {
        Request::setTrustedHosts([]);
        if ($this->previousAppUrl === null) {
            unset($_ENV['APP_URL']);
        } else {
            $_ENV['APP_URL'] = $this->previousAppUrl;
        }
    }

    /**
     * @return list<string>
     */
    private static function prodPatterns(): array
    {
        $prod = self::$config['when@prod'] ?? null;
        self::assertIsArray($prod);
        $hosts = $prod['framework']['trusted_hosts'] ?? null;
        self::assertIsArray($hosts);

        return array_values(array_map('strval', $hosts));
    }

    /**
     * Motifs tels que le conteneur compilé les donne au noyau (kernel.trusted_hosts), pour cette valeur d'APP_URL.
     *
     * @param list<string> $patterns
     *
     * @return list<string>
     */
    private static function resolve(array $patterns, string $appUrl): array
    {
        $_ENV['APP_URL'] = $appUrl;
        $container = new ContainerBuilder();
        $container->register('env_var_processor', EnvVarProcessor::class)
            ->setArguments([new Reference('service_container')])
            ->addTag('container.env_var_processor');
        $container->register(RegexQuoteEnvVarProcessor::class, RegexQuoteEnvVarProcessor::class)
            ->addTag('container.env_var_processor');
        $container->setParameter('trusted_hosts', $patterns);
        $container->compile(true);

        $resolved = $container->getParameter('trusted_hosts');
        self::assertIsArray($resolved);

        return array_values(array_map('strval', $resolved));
    }

    private static function hostAccepted(string $host): bool
    {
        try {
            Request::create('https://'.$host.'/login')->getHost();

            return true;
        } catch (SuspiciousOperationException) {
            return false;
        }
    }

    public function testProdPatternIsTheQuotedAndAnchoredAppUrlHost(): void
    {
        self::assertSame(['^%env(regex_quote:key:host:url:APP_URL)%$'], self::prodPatterns());
        self::assertSame(['^sportpartner\.alwaysdata\.net$'], self::resolve(self::prodPatterns(), self::APP_URL));
    }

    public function testPortAndPathOfAppUrlAreIgnored(): void
    {
        self::assertSame(['^sport\-partner\.example$'], self::resolve(self::prodPatterns(), 'https://sport-partner.example:8443/app'));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function hosts(): iterable
    {
        yield 'exact host' => ['sportpartner.alwaysdata.net', true];
        yield 'exact host, other case' => ['SportPartner.AlwaysData.net', true];
        yield 'host with https port' => ['sportpartner.alwaysdata.net:443', true];
        yield 'dot used as a wildcard' => ['sportpartnerXalwaysdata.net', false];
        yield 'other dot replaced' => ['sportpartner.alwaysdataYnet', false];
        yield 'suffix' => ['sportpartner.alwaysdata.net.evil.example', false];
        yield 'prefix' => ['evilsportpartner.alwaysdata.net', false];
        yield 'subdomain' => ['www.sportpartner.alwaysdata.net', false];
        yield 'other host' => ['evil.example', false];
        yield 'localhost' => ['localhost', false];
        yield 'loopback' => ['127.0.0.1', false];
    }

    #[DataProvider('hosts')]
    public function testOnlyTheAppUrlHostIsTrustedInProd(string $host, bool $accepted): void
    {
        Request::setTrustedHosts(self::resolve(self::prodPatterns(), self::APP_URL));

        self::assertSame($accepted, self::hostAccepted($host), $host);
    }

    public function testDevAndTestTrustEveryHost(): void
    {
        self::assertArrayNotHasKey('trusted_hosts', self::$config['framework']);
        foreach (['when@dev', 'when@test'] as $env) {
            self::assertArrayNotHasKey('trusted_hosts', self::$config[$env]['framework'] ?? [], $env);
        }

        Request::setTrustedHosts([]);
        foreach (['localhost', 'localhost:8080', '127.0.0.1', '127.0.0.1:8099', '127.0.0.1:8098'] as $host) {
            self::assertTrue(self::hostAccepted($host), $host);
        }
    }

    public function testSessionCookieIsSecureInProd(): void
    {
        self::assertTrue(self::$config['when@prod']['framework']['session']['cookie_secure'] ?? null);
        self::assertSame('auto', self::$config['framework']['session']['cookie_secure'] ?? null);
    }

    public function testImportmapPolyfillIsDisabled(): void
    {
        self::assertFalse(self::$config['framework']['asset_mapper']['importmap_polyfill'] ?? null);
    }

    public function testProcessorQuotesEveryRegexCharacter(): void
    {
        $processor = new RegexQuoteEnvVarProcessor();

        self::assertSame(['regex_quote' => 'string'], RegexQuoteEnvVarProcessor::getProvidedTypes());
        self::assertSame('a\.b\-c\+d\*\(e\)', $processor->getEnv('regex_quote', 'X', static fn () => 'a.b-c+d*(e)'));
    }

    public function testProcessorRefusesAnEmptyValue(): void
    {
        $this->expectException(RuntimeException::class);

        (new RegexQuoteEnvVarProcessor())->getEnv('regex_quote', 'key:host:url:APP_URL', static fn () => '');
    }

    public function testAppUrlWithoutHostFailsAtBoot(): void
    {
        $this->expectException(\Throwable::class);

        self::resolve(self::prodPatterns(), 'not a url');
    }
}
