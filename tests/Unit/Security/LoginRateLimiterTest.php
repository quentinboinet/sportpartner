<?php

namespace App\Tests\Unit\Security;

use App\Security\LoginRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Yaml\Yaml;

/**
 * Essais de connexion : clé e-mail normalisée + IP, UN SEUL compteur (aucun compteur global par IP, qui bloquerait
 * tout le monde si l'IP vue est celle du proxy), et branchement dans security.yaml / rate_limiter.yaml.
 */
final class LoginRateLimiterTest extends TestCase
{
    private const SECRET = 'test-secret';

    private static function limiter(): LoginRateLimiter
    {
        $factory = new RateLimiterFactory(['id' => 'login', 'policy' => 'sliding_window', 'limit' => 5, 'interval' => '15 minutes'], new InMemoryStorage());

        return new LoginRateLimiter($factory, self::SECRET);
    }

    private static function attempt(string $username, ?string $ip): Request
    {
        $request = Request::create('/login', 'POST', [], [], [], null === $ip ? [] : ['REMOTE_ADDR' => $ip]);
        if (null === $ip) {
            $request->server->remove('REMOTE_ADDR');
        }
        $request->attributes->set(SecurityRequestAttributes::LAST_USERNAME, $username);

        return $request;
    }

    public function testKeyIgnoresCaseAndSurroundingSpaces(): void
    {
        $key = LoginRateLimiter::key('Alice@Example.COM', '203.0.113.5', self::SECRET);

        $this->assertSame($key, LoginRateLimiter::key('  alice@example.com ', '203.0.113.5', self::SECRET));
        $this->assertSame($key, LoginRateLimiter::key('ALICE@EXAMPLE.COM', '203.0.113.5', self::SECRET));
        $this->assertSame('éloïse@example.com', LoginRateLimiter::normalizeUsername(' ÉLOÏSE@Example.com'));
    }

    public function testKeyDependsOnUserIpAndSecret(): void
    {
        $key = LoginRateLimiter::key('alice@example.com', '203.0.113.5', self::SECRET);

        $this->assertNotSame($key, LoginRateLimiter::key('bob@example.com', '203.0.113.5', self::SECRET));
        $this->assertNotSame($key, LoginRateLimiter::key('alice@example.com', '203.0.113.6', self::SECRET));
        $this->assertNotSame($key, LoginRateLimiter::key('alice@example.com', '2001:db8::1', self::SECRET));
        $this->assertNotSame($key, LoginRateLimiter::key('alice@example.com', '203.0.113.5', 'other-secret'));
        // IP inconnue : la clé reste propre à l'adresse e-mail
        $this->assertNotSame(LoginRateLimiter::key('alice@example.com', null, self::SECRET), LoginRateLimiter::key('bob@example.com', null, self::SECRET));
    }

    public function testKeyRevealsNeitherEmailNorIpAndIsCacheSafe(): void
    {
        $key = LoginRateLimiter::key('alice@example.com', '203.0.113.5', self::SECRET);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._]{43}$/', $key);
        $this->assertStringNotContainsString('alice', $key);
        $this->assertStringNotContainsString('203', $key);
        // Octets invalides en UTF-8 : pas d'erreur
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._]{43}$/', LoginRateLimiter::key("al\xFFice", '203.0.113.5', self::SECRET));
    }

    public function testAnEmptySecretIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LoginRateLimiter(new RateLimiterFactory(['id' => 'login', 'policy' => 'no_limit'], new InMemoryStorage()), '');
    }

    public function testFiveFailuresLockOnlyThatUserFromThatIp(): void
    {
        $limiter = self::limiter();
        for ($i = 0; $i < 5; ++$i) {
            $this->assertTrue($limiter->consume(self::attempt('alice@example.com', '203.0.113.5'))->isAccepted(), 'attempt '.($i + 1));
        }

        $this->assertFalse($limiter->consume(self::attempt('Alice@Example.com', '203.0.113.5'))->isAccepted(), '6th attempt refused');
        $this->assertSame(0, $limiter->peek(self::attempt('alice@example.com', '203.0.113.5'))->getRemainingTokens());
        // Autre utilisateur, même IP (par exemple celle du proxy) : pas touché
        $this->assertSame(5, $limiter->peek(self::attempt('bob@example.com', '203.0.113.5'))->getRemainingTokens());
        $this->assertTrue($limiter->consume(self::attempt('bob@example.com', '203.0.113.5'))->isAccepted());
        // Même utilisateur depuis une autre IP : compteur distinct
        $this->assertTrue($limiter->consume(self::attempt('alice@example.com', '198.51.100.7'))->isAccepted());
    }

    public function testManyUsersFromOneIpAreNeverLockedTogether(): void
    {
        // Le limiteur par défaut de Symfony bloquerait l'IP après 5 x max_attempts essais, tous comptes confondus
        $limiter = self::limiter();
        for ($i = 0; $i < 60; ++$i) {
            $this->assertTrue($limiter->consume(self::attempt("user$i@example.com", '203.0.113.5'))->isAccepted());
        }
        $this->assertTrue($limiter->consume(self::attempt('carol@example.com', '203.0.113.5'))->isAccepted());
    }

    public function testResetAfterASuccessfulLogin(): void
    {
        $limiter = self::limiter();
        for ($i = 0; $i < 4; ++$i) {
            $limiter->consume(self::attempt('alice@example.com', '203.0.113.5'));
        }
        $limiter->reset(self::attempt('alice@example.com', '203.0.113.5'));

        $this->assertSame(5, $limiter->peek(self::attempt('alice@example.com', '203.0.113.5'))->getRemainingTokens());
    }

    public function testWithoutClientIpTheLimitIsPerUser(): void
    {
        $limiter = self::limiter();
        for ($i = 0; $i < 5; ++$i) {
            $limiter->consume(self::attempt('alice@example.com', null));
        }
        $this->assertFalse($limiter->consume(self::attempt('alice@example.com', null))->isAccepted());
        $this->assertTrue($limiter->consume(self::attempt('bob@example.com', null))->isAccepted());
    }

    public function testSecurityConfigUsesThisLimiterAndTheLoginLimiterSettings(): void
    {
        $root     = __DIR__.'/../../..';
        $security = Yaml::parseFile($root.'/config/packages/security.yaml')['security'];
        $main     = $security['firewalls']['main'];
        $this->assertSame(['limiter' => LoginRateLimiter::class], $main['login_throttling']);

        $services = Yaml::parseFile($root.'/config/services.yaml')['services'];
        $this->assertSame('@limiter.login', $services[LoginRateLimiter::class]['arguments']['$factory']);
        $this->assertSame('%kernel.secret%', $services[LoginRateLimiter::class]['arguments']['$secret']);

        $login = Yaml::parseFile($root.'/config/packages/rate_limiter.yaml')['framework']['rate_limiter']['login'];
        $this->assertSame(['policy' => 'sliding_window', 'limit' => 5, 'interval' => '15 minutes'], $login);
    }
}
